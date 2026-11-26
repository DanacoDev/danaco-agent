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
            $projectId = $job['project_id'] !== null ? (int)$job['project_id'] : 0;
            if ($this->db->runStopRequested($projectId)) {
                $this->db->closeStoppedJob($job);
                $this->traceJob($job, 'Stopped by user · job ' . $id . ' not retried');
                continue;
            }
            if ($projectId > 0 && $this->db->heartbeatPaused($projectId)) {
                $this->traceJob($job, 'Skipped job ' . $id . ' · heartbeat paused');
                continue;
            }
            $attempt = (int)($job['attempts'] ?? 1);
            $this->traceJob($job, 'Due job ' . $id . ' · ' . $job['kind'] . ' · attempt ' . $attempt);
            if ((string)$job['kind'] === 'subagent' && $this->db->lockFresh('subagent_lock', 900)) {
                $this->traceJob($job, 'Skipped job ' . $id . ' · subagent lock held');
                continue;
            }
            if ((string)$job['kind'] === 'chat' && $this->db->lockFresh('subagent_lock', 900)) {
                $this->traceJob($job, 'Skipped job ' . $id . ' · subagent still working');
                continue;
            }
            if ((string)$job['kind'] === 'task') {
                if ($this->closeFinishedOccurrence($job)) {
                    continue;
                }
                if ($this->taskSlotTaken()) {
                    $this->traceJob($job, 'Skipped job ' . $id . ' · task still running');
                    continue;
                }
                $task = $this->taskFromJob($job);
                if (!$task) {
                    $this->traceJob($job, 'Task missing · job ' . $id . ' closed');
                    $this->db->finishJob($id);
                    continue;
                }
                $this->db->bumpJob($id);
                $this->traceJob($job, 'Bumped job ' . $id . ' · attempt ' . ($attempt + 1));
                $this->traceJob($job, 'Retry started · job ' . $id . ' · background');
                $this->launchTask($task, $id);
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
                $this->db->closeStoppedJob($job);
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
                $stopProject = $stuckProject !== null ? (int)$stuckProject : 0;
                $age = $this->db->runStopAge($stopProject);
                if ($age !== null && $age < self::STALE_SECONDS) {
                    $projectId = $stuckProject;
                } else {
                    if ($age !== null) {
                        $this->db->clearRunStop($stopProject);
                    }
                    if ($this->db->lockFresh('subagent_lock', 900)) {
                        $projectId = $stuckProject;
                        $out[$name] = [
                            'status' => $status,
                            'project_id' => $projectId,
                        ];
                        continue;
                    }
                    $this->db->setAgentStatus($name, 'stuck');
                    $this->db->logAgent($name, $stuckProject, 'error', $note);
                    $this->db->loopWrite(null, $stuckProject, $name, 'process', 'Marked stuck · no report for ' . $minutes . ' minutes');
                    $status = 'stuck';
                }
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
        if ($name === 'scheduler') {
            if (!$this->db->parallelWork()) {
                return null;
            }
            foreach ($fresh as $job) {
                if ((string)$job['kind'] === 'task') {
                    return $job;
                }
            }
            return null;
        }
        $parallel = $this->db->parallelWork();
        foreach ($fresh as $job) {
            $kind = (string)$job['kind'];
            if ($name === 'orchestrator' && $kind === 'chat') {
                return $job;
            }
            if ($name === 'orchestrator' && $kind === 'task' && !$parallel) {
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
        if ((int)($task['held'] ?? 0) === 1) {
            if ($jobId !== null) {
                $this->db->finishJob($jobId);
                $this->db->acknowledgeRunStop($projectId, $jobId);
            }
            $this->db->loopWrite($jobId, $projectId, 'heartbeat', 'process', 'Task ' . $taskId . ' held · not started');
            return;
        }
        if ($this->db->runStopRequested($projectId)) {
            if ($jobId !== null) {
                $this->db->updateTask($taskId, ['held' => 1]);
                $this->db->interruptJob($jobId);
                $this->db->acknowledgeRunStop($projectId, $jobId);
                $this->db->loopWrite($jobId, $projectId, 'heartbeat', 'process', 'Task ' . $taskId . ' stopped · held');
            } else {
                $this->db->loopWrite(null, $projectId, 'heartbeat', 'process', 'Task ' . $taskId . ' not started · run stopped');
            }
            return;
        }
        if ($ownJob) {
            Recovery::reset($this->db);
            $jobId = $this->db->startJob('task', $projectId, [
                'task_id' => (int)$task['id'],
            ], (int)$task['id']);
            $this->db->updateTask((int)$task['id'], ['status' => 'in_progress']);
            $fresh = $this->db->task((int)$task['id']) ?? $task;
            $lines = [];
            foreach ($fresh['subtasks'] ?? [] as $index => $sub) {
                if (!is_array($sub)) {
                    continue;
                }
                $step = trim((string)($sub['title'] ?? ''));
                $detail = trim((string)($sub['description'] ?? ''));
                if ($step === '' && $detail === '') {
                    continue;
                }
                $line = ($index + 1) . '. ' . $step;
                $stepAgent = trim((string)($sub['assigned_agent'] ?? ''));
                if ($stepAgent === '') {
                    $stepAgent = (string)($fresh['assigned_agent'] ?? '');
                }
                if ($stepAgent !== '') {
                    $line .= ' (' . $stepAgent . ')';
                }
                if ($detail !== '') {
                    $line .= "\n" . $detail;
                }
                $lines[] = $line;
            }
            $body = implode("\n", $lines);
            if ($body === '') {
                $body = trim((string)($fresh['description'] ?? ''));
            }
            $message = 'Scheduled task: #' . (int)$fresh['id'] . ' ' . $fresh['title'] . "\n\n" . $body;
            $this->db->addMessage($projectId, 'user', $message, 'task');
        } else {
            $this->db->deleteLatestFailed($projectId);
        }
        $this->db->loopPush($jobId, $projectId);
        $voice = $this->taskVoice();
        try {
            if ($ownJob) {
                $this->db->loop($voice, 'process', 'Task ' . (int)$task['id'] . ' started · job ' . $jobId . ' · in progress');
                $this->db->loop($voice, 'process', 'Recovery counters reset');
            } else {
                $this->db->loop($voice, 'process', 'Heartbeat retry · task ' . (int)$task['id'] . ' · job ' . $jobId);
            }
            $saved = false;
            $turn = new ChatTurn();
            try {
                $answer = $turn->run($projectId, static function (): void {
                }, function () use ($jobId, $projectId): bool {
                    $this->db->touchJob($jobId);
                    return $this->db->runStopRequested($projectId) || !$this->db->jobIsRunning($jobId);
                }, true);
                if ($this->db->runStopRequested($projectId) || !$this->db->jobIsRunning($jobId)) {
                    throw new ChatStopped('Stopped.');
                }
                $detail = ChatTurn::encodeDetail($turn->detail);
                if (trim($answer) === '' && $detail === null) {
                    throw new RuntimeException('The model returned an empty answer.');
                }
                $spoken = $answer !== '' ? $answer : 'Finished the scheduled task.';
                $this->db->addMessage($projectId, 'assistant', $spoken, 'orchestrator', false, $detail);
                $this->db->loop($voice, 'process', 'Answer saved · job ' . $jobId . ' finished');
                $this->db->finishJob($jobId);
                $saved = true;
                $this->settle($task, $this->withTaskNotes($spoken, $turn->notices), false, $turn->notices !== []);
            } catch (Throwable $e) {
                if ($e instanceof ChatStopped) {
                    $this->db->updateTask((int)$task['id'], ['held' => 1]);
                    $this->db->loop($voice, 'process', 'Stopped by user · job ' . $jobId);
                    $this->db->interruptJob($jobId);
                    $this->db->acknowledgeRunStop($projectId, $jobId);
                    $this->db->setAgentStatus($voice, 'idle');
                    return;
                }
                if ($saved) {
                    $this->db->loop('heartbeat', 'process', 'Answer saved · schedule not moved · job ' . $jobId . ' · ' . $e->getMessage());
                    return;
                }
                $this->db->failJob($jobId, $e->getMessage(), self::MAX_ATTEMPTS);
                $this->db->logAgent($voice, $projectId, 'error', $e->getMessage());
                $this->db->setAgentStatus($voice, 'error');
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
                $this->db->loop($voice, 'process', $line);
                $this->noteTaskFailure($task, (string)($current['last_error'] ?? $e->getMessage()));
                if ($ownJob && $status === 'gave_up') {
                    $this->settle($task, $this->withTaskNotes((string)($current['last_error'] ?? $e->getMessage()), $turn->notices), true);
                }
                if (!$ownJob) {
                    throw $e;
                }
            }
        } finally {
            app_services()['llm']->releaseIfIdle();
            $this->db->loopPop();
        }
    }

    public function taskSlotTaken(): bool
    {
        if ($this->db->taskRunning()) {
            return true;
        }
        return $this->taskProcessLive();
    }

    public function launchTask(array $task, ?int $jobId = null): void
    {
        $taskId = (int)($task['id'] ?? 0);
        $projectId = (int)($task['project_id'] ?? 0);
        if ($taskId <= 0 || $projectId <= 0) {
            return;
        }
        $php = PHP_BINARY !== '' ? PHP_BINARY : 'php';
        $script = dirname(__DIR__) . '/bin/run-task.php';
        $log = app_config()['data_dir'] . '/task-run.log';
        $cmd = 'nohup ' . escapeshellarg($php) . ' ' . escapeshellarg($script) . ' ' . $taskId;
        if ($jobId !== null && $jobId > 0) {
            $cmd .= ' ' . $jobId;
        }
        $lines = [];
        exec($cmd . ' >> ' . escapeshellarg($log) . ' 2>&1 < /dev/null & echo $!', $lines);
        $pid = (int)trim((string)($lines[0] ?? '0'));
        if ($pid <= 0) {
            $this->db->loopWrite($jobId, $projectId, 'heartbeat', 'process', 'Task ' . $taskId . ' did not start');
            return;
        }
        $this->db->setSetting('task_run_pid', (string)$pid);
        $line = 'Task ' . $taskId . ' started in the background';
        if ($jobId !== null && $jobId > 0) {
            $line .= ' · job ' . $jobId;
        }
        $this->db->loopWrite($jobId, $projectId, 'heartbeat', 'process', $line);
    }

    private function taskProcessLive(): bool
    {
        $pid = (int)$this->db->getSetting('task_run_pid', '0');
        if ($pid <= 1) {
            return false;
        }
        $result = Process::run(['ps', '-ww', '-p', (string)$pid, '-o', 'command='], null, null, 3);
        if ((int)$result['code'] !== 0) {
            return false;
        }
        return str_contains((string)$result['stdout'], 'run-task.php');
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
                }, $projectId, function () use ($id, $projectId): bool {
                    $this->db->touchJob($id);
                    return $this->db->runStopRequested($projectId) || !$this->db->jobIsRunning($id);
                }, !empty($payload['scheduled']));
                if ($this->db->runStopRequested($projectId) || !$this->db->jobIsRunning($id)) {
                    throw new ChatStopped('Stopped.');
                }
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
                }, function () use ($id, $projectId): bool {
                    $this->db->touchJob($id);
                    return $this->db->runStopRequested($projectId) || !$this->db->jobIsRunning($id);
                });
                if ($this->db->runStopRequested($projectId) || !$this->db->jobIsRunning($id)) {
                    throw new ChatStopped('Stopped.');
                }
            } catch (Throwable $e) {
                if (!($e instanceof ChatStopped)) {
                    $error = error_text($e->getMessage());
                    $text = trim(strip_tool_result_leak(strip_tool_markers($turn->streamed)));
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

    private function noteTaskFailure(array $task, string $error): void
    {
        $error = trim($error);
        if ($error === '') {
            $error = 'The scheduled task stopped.';
        }
        $this->db->updateTask((int)$task['id'], [
            'failed' => 1,
            'result' => clip_text($error, 2000),
        ]);
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
            $fields = ['status' => 'in_progress', 'schedule_time' => $next, 'failed' => $failed ? 1 : 0];
            if ($failed || $noted) {
                $fields['result'] = $result;
            } elseif ((int)($task['failed'] ?? 0) === 1) {
                $fields['result'] = '';
            }
            $board->move((int)$task['id'], $fields, false);
            $this->reopenFinishedSteps((int)$task['id']);
            return;
        }
        $saved = $board->move((int)$task['id'], [
            'status' => 'done',
            'result' => $result,
            'failed' => $failed ? 1 : 0,
        ], false);
        $line = (string)($saved['status'] ?? '') === 'done'
            ? 'Task ' . (int)$task['id'] . ' settled to done'
            : 'Task ' . (int)$task['id'] . ' settled · steps still open';
        if ($failed || $noted) {
            $line .= ' · result noted';
        }
        $this->db->loopWrite(null, $projectId > 0 ? $projectId : null, 'orchestrator', 'process', $line);
    }

    private function reopenFinishedSteps(int $taskId): void
    {
        $task = $this->db->task($taskId);
        if (!$task) {
            return;
        }
        $steps = [];
        foreach ($task['subtasks'] ?? [] as $sub) {
            if (!is_array($sub) || trim((string)($sub['title'] ?? '')) === '') {
                continue;
            }
            $steps[] = $sub;
        }
        if ($steps === []) {
            return;
        }
        foreach ($steps as $sub) {
            if ((string)($sub['status'] ?? '') !== 'done') {
                return;
            }
        }
        foreach ($steps as $sub) {
            $this->db->updateSubtask((int)$sub['id'], ['status' => 'in_progress', 'result' => '']);
        }
    }

    private function traceJob(array $job, string $line): void
    {
        $projectId = $job['project_id'] !== null ? (int)$job['project_id'] : null;
        $kind = (string)($job['kind'] ?? '');
        $agent = $kind === 'task' && $this->db->parallelWork() ? 'scheduler' : 'orchestrator';
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

    public function tasksToRun(): array
    {
        $due = [];
        foreach ($this->db->dueTasks() as $task) {
            if (empty($task['project_id'])) {
                continue;
            }
            $due[] = $task;
        }
        $run = [];
        $overdue = [];
        foreach ($due as $task) {
            if ($this->missedRepeat($task)) {
                $this->skipMissedRepeat($task);
                continue;
            }
            if ($this->overdueOnce($task)) {
                $overdue[] = $task;
                continue;
            }
            $run[] = $task;
        }
        if ($overdue !== [] && $this->catchUpOpen()) {
            $run[] = array_shift($overdue);
            if ($overdue !== []) {
                $this->armCatchUp();
            }
        }
        return $run;
    }

    private function missedRepeat(array $task): bool
    {
        $interval = strtolower(trim((string)($task['recurrence_interval'] ?? 'none')));
        if (!$this->repeats($interval)) {
            return false;
        }
        $when = trim((string)($task['schedule_time'] ?? ''));
        $at = $when !== '' ? strtotime($when) : false;
        if ($at === false || $at > time()) {
            return false;
        }
        $seconds = [
            '5min' => 300,
            '15min' => 900,
            '30min' => 1800,
            'hourly' => 3600,
            'daily' => 86400,
            'weekly' => 604800,
        ];
        if (isset($seconds[$interval])) {
            return time() >= $at + $seconds[$interval];
        }
        if ($interval === 'monthly') {
            $next = strtotime('+1 month', $at);
            return $next !== false && time() >= $next;
        }
        return false;
    }

    private function skipMissedRepeat(array $task): void
    {
        $next = $this->nextSchedule((string)($task['recurrence_interval'] ?? 'none'), (string)($task['schedule_time'] ?? ''));
        $projectId = (int)($task['project_id'] ?? 0);
        $id = (int)$task['id'];
        if (!$next) {
            $this->db->loopWrite(null, $projectId > 0 ? $projectId : null, 'heartbeat', 'process', 'Task ' . $id . ' missed · schedule not moved');
            return;
        }
        $this->db->updateTask($id, ['schedule_time' => $next]);
        $this->db->loopWrite(null, $projectId > 0 ? $projectId : null, 'heartbeat', 'process', 'Task ' . $id . ' missed · next at ' . $next);
    }

    private function overdueOnce(array $task): bool
    {
        if ($this->repeats((string)($task['recurrence_interval'] ?? 'none'))) {
            return false;
        }
        $when = trim((string)($task['schedule_time'] ?? ''));
        $at = $when !== '' ? strtotime($when) : false;
        if ($at === false) {
            return false;
        }
        return $at <= time() - Kanban::RESTART_DELAY;
    }

    private function catchUpOpen(): bool
    {
        $raw = trim((string)$this->db->getSetting('task_catchup_after', '0'));
        if ($raw === '' || $raw === '0') {
            return true;
        }
        $at = strtotime($raw);
        return $at === false || $at <= time();
    }

    private function armCatchUp(): void
    {
        $this->db->setSetting('task_catchup_after', date('Y-m-d H:i:s', time() + Kanban::RESTART_DELAY));
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

    private function taskVoice(): string
    {
        return $this->db->parallelWork() ? 'scheduler' : 'orchestrator';
    }
}
