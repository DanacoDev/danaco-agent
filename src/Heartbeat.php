<?php

class Heartbeat
{
    public const MAX_ATTEMPTS = 3;
    public const STALE_SECONDS = 180;

    public function __construct(private Database $db)
    {
    }

    public function beat(): void
    {
        $this->reconcile();
        foreach ($this->db->dueJobs(self::STALE_SECONDS, self::MAX_ATTEMPTS) as $job) {
            $id = (int)$job['id'];
            $this->db->bumpJob($id);
            try {
                $this->retry($job);
                $this->db->finishJob($id);
            } catch (ChatStopped $e) {
                $this->db->finishJob($id);
            } catch (Throwable $e) {
                $this->db->failJob($id, $e->getMessage(), self::MAX_ATTEMPTS);
                $projectId = $job['project_id'] !== null ? (int)$job['project_id'] : null;
                $this->db->logAgent('orchestrator', $projectId, 'error', 'Heartbeat retry failed: ' . $e->getMessage());
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
                $this->db->setAgentStatus($name, 'stuck');
                $this->db->logAgent($name, $this->db->latestJobProject($name), 'error', $note);
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
        $ownJob = $jobId === null;
        if ($ownJob) {
            Recovery::reset($this->db);
            $jobId = $this->db->startJob('task', $projectId, [
                'task_id' => (int)$task['id'],
            ], (int)$task['id']);
            $this->db->updateTask((int)$task['id'], ['status' => 'in_progress']);
            $message = "Scheduled task: {$task['title']}\n\n" . (string)($task['description'] ?? '');
            $this->db->addMessage($projectId, 'user', $message, 'task');
        } else {
            $this->db->deleteLatestFailed($projectId);
        }
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
            $this->db->addMessage($projectId, 'assistant', $answer !== '' ? $answer : 'Finished the scheduled task.', 'orchestrator', false, $detail);
            $this->db->finishJob($jobId);
            $this->settle($task, $answer !== '' ? $answer : 'Finished the scheduled task.');
        } catch (Throwable $e) {
            if ($e instanceof ChatStopped) {
                $this->db->finishJob($jobId);
                return;
            }
            $this->db->failJob($jobId, $e->getMessage(), self::MAX_ATTEMPTS);
            $this->db->logAgent('orchestrator', $projectId, 'error', $e->getMessage());
            $this->db->setAgentStatus('orchestrator', 'error');
            $current = $this->db->job($jobId);
            if ($ownJob && ($current['status'] ?? '') === 'gave_up') {
                $this->settle($task, (string)($current['last_error'] ?? $e->getMessage()), true);
            }
            if (!$ownJob) {
                throw $e;
            }
        }
    }

    private function retry(array $job): void
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
                return;
            }
            $this->runTask($task, $id);
            return;
        }
        if ($kind === 'subagent') {
            $agent = (string)($payload['agent'] ?? '');
            $folder = (string)($payload['folder'] ?? '');
            $instruction = (string)($payload['instruction'] ?? '');
            $result = app_services()['subagents']->run($agent, $folder, $instruction, static function (): void {
            }, $projectId, function () use ($id): bool {
                $this->db->touchJob($id);
                return false;
            });
            if (trim($result) !== '') {
                $this->db->addMessage($projectId, 'assistant', $result, $agent);
            }
            return;
        }
        $this->db->deleteLatestFailed($projectId);
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

    private function settle(array $task, string $result, bool $failed = false): void
    {
        $board = new Kanban($this->db);
        $next = $this->nextSchedule((string)($task['recurrence_interval'] ?? 'none'), (string)($task['schedule_time'] ?? ''));
        if ($next) {
            $fields = ['status' => 'in_progress', 'schedule_time' => $next];
            if ($failed) {
                $fields['result'] = $result;
            }
            $board->move((int)$task['id'], $fields, false);
            return;
        }
        $board->move((int)$task['id'], [
            'status' => 'review',
            'result' => $result,
        ], false);
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
