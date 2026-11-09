<?php

class Heartbeat
{
    public const MAX_ATTEMPTS = 3;
    public const STALE_SECONDS = 300;

    public function __construct(private Database $db)
    {
    }

    public function beat(): void
    {
        $this->reconcile();
        foreach ($this->db->dueJobs(self::STALE_SECONDS, self::MAX_ATTEMPTS) as $job) {
            $id = (int)$job['id'];
            $attempt = (int)($job['attempts'] ?? 1);
            $this->traceJob($job, 'Due job ' . $id . ' · ' . $job['kind'] . ' · attempt ' . $attempt);
            if ((string)$job['kind'] === 'subagent' && $this->db->lockFresh('subagent_lock', 900)) {
                $this->traceJob($job, 'Skipped job ' . $id . ' · subagent lock held');
                continue;
            }
            if ((string)$job['kind'] === 'task' && $this->closeFinishedOccurrence($job)) {
                continue;
            }
            $this->db->bumpJob($id);
            $this->traceJob($job, 'Bumped job ' . $id . ' · attempt ' . ($attempt + 1));
            $this->traceJob($job, 'Retry started · job ' . $id);
            try {
                if ($this->retry($job)) {
                    $this->db->finishJob($id);
                    $this->traceJob($job, 'Retry finished · job ' . $id);
                }
            } catch (ChatStopped $e) {
                $this->db->finishJob($id);
                $this->traceJob($job, 'Stopped by user · job ' . $id);
            } catch (Throwable $e) {
                $this->db->failJob($id, $e->getMessage(), self::MAX_ATTEMPTS);
                $projectId = $job['project_id'] !== null ? (int)$job['project_id'] : null;
                $this->db->logAgent('orchestrator', $projectId, 'error', 'Heartbeat retry failed: ' . $e->getMessage());
                $current = $this->db->job($id);
                $status = (string)($current['status'] ?? '');
                $reason = $e->getMessage();
                if (str_contains($reason, 'empty answer')) {
                    $line = 'Empty answer · job ' . $id . ($status === 'gave_up' ? ' · give up' : ' · heartbeat may retry');
                } elseif ($status === 'gave_up') {
                    $line = 'Gave up · job ' . $id . ' · ' . $reason;
                } else {
                    $line = 'Failed · job ' . $id . ' · attempt ' . (int)($current['attempts'] ?? 0) . ' · heartbeat may retry · ' . $reason;
                }
                $this->traceJob($job, $line);
                $this->gaveUpTask($job);
            }
        }
    }

    public function reconcile(): array
    {
        $fresh = $this->db->freshJobs(self::STALE_SECONDS);
        $stored = $this->db->agentStatus();
        $minutes = (int)max(1, round(self::STALE_SECONDS / 60));
        $note = 'No job has reported in for ' . $minutes . ' minutes. Marked stuck.';
        $out = [];
        foreach (array_keys(Database::idleStatus()) as $name) {
            $job = $this->liveJob($name, $fresh);
            $status = (string)($stored[$name] ?? 'idle');
            $projectId = null;
            if ($job !== null) {
                $projectId = $job['project_id'];
            } elseif ($status === 'thinking' || $status === 'executing_tool') {
                $stuckProject = $this->db->latestJobProject($name);
                $this->db->setAgentStatus($name, 'stuck');
                $this->db->logAgent($name, $stuckProject, 'error', $note);
                $this->db->loopWrite(null, $stuckProject, $name, 'process', 'Marked stuck · no report for ' . $minutes . ' minutes');
                $status = 'stuck';
            }
            $out[$name] = [
                'status' => $status,
                'project_id' => $projectId,
            ];
        }
        return $out;
    }

    private function liveJob(string $name, array $fresh): ?array
    {
        if ($name === 'consultant') {
            if (!$this->db->lockFresh('consultant_lock', self::STALE_SECONDS)) {
                return null;
            }
            foreach ($fresh as $job) {
                if (in_array((string)$job['kind'], ['chat', 'task'], true)) {
                    return $job;
                }
            }
            return ['project_id' => null];
        }
        foreach ($fresh as $job) {
            $kind = (string)$job['kind'];
            if ($name === 'orchestrator' && ($kind === 'chat' || $kind === 'task')) {
                return $job;
            }
            if ($kind === 'subagent' && (string)($job['payload']['agent'] ?? '') === $name) {
                return $job;
            }
        }
        return null;
    }

    public function runTask(array $task, ?int $jobId = null): void
    {
        $projectId = (int)($task['project_id'] ?? 0);
        if ($projectId <= 0) {
            return;
        }
        $taskId = (int)$task['id'];
        $state = $this->slotState($task);
        if ($state === 'later') {
            return;
        }
        if ($state === 'ran') {
            $this->db->loopWrite($jobId, $projectId, 'heartbeat', 'process', 'Task ' . $taskId . ' already finished · not started again');
            $this->settleIfDue($task);
            return;
        }
        $ownJob = $jobId === null;
        if (!$ownJob && $this->db->scheduledAnswerSaved($projectId, $taskId, (string)($task['title'] ?? ''))) {
            $this->db->loopWrite($jobId, $projectId, 'heartbeat', 'process', 'Task ' . $taskId . ' already answered · job ' . $jobId . ' closed');
            $this->settleIfDue($task);
            return;
        }
        if ($ownJob) {
            Recovery::reset($this->db);
            $jobId = $this->db->startJob('task', $projectId, [
                'task_id' => (int)$task['id'],
            ], (int)$task['id']);
            $this->db->updateTask((int)$task['id'], ['status' => 'in_progress']);
            $message = 'Scheduled task: #' . (int)$task['id'] . ' ' . $task['title'] . "\n\n" . (string)($task['description'] ?? '');
            $this->db->addMessage($projectId, 'user', $message, 'task');
        } else {
            $this->db->deleteLatestFailed($projectId);
        }
        $this->db->loopPush($jobId, $projectId);
        try {
            if ($ownJob) {
                $this->db->loop('orchestrator', 'process', 'Task ' . (int)$task['id'] . ' started · job ' . $jobId . ' · in progress');
                $this->db->loop('orchestrator', 'process', 'Recovery counters reset');
            } else {
                $this->db->loop('orchestrator', 'process', 'Heartbeat retry · task ' . (int)$task['id'] . ' · job ' . $jobId);
            }
            $saved = false;
            $turn = new ChatTurn();
            try {
                $answer = $turn->run($projectId, static function (): void {
                }, function () use ($jobId): bool {
                    $this->db->touchJob($jobId);
                    return false;
                });
                $detail = ChatTurn::encodeDetail($turn->detail);
                if (trim($answer) === '' && $detail === null) {
                    throw new RuntimeException('The model returned an empty answer.');
                }
                $spoken = $answer !== '' ? $answer : 'Finished the scheduled task.';
                $this->db->addMessage($projectId, 'assistant', $spoken, 'orchestrator', false, $detail);
                $this->db->loop('orchestrator', 'process', 'Answer saved · job ' . $jobId . ' finished');
                $this->db->finishJob($jobId);
                $saved = true;
                $this->settle($task, $this->withTaskNotes($spoken, $turn->notices), false, $turn->notices !== []);
            } catch (Throwable $e) {
                if ($e instanceof ChatStopped) {
                    $this->db->loop('orchestrator', 'process', 'Stopped · job ' . $jobId);
                    $this->db->finishJob($jobId);
                    return;
                }
                if ($saved) {
                    $this->db->loop('heartbeat', 'process', 'Answer saved · schedule not moved · job ' . $jobId . ' · ' . $e->getMessage());
                    return;
                }
                $this->db->failJob($jobId, $e->getMessage(), self::MAX_ATTEMPTS);
                $this->db->logAgent('orchestrator', $projectId, 'error', $e->getMessage());
                $this->db->setAgentStatus('orchestrator', 'error');
                $current = $this->db->job($jobId);
                $reason = $e->getMessage();
                $status = (string)($current['status'] ?? '');
                if (str_contains($reason, 'empty answer')) {
                    $line = 'Empty answer · job ' . $jobId;
                } elseif ($status === 'gave_up') {
                    $line = 'Gave up · job ' . $jobId . ' · ' . $reason;
                } else {
                    $line = 'Failed · job ' . $jobId . ' · ' . $reason;
                }
                $this->db->loop('orchestrator', 'process', $line);
                if ($ownJob && $status === 'gave_up') {
                    $this->settle($task, $this->withTaskNotes((string)($current['last_error'] ?? $e->getMessage()), $turn->notices), true);
                }
                if (!$ownJob) {
                    throw $e;
                }
            }
        } finally {
            $this->db->loopPop();
        }
    }

    private function retry(array $job): bool
    {
        $kind = (string)$job['kind'];
        $projectId = $job['project_id'] !== null ? (int)$job['project_id'] : 0;
        $payload = json_decode((string)($job['payload'] ?? ''), true);
        if (!is_array($payload)) {
            $payload = [];
        }
        $id = (int)$job['id'];
        if ($kind === 'task') {
            $task = $this->db->task((int)($job['ref_id'] ?? $payload['task_id'] ?? 0));
            if (!$task) {
                $this->traceJob($job, 'Task missing · job ' . $id . ' closed');
                return true;
            }
            $this->runTask($task, $id);
            return true;
        }
        if ($kind === 'subagent') {
            $this->db->loopPush($id, $projectId > 0 ? $projectId : null);
            try {
                if ($this->db->lockFresh('subagent_lock', 900)) {
                    $this->db->loop('heartbeat', 'process', 'Skipped · subagent lock held · job ' . $id);
                    return false;
                }
                $agent = (string)($payload['agent'] ?? '');
                $folder = (string)($payload['folder'] ?? '');
                $instruction = (string)($payload['instruction'] ?? '');
                $this->db->loop($agent !== '' ? $agent : 'subagent', 'process', 'Subagent retry · job ' . $id . ($agent !== '' ? ' · ' . $agent : ''));
                $result = app_services()['subagents']->run($agent, $folder, $instruction, static function (): void {
                }, $projectId, function () use ($id): bool {
                    $this->db->touchJob($id);
                    return false;
                }, !empty($payload['scheduled']));
                if (trim($result) !== '') {
                    $this->db->addMessage($projectId, 'assistant', $result, $agent);
                }
                $this->db->loop($agent !== '' ? $agent : 'subagent', 'process', 'Subagent retry finished · job ' . $id);
                return true;
            } finally {
                $this->db->loopPop();
            }
        }
        $this->db->loopPush($id, $projectId > 0 ? $projectId : null);
        try {
            $this->db->deleteLatestFailed($projectId);
            $this->db->loop('orchestrator', 'process', 'Chat retry · job ' . $id);
            $turn = new ChatTurn();
            try {
                $answer = $turn->run($projectId, static function (): void {
                }, function () use ($id): bool {
                    $this->db->touchJob($id);
                    return false;
                });
            } catch (Throwable $e) {
                if (!($e instanceof ChatStopped)) {
                    $error = error_text($e->getMessage());
                    $text = trim($turn->streamed);
                    $text = $text !== '' ? $text . "\n\n" . $error : $error;
                    $this->db->addMessage($projectId, 'assistant', $text, 'orchestrator', true, ChatTurn::encodeDetail($turn->detail));
                }
                throw $e;
            }
            $detail = ChatTurn::encodeDetail($turn->detail);
            if (trim($answer) === '' && $detail === null) {
                throw new RuntimeException('The model returned an empty answer.');
            }
            $this->db->addMessage($projectId, 'assistant', $answer, 'orchestrator', false, $detail);
            $this->db->loop('orchestrator', 'process', 'Answer saved · job ' . $id . ' finished');
            return true;
        } finally {
            $this->db->loopPop();
        }
    }

    private function closeFinishedOccurrence(array $job): bool
    {
        $task = $this->taskFromJob($job);
        if (!$task) {
            return false;
        }
        $state = $this->slotState($task);
        $id = (int)$job['id'];
        $taskId = (int)$task['id'];
        if ($state === 'later') {
            $this->traceJob($job, 'Task ' . $taskId . ' already scheduled · ' . $task['schedule_time'] . ' · job ' . $id . ' closed');
            $this->db->finishJob($id);
            return true;
        }
        $projectId = (int)($task['project_id'] ?? 0);
        $answered = $state === 'ran' || $this->db->scheduledAnswerSaved($projectId, $taskId, (string)($task['title'] ?? ''));
        if (!$answered) {
            return false;
        }
        $this->traceJob($job, 'Task ' . $taskId . ' already finished · job ' . $id . ' closed');
        $this->db->finishJob($id);
        $this->settleIfDue($task);
        return true;
    }

    private function taskFromJob(array $job): ?array
    {
        $taskId = (int)($job['ref_id'] ?? 0);
        if ($taskId <= 0) {
            $payload = $job['payload'] ?? null;
            if (is_string($payload)) {
                $payload = json_decode($payload, true);
            }
            $taskId = is_array($payload) ? (int)($payload['task_id'] ?? 0) : 0;
        }
        return $taskId > 0 ? $this->db->task($taskId) : null;
    }

    private function slotState(array $task): string
    {
        $when = trim((string)($task['schedule_time'] ?? ''));
        if ($when !== '') {
            $at = strtotime($when);
            if ($at !== false && $at > time()) {
                return 'later';
            }
            if ($at !== false && $this->db->taskRanSince((int)$task['id'], $when)) {
                return 'ran';
            }
            return 'due';
        }
        if ($this->repeats((string)($task['recurrence_interval'] ?? 'none')) && $this->db->taskRanSince((int)$task['id'], null)) {
            return 'ran';
        }
        return 'due';
    }

    private function settleIfDue(array $task): void
    {
        $when = trim((string)($task['schedule_time'] ?? ''));
        $at = $when !== '' ? strtotime($when) : false;
        if ($when !== '' && $at !== false && $at > time()) {
            return;
        }
        $this->settle($task, (string)($task['result'] ?? ''), false);
    }

    private function repeats(string $recurrence): bool
    {
        return $recurrence !== '' && $recurrence !== 'none';
    }

    private function gaveUpTask(array $job): void
    {
        if ((string)$job['kind'] !== 'task') {
            return;
        }
        $current = $this->db->job((int)$job['id']);
        if (($current['status'] ?? '') !== 'gave_up') {
            return;
        }
        $taskId = (int)($job['ref_id'] ?? 0);
        $task = $taskId > 0 ? $this->db->task($taskId) : null;
        if ($task) {
            $this->settle($task, (string)($current['last_error'] ?? 'The scheduled task stopped.'), true);
        }
    }

    private function withTaskNotes(string $result, array $notes): string
    {
        $lines = [];
        foreach ($notes as $note) {
            $note = trim((string)$note);
            if ($note === '' || in_array($note, $lines, true)) {
                continue;
            }
            $lines[] = $note;
        }
        if (!$lines) {
            return $result;
        }
        return trim(implode("\n", $lines) . ($result !== '' ? "\n\n" . $result : ''));
    }

    private function settle(array $task, string $result, bool $failed = false, bool $noted = false): void
    {
        $board = new Kanban($this->db);
        $next = $this->nextSchedule((string)($task['recurrence_interval'] ?? 'none'), (string)($task['schedule_time'] ?? ''));
        $projectId = (int)($task['project_id'] ?? 0);
        if ($next) {
            $line = 'Task ' . (int)$task['id'] . ' rescheduled · ' . $next;
            if ($failed || $noted) {
                $line .= ' · result noted';
            }
            $this->db->loopWrite(null, $projectId > 0 ? $projectId : null, 'orchestrator', 'process', $line);
            $fields = ['status' => 'in_progress', 'schedule_time' => $next];
            if ($failed || $noted) {
                $fields['result'] = $result;
            }
            $board->move((int)$task['id'], $fields, false);
            return;
        }
        $line = 'Task ' . (int)$task['id'] . ' settled to review';
        if ($failed || $noted) {
            $line .= ' · result noted';
        }
        $this->db->loopWrite(null, $projectId > 0 ? $projectId : null, 'orchestrator', 'process', $line);
        $board->move((int)$task['id'], [
            'status' => 'review',
            'result' => $result,
        ], false);
    }

    private function traceJob(array $job, string $line): void
    {
        $projectId = $job['project_id'] !== null ? (int)$job['project_id'] : null;
        $kind = (string)($job['kind'] ?? '');
        $agent = 'orchestrator';
        if ($kind === 'subagent') {
            $payload = $job['payload'] ?? null;
            if (is_string($payload)) {
                $payload = json_decode($payload, true);
            }
            $name = is_array($payload) ? trim((string)($payload['agent'] ?? '')) : '';
            $agent = $name !== '' ? $name : 'subagent';
        }
        $jobId = isset($job['id']) ? (int)$job['id'] : null;
        $this->db->loopWrite($jobId, $projectId, $agent, 'process', $line);
    }

    private function nextSchedule(string $interval, string $from): ?string
    {
        $seconds = [
            '5min' => 300,
            '15min' => 900,
            '30min' => 1800,
            'hourly' => 3600,
            'daily' => 86400,
            'weekly' => 604800,
        ];
        $base = strtotime($from) ?: time();
        $now = time();
        if (isset($seconds[$interval])) {
            $step = $seconds[$interval];
            if ($base <= $now) {
                $base += (int)ceil(($now - $base + 1) / $step) * $step;
            } else {
                $base += $step;
            }
            return date('Y-m-d H:i:s', $base);
        }
        if ($interval !== 'monthly') {
            return null;
        }
        $guard = 0;
        do {
            $next = strtotime('+1 month', $base);
            if ($next === false || $next <= $base) {
                return null;
            }
            $base = $next;
            $guard++;
        } while ($base <= $now && $guard < 240);
        return $base > $now ? date('Y-m-d H:i:s', $base) : null;
    }
}
