<?php

class Kanban
{
    private const RESTART_DELAY = 180;

    public function __construct(private Database $db)
    {
    }

    public function create(int $projectId, array $args, bool $byUser): array
    {
        $title = trim((string)($args['title'] ?? ''));
        if ($title === '' || strlen($title) > 200) {
            throw new InvalidArgumentException('Task title is required.');
        }
        $inactivate = $this->inactivated($args['schedule_time'] ?? null);
        $schedule = $inactivate ? null : $this->schedule($args['schedule_time'] ?? null);
        $recurrence = $this->recurrence((string)($args['recurrence'] ?? $args['recurrence_interval'] ?? 'none'));
        $this->rejectIdea($args, $schedule, $recurrence, $byUser);
        $steps = $this->stepItems($args);
        if (!$byUser && $steps === []) {
            throw new InvalidArgumentException('A task needs at least one step. It was not added. Pass steps as a list. One instruction is step 1.');
        }
        $description = implode("\n", array_map(fn(array $step): string => $step['title'], $steps));
        $agent = $this->agent((string)($args['agent'] ?? $args['assigned_agent'] ?? 'orchestrator'));
        if (!$byUser) {
            $duplicate = $this->exactDuplicate($projectId, $title, $description, $agent, $schedule, $recurrence);
            if ($duplicate) {
                throw new InvalidArgumentException('Error: Task #' . $duplicate['id'] . ' is already on the task list as ' . $this->column($duplicate) . ' and matches this task exactly: ' . $duplicate['title'] . '. It was not added. Use that task.');
            }
        }
        $task = $this->db->insertTask([
            'project_id' => $projectId,
            'title' => $title,
            'description' => '',
            'status' => $inactivate ? 'in_progress' : $this->place((string)($args['status'] ?? 'in_progress'), $schedule, $recurrence, $byUser),
            'assigned_agent' => $agent,
            'schedule_time' => $schedule,
            'recurrence_interval' => $recurrence,
        ]);
        if ($inactivate) {
            $task = $this->db->updateTask((int)$task['id'], ['held' => 1, 'schedule_time' => null, 'status' => 'in_progress']);
        }
        if ($steps) {
            $this->addSteps((int)$task['id'], $steps, false);
            $task = $this->db->task((int)$task['id']) ?? $task;
        }
        return $this->db->syncTaskFromSteps($task);
    }

    public function crossOffSteps(int $taskId, bool $all = true, array $only = [], string $result = ''): array
    {
        $task = $this->db->task($taskId);
        if (!$task) {
            throw new RuntimeException('Task not found.');
        }
        $wanted = [];
        foreach ($only as $title) {
            $title = trim((string)$title);
            if ($title !== '') {
                $wanted[] = $title;
            }
        }
        foreach ($task['subtasks'] ?? [] as $sub) {
            if (!is_array($sub) || (string)($sub['status'] ?? '') === 'done') {
                continue;
            }
            $title = trim((string)($sub['title'] ?? ''));
            $match = $all || $wanted === [];
            if (!$match) {
                foreach ($wanted as $want) {
                    if (strcasecmp($title, $want) === 0) {
                        $match = true;
                        break;
                    }
                }
            }
            if ($match) {
                $fields = ['status' => 'done'];
                $line = trim($result);
                if ($line !== '' && !$all && trim((string)($sub['result'] ?? '')) === '') {
                    $fields['result'] = $this->oneLineResult($line);
                }
                $this->db->updateSubtask((int)$sub['id'], $fields);
            }
        }
        $fresh = $this->db->task($taskId);
        return $fresh ? $this->db->syncTaskFromSteps($fresh) : $task;
    }

    public function recordResult(int $id, int $projectId, string $result): array
    {
        $existing = $this->db->task($id);
        if (!$existing || (int)$existing['project_id'] !== $projectId) {
            throw new RuntimeException('Task not found.');
        }
        $result = trim($result);
        if ($result === '') {
            throw new InvalidArgumentException('A result is required.');
        }
        $saved = $this->db->updateTask($id, ['result' => $this->oneLineResult($result)]);
        if (!$saved) {
            throw new RuntimeException('Task not found.');
        }
        return $saved;
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
        $steps = $this->stepItems($args);
        $hasSteps = array_key_exists('steps', $args) || array_key_exists('subtasks', $args);
        $hasInstruction = $hasSteps || array_key_exists('description', $args) || array_key_exists('instruction', $args);
        if ($hasInstruction && $steps) {
            $fields['description'] = '';
        }
        if (array_key_exists('result', $args)) {
            $fields['result'] = $this->oneLineResult((string)$args['result']);
        }
        if (array_key_exists('failed', $args)) {
            $fields['failed'] = (int)$args['failed'] === 1 ? 1 : 0;
        }
        if (array_key_exists('agent', $args) || array_key_exists('assigned_agent', $args)) {
            $fields['assigned_agent'] = $this->agent((string)($args['agent'] ?? $args['assigned_agent'] ?? 'orchestrator'));
        }
        $inactivate = array_key_exists('schedule_time', $args) && $this->inactivated($args['schedule_time']);
        $schedule = $inactivate ? null : (array_key_exists('schedule_time', $args) ? $this->schedule($args['schedule_time'], $byUser) : ($existing['schedule_time'] ?? null));
        $recurrence = array_key_exists('recurrence', $args) || array_key_exists('recurrence_interval', $args)
            ? $this->recurrence((string)($args['recurrence'] ?? $args['recurrence_interval'] ?? 'none'))
            : (string)($existing['recurrence_interval'] ?? 'none');
        if (!$inactivate && $byUser && array_key_exists('description', $args)) {
            $newDescription = trim((string)($fields['description'] ?? ''));
            $oldDescription = trim((string)($existing['description'] ?? ''));
            if ($newDescription !== '' && $newDescription !== $oldDescription && $this->shouldDelayRestart($existing, $recurrence)) {
                $args['status'] = 'in_progress';
                $schedule = date('Y-m-d H:i:s', time() + self::RESTART_DELAY);
                $fields['schedule_time'] = $schedule;
            }
        }
        $this->rejectIdea($args, $schedule, $recurrence, $byUser);
        if (array_key_exists('schedule_time', $args) || array_key_exists('schedule_time', $fields)) {
            $fields['schedule_time'] = $schedule;
        }
        if (array_key_exists('recurrence', $args) || array_key_exists('recurrence_interval', $args)) {
            $fields['recurrence_interval'] = $recurrence;
        }
        if (array_key_exists('status', $args) || array_key_exists('schedule_time', $args) || array_key_exists('schedule_time', $fields) || array_key_exists('recurrence', $args) || array_key_exists('recurrence_interval', $args)) {
            $keepArchived = (string)($existing['status'] ?? '') === 'archived' && !array_key_exists('status', $args);
            $status = $this->place((string)($args['status'] ?? $existing['status'] ?? 'in_progress'), $schedule, $recurrence, $byUser, $keepArchived);
            $fields['status'] = $status;
            if ($status === 'archived') {
                $fields['finished_at'] = date('Y-m-d H:i:s');
            } elseif (($existing['status'] ?? '') === 'archived') {
                $fields['finished_at'] = null;
            }
        }
        $placed = (string)($fields['status'] ?? $existing['status'] ?? '');
        if ($inactivate) {
            $fields['held'] = 1;
            $fields['schedule_time'] = null;
            $fields['status'] = 'in_progress';
        } elseif ($byUser) {
            $fields['held'] = 0;
        } elseif ((int)($existing['held'] ?? 0) === 1 && $this->agentReleasesHold($args, $existing, $schedule, $recurrence, $placed)) {
            $fields['held'] = 0;
        }
        $saved = $this->db->updateTask($id, $fields);
        if ($saved && $steps && $hasInstruction) {
            $this->addSteps($id, $steps, !$hasSteps);
            $saved = $this->db->task($id) ?? $saved;
        }
        return $saved ? $this->db->syncTaskFromSteps($saved) : $saved;
    }

    public function oneLineResult(string $text): string
    {
        $text = trim($text);
        if ($text === '') {
            return '';
        }
        $lines = preg_split('/\R/u', $text) ?: [];
        $kept = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || $line === '---') {
                continue;
            }
            if (preg_match('/^(TOOL RESULT|Results:)/i', $line)) {
                continue;
            }
            if (preg_match('/^#\d+\s+\[/', $line)) {
                continue;
            }
            if (preg_match('/^-\s+\[(done|in_progress)\]/i', $line)) {
                continue;
            }
            if (preg_match('/^(Let me|I need to|I\'ll |I will |You\'re right|However,|Is there anything|Since it\'s already)/i', $line)) {
                continue;
            }
            if (str_ends_with($line, '?')) {
                continue;
            }
            $kept[] = $line;
        }
        $pick = $kept ? $kept[array_key_last($kept)] : $text;
        $pick = trim((string)preg_replace('/\s+/u', ' ', $pick));
        if (strlen($pick) > 180) {
            if (preg_match('/^.{20,160}?[.!](?=\s|$)/u', $pick, $found)) {
                $pick = $found[0];
            } else {
                $pick = rtrim(substr($pick, 0, 177)) . '…';
            }
        }
        return $pick;
    }

    private function agentReleasesHold(array $args, array $existing, ?string $schedule, string $recurrence, string $status): bool
    {
        if (array_key_exists('schedule_time', $args)) {
            $before = $existing['schedule_time'] ?? null;
            $before = is_string($before) && $before !== '' ? $before : null;
            if ($schedule !== $before) {
                return true;
            }
        }
        if (array_key_exists('recurrence', $args) || array_key_exists('recurrence_interval', $args)) {
            if ($recurrence !== (string)($existing['recurrence_interval'] ?? 'none')) {
                return true;
            }
        }
        return $status === 'done' || $status === 'archived';
    }

    public function listing(?int $projectId): string
    {
        if ($projectId !== null && $projectId <= 0) {
            return 'Open a project to see its tasks.';
        }
        $lines = [];
        foreach ($this->db->tasks($projectId) as $task) {
            if ($this->column($task) === 'archived' || (string)($task['status'] ?? '') === 'archived') {
                continue;
            }
            $line = '#' . $task['id'] . ' [' . $this->column($task) . '] ' . $task['title'] . ' (' . $task['assigned_agent'] . ')';
            if ((int)($task['held'] ?? 0) === 1 && empty($task['schedule_time'])) {
                $line .= ' never';
            } elseif (!empty($task['schedule_time'])) {
                $line .= ' at ' . $task['schedule_time'];
            } else {
                $line .= ' immediately';
            }
            if (($task['recurrence_interval'] ?? 'none') !== 'none') {
                $line .= ' ' . $task['recurrence_interval'];
            } else {
                $line .= ' run once';
            }
            if ((int)($task['held'] ?? 0) === 1 && !empty($task['schedule_time'])) {
                $line .= ' held';
            }
            $result = trim((string)($task['result'] ?? ''));
            if ($result !== '') {
                $line .= ' — ' . trim(clip_text(preg_replace('/\s+/', ' ', $result) ?? $result, 180));
            }
            $lines[] = $line;
            foreach ($task['subtasks'] ?? [] as $sub) {
                if (!is_array($sub)) {
                    continue;
                }
                $title = trim((string)($sub['title'] ?? ''));
                if ($title === '') {
                    continue;
                }
                $subStatus = (string)($sub['status'] ?? '') === 'done' ? 'done' : 'in_progress';
                $stepAgent = trim((string)($sub['assigned_agent'] ?? ''));
                if ($stepAgent === '') {
                    $stepAgent = (string)($task['assigned_agent'] ?? 'orchestrator');
                }
                $lines[] = '  - [' . $subStatus . '] ' . $title . ' (' . $stepAgent . ')';
                $stepResult = trim((string)($sub['result'] ?? ''));
                if ($stepResult !== '') {
                    $lines[] = '    Results: ' . trim(clip_text(preg_replace('/\s+/', ' ', $stepResult) ?? $stepResult, 180));
                }
                $detail = trim((string)($sub['description'] ?? ''));
                if ($detail !== '') {
                    $lines[] = '    ' . trim(clip_text(preg_replace('/\s+/', ' ', $detail) ?? $detail, 400));
                }
            }
        }
        return $lines ? implode("\n", $lines) : 'No open tasks.';
    }

    public function column(array $task): string
    {
        if ($this->repeats((string)($task['recurrence_interval'] ?? 'none'))) {
            return 'in_progress';
        }
        $status = (string)($task['status'] ?? '');
        if ($status === 'review') {
            return 'done';
        }
        if ($status === 'archived' || $status === 'done' || $status === 'in_progress') {
            return $status;
        }
        return 'in_progress';
    }

    public function place(string $status, ?string $schedule, string $recurrence, bool $byUser, bool $keepArchived = false): string
    {
        $status = strtolower(trim(str_replace(['-', ' '], '_', $status)));
        $aliases = [
            '' => 'in_progress',
            'todo' => 'in_progress',
            'to_do' => 'in_progress',
            'later' => 'in_progress',
            'queued' => 'in_progress',
            'backlog' => 'in_progress',
            'active' => 'in_progress',
            'activated' => 'in_progress',
            'started' => 'in_progress',
            'working' => 'in_progress',
            'wip' => 'in_progress',
            'inprogress' => 'in_progress',
            'review' => 'done',
            'complete' => 'done',
            'completed' => 'done',
            'finished' => 'done',
            'archive' => 'archived',
        ];
        if (array_key_exists($status, $aliases)) {
            $status = $aliases[$status];
        }
        if (!$byUser && $status === 'archived') {
            $status = $keepArchived ? 'archived' : 'done';
        }
        if (!in_array($status, ['in_progress', 'done', 'archived'], true)) {
            $status = 'in_progress';
        }
        if ($this->repeats($recurrence) && in_array($status, ['done', 'archived'], true)) {
            return 'in_progress';
        }
        return $status;
    }

    private function rejectIdea(array $args, ?string $schedule, string $recurrence, bool $byUser): void
    {
        if ($byUser || !array_key_exists('status', $args) || $this->inactivated($args['schedule_time'] ?? null)) {
            return;
        }
        $status = strtolower(trim(str_replace(['-', ' '], '_', (string)$args['status'])));
        if (!in_array($status, ['backlog', 'todo', 'to_do', 'later', 'queued'], true)) {
            return;
        }
        if ($this->isScheduled($schedule, $recurrence)) {
            return;
        }
        throw new InvalidArgumentException('There is no backlog. To keep a task without running it, set schedule_time to never. That inactivates it. A task runs now, or it has a real clock time.');
    }

    private function stepItems(array $args): array
    {
        $raw = $args['steps'] ?? $args['subtasks'] ?? null;
        $items = [];
        $add = function (string $title, string $agent, string $description) use (&$items): void {
            $title = trim($title);
            if ($title === '') {
                return;
            }
            $agent = strtolower(trim($agent));
            if ($agent === 'user') {
                $agent = 'human';
            }
            if (!in_array($agent, ['orchestrator', 'researcher', 'writer', 'coder', 'analyst', 'marketer', 'consultant', 'human'], true)) {
                $agent = '';
            }
            $items[] = [
                'title' => mb_substr($title, 0, 2000),
                'agent' => $agent,
                'description' => mb_substr(trim($description), 0, 20000),
            ];
        };
        if (is_string($raw)) {
            $add($raw, '', '');
        } elseif (is_array($raw)) {
            foreach ($raw as $step) {
                if (is_array($step)) {
                    $add((string)($step['title'] ?? $step['step'] ?? $step['text'] ?? ''), (string)($step['agent'] ?? $step['assigned_agent'] ?? ''), (string)($step['description'] ?? $step['detail'] ?? ''));
                } else {
                    $add((string)$step, '', '');
                }
            }
        }
        if ($items) {
            return $items;
        }
        $one = trim((string)($args['description'] ?? $args['instruction'] ?? ''));
        if ($one !== '') {
            $add($one, '', '');
        }
        return $items;
    }

    private function addSteps(int $taskId, array $items, bool $onlyIfEmpty): void
    {
        $task = $this->db->task($taskId);
        if (!$task) {
            return;
        }
        $existing = [];
        foreach ($task['subtasks'] ?? [] as $sub) {
            if (!is_array($sub)) {
                continue;
            }
            $title = trim((string)($sub['title'] ?? ''));
            if ($title !== '') {
                $existing[] = $title;
            }
        }
        if ($onlyIfEmpty && $existing) {
            return;
        }
        foreach ($items as $item) {
            $title = trim((string)($item['title'] ?? ''));
            if ($title === '' || in_array($title, $existing, true)) {
                continue;
            }
            $this->db->insertSubtask($taskId, $title, 'in_progress', (string)($item['agent'] ?? ''), (string)($item['description'] ?? ''));
            $existing[] = $title;
        }
    }

    private function instructionOf(array $task): string
    {
        $description = trim((string)($task['description'] ?? ''));
        if ($description !== '') {
            return $description;
        }
        $parts = [];
        foreach ($task['subtasks'] ?? [] as $sub) {
            if (!is_array($sub)) {
                continue;
            }
            $title = trim((string)($sub['title'] ?? ''));
            if ($title !== '') {
                $parts[] = $title;
            }
        }
        return implode("\n", $parts);
    }

    private function exactDuplicate(int $projectId, string $title, string $description, string $agent, ?string $schedule, string $recurrence): ?array
    {
        foreach ($this->db->tasks($projectId) as $task) {
            if ($this->column($task) === 'archived' || (string)($task['status'] ?? '') === 'archived') {
                continue;
            }
            if (trim((string)($task['title'] ?? '')) !== $title) {
                continue;
            }
            if ($this->instructionOf($task) !== $description) {
                continue;
            }
            if ((string)($task['assigned_agent'] ?? '') !== $agent) {
                continue;
            }
            $existingSchedule = $task['schedule_time'] ?? null;
            if ($existingSchedule === '') {
                $existingSchedule = null;
            }
            if ($existingSchedule !== $schedule) {
                continue;
            }
            $existingRepeat = strtolower(trim((string)($task['recurrence_interval'] ?? '')));
            if ($existingRepeat === '') {
                $existingRepeat = 'none';
            }
            if ($existingRepeat !== $recurrence) {
                continue;
            }
            return $task;
        }
        return null;
    }

    private function shouldDelayRestart(array $existing, string $recurrence): bool
    {
        $status = (string)($existing['status'] ?? '');
        if ($status === 'done') {
            return true;
        }
        if ($status !== 'in_progress' || $recurrence !== 'none') {
            return false;
        }
        $when = $existing['schedule_time'] ?? null;
        if (!is_string($when) || $when === '') {
            return false;
        }
        $at = strtotime($when);
        if ($at === false) {
            return false;
        }
        $now = time();
        return $at > $now && $at <= $now + self::RESTART_DELAY;
    }

    private function inactivated(mixed $value): bool
    {
        if (!is_string($value)) {
            return false;
        }
        $value = strtolower(trim($value));
        return in_array($value, ['never', 'inactivated', 'inactive', 'never (inactivated)'], true);
    }

    private function repeats(string $recurrence): bool
    {
        return $recurrence !== '' && $recurrence !== 'none';
    }

    private function isScheduled(mixed $schedule, string $recurrence): bool
    {
        if ($this->repeats($recurrence)) {
            return true;
        }
        return is_string($schedule) && $schedule !== '';
    }

    private function agent(string $agent): string
    {
        return in_array($agent, ['orchestrator', 'researcher', 'writer', 'coder', 'analyst', 'marketer', 'consultant', 'human'], true) ? $agent : 'orchestrator';
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

    private function schedule(mixed $value, bool $strict = false): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $value = str_replace('T', ' ', trim((string)$value));
        $value = preg_replace('/\.\d+$/', '', $value) ?? $value;
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $value)) {
            $value .= ':00';
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value)) {
            if ($strict) {
                throw new InvalidArgumentException('Time must be a date and a clock time, or left empty to run immediately.');
            }
            return null;
        }
        return $value;
    }
}
