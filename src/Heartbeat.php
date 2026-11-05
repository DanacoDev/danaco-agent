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
            $this->db->addMessage($projectId, 'user', $message, 'human');
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
            $next = $this->nextSchedule((string)($task['recurrence_interval'] ?? 'none'), (string)($task['schedule_time'] ?? ''));
            $board = new Kanban($this->db);
            if ($next) {
                $board->move((int)$task['id'], ['status' => 'in_progress', 'schedule_time' => $next], false);
            } else {
                $board->move((int)$task['id'], [
                    'status' => 'review',
                    'result' => $answer !== '' ? $answer : 'Finished the scheduled task.',
                ], false);
            }
        } catch (Throwable $e) {
            if ($e instanceof ChatStopped) {
                $this->db->finishJob($jobId);
                return;
            }
            $this->db->failJob($jobId, $e->getMessage(), self::MAX_ATTEMPTS);
            $this->db->logAgent('orchestrator', $projectId, 'error', $e->getMessage());
            $this->db->setAgentStatus('orchestrator', 'error');
            $current = $this->db->job($jobId);
            if (($current['status'] ?? '') === 'gave_up') {
                (new Kanban($this->db))->move((int)$task['id'], [
                    'status' => 'review',
                    'result' => (string)($current['last_error'] ?? $e->getMessage()),
                ], false);
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
        if ($taskId > 0 && $this->db->task($taskId)) {
            (new Kanban($this->db))->move($taskId, [
                'status' => 'review',
                'result' => (string)($current['last_error'] ?? 'The scheduled task stopped.'),
            ], false);
        }
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
