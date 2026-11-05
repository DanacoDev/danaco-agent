<?php

class Kanban
{
    public function __construct(private Database $db)
    {
    }

    public function create(int $projectId, array $args, bool $byUser): array
    {
        $title = trim((string)($args['title'] ?? ''));
        if ($title === '' || strlen($title) > 200) {
            throw new InvalidArgumentException('Task title is required.');
        }
        $schedule = $this->schedule($args['schedule_time'] ?? null);
        $recurrence = $this->recurrence((string)($args['recurrence'] ?? $args['recurrence_interval'] ?? 'none'));
        return $this->db->insertTask([
            'project_id' => $projectId,
            'title' => $title,
            'description' => mb_substr((string)($args['description'] ?? ''), 0, 20000),
            'status' => $this->place((string)($args['status'] ?? 'backlog'), $schedule, $recurrence, $byUser),
            'assigned_agent' => $this->agent((string)($args['agent'] ?? $args['assigned_agent'] ?? 'orchestrator')),
            'schedule_time' => $schedule,
            'recurrence_interval' => $recurrence,
        ]);
    }

    public function move(int $id, array $args, bool $byUser): array
    {
        $existing = $this->db->task($id);
        if (!$existing) {
            throw new RuntimeException('Task not found.');
        }
        $fields = [];
        if (array_key_exists('title', $args)) {
            $title = trim((string)$args['title']);
            if ($title === '' || strlen($title) > 200) {
                throw new InvalidArgumentException('Task title is required.');
            }
            $fields['title'] = $title;
        }
        if (array_key_exists('description', $args)) {
            $fields['description'] = mb_substr((string)$args['description'], 0, 20000);
        }
        if (array_key_exists('result', $args)) {
            $fields['result'] = clip_text(trim((string)$args['result']), 2000);
        }
        if (array_key_exists('agent', $args) || array_key_exists('assigned_agent', $args)) {
            $fields['assigned_agent'] = $this->agent((string)($args['agent'] ?? $args['assigned_agent'] ?? 'orchestrator'));
        }
        $schedule = array_key_exists('schedule_time', $args) ? $this->schedule($args['schedule_time']) : ($existing['schedule_time'] ?? null);
        $recurrence = array_key_exists('recurrence', $args) || array_key_exists('recurrence_interval', $args)
            ? $this->recurrence((string)($args['recurrence'] ?? $args['recurrence_interval'] ?? 'none'))
            : (string)($existing['recurrence_interval'] ?? 'none');
        if (array_key_exists('schedule_time', $args)) {
            $fields['schedule_time'] = $schedule;
        }
        if (array_key_exists('recurrence', $args) || array_key_exists('recurrence_interval', $args)) {
            $fields['recurrence_interval'] = $recurrence;
        }
        if (array_key_exists('status', $args) || array_key_exists('schedule_time', $args) || array_key_exists('recurrence', $args) || array_key_exists('recurrence_interval', $args)) {
            $status = $this->place((string)($args['status'] ?? $existing['status'] ?? 'backlog'), $schedule, $recurrence, $byUser);
            $fields['status'] = $status;
            if ($status === 'done') {
                $fields['finished_at'] = date('Y-m-d H:i:s');
            } elseif (($existing['status'] ?? '') === 'done') {
                $fields['finished_at'] = null;
            }
        }
        return $this->db->updateTask($id, $fields);
    }

    public function listing(?int $projectId): string
    {
        if ($projectId !== null && $projectId <= 0) {
            return 'Open a project to see its tasks.';
        }
        $lines = [];
        foreach ($this->db->tasks($projectId) as $task) {
            if (($task['status'] ?? '') === 'done') {
                continue;
            }
            $line = '#' . $task['id'] . ' [' . $this->column($task) . '] ' . $task['title'] . ' (' . $task['assigned_agent'] . ')';
            if (!empty($task['schedule_time'])) {
                $line .= ' at ' . $task['schedule_time'];
                if (($task['recurrence_interval'] ?? 'none') !== 'none') {
                    $line .= ' ' . $task['recurrence_interval'];
                }
            }
            $result = trim((string)($task['result'] ?? ''));
            if ($result !== '') {
                $line .= ' — ' . trim(clip_text(preg_replace('/\s+/', ' ', $result) ?? $result, 180));
            }
            $lines[] = $line;
        }
        return $lines ? implode("\n", $lines) : 'No open tasks.';
    }

    public function column(array $task): string
    {
        $status = (string)($task['status'] ?? '');
        if ($status === 'done' || $status === 'review' || $status === 'in_progress') {
            return $status;
        }
        if ($this->isScheduled($task['schedule_time'] ?? null, (string)($task['recurrence_interval'] ?? 'none'))) {
            return 'in_progress';
        }
        return 'backlog';
    }

    public function place(string $status, ?string $schedule, string $recurrence, bool $byUser): string
    {
        $status = strtolower(trim(str_replace(['-', ' '], '_', $status)));
        $aliases = [
            '' => 'backlog',
            'todo' => 'backlog',
            'to_do' => 'backlog',
            'later' => 'backlog',
            'queued' => 'backlog',
            'active' => 'in_progress',
            'activated' => 'in_progress',
            'started' => 'in_progress',
            'working' => 'in_progress',
            'wip' => 'in_progress',
            'inprogress' => 'in_progress',
        ];
        if (array_key_exists($status, $aliases)) {
            $status = $aliases[$status];
        }
        if (in_array($status, ['complete', 'completed', 'finished'], true)) {
            $status = $byUser ? 'done' : 'review';
        }
        if (!$byUser && $status === 'done') {
            $status = 'review';
        }
        if (!in_array($status, ['backlog', 'in_progress', 'review', 'done'], true)) {
            $status = 'backlog';
        }
        if ($status === 'backlog' && $this->isScheduled($schedule, $recurrence)) {
            return 'in_progress';
        }
        return $status;
    }

    private function isScheduled(mixed $schedule, string $recurrence): bool
    {
        if ($recurrence !== '' && $recurrence !== 'none') {
            return true;
        }
        return is_string($schedule) && $schedule !== '';
    }

    private function agent(string $agent): string
    {
        return in_array($agent, ['orchestrator', 'researcher', 'writer', 'coder', 'analyst', 'marketer', 'human'], true) ? $agent : 'orchestrator';
    }

    private function recurrence(string $value): string
    {
        $value = strtolower(trim($value));
        $aliases = [
            'every 5 min' => '5min',
            'every 5 minutes' => '5min',
            '5 min' => '5min',
            'every 15 min' => '15min',
            'every 15 minutes' => '15min',
            '15 min' => '15min',
            'every 30 min' => '30min',
            'every 30 minutes' => '30min',
            '30 min' => '30min',
            'every hour' => 'hourly',
            'hour' => 'hourly',
            'every month' => 'monthly',
            'month' => 'monthly',
        ];
        $value = $aliases[$value] ?? $value;
        return in_array($value, ['none', '5min', '15min', '30min', 'hourly', 'daily', 'weekly', 'monthly'], true) ? $value : 'none';
    }

    private function schedule(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $value = str_replace('T', ' ', trim((string)$value));
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $value)) {
            $value .= ':00';
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value)) {
            return null;
        }
        return $value;
    }
}
