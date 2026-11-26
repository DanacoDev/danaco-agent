<?php

class Kanban
{
    public const RESTART_DELAY = 120;

    private array $lastStepWrite = ['added' => 0, 'detailed' => 0, 'removed' => 0, 'crossed' => 0, 'still_open' => 0, 'already_done' => 0, 'open_titles' => []];

    public function __construct(private Database $db)
    {
    }

    public function create(int $projectId, array $args, bool $byUser): array
    {
        $title = $this->oneLine((string)($args['title'] ?? ''));
        if ($title === '' || strlen($title) > 200) {
            throw new InvalidArgumentException('Task title is required.');
        }
        if (!$byUser && $this->researchSetup($args, null)) {
            throw new InvalidArgumentException('A research task is created by research_loop action mission. It was not added. Pass title and question to mission. Do not write the steps yourself.');
        }
        $inactivate = $this->inactivated($args['schedule_time'] ?? null);
        $schedule = $inactivate ? null : $this->schedule($args['schedule_time'] ?? null);
        $recurrence = $this->recurrence((string)($args['recurrence'] ?? $args['recurrence_interval'] ?? 'none'));
        $this->rejectIdea($args, $schedule, $recurrence, $byUser);
        $steps = $this->stepItems($args);
        if (!$byUser && $steps === []) {
            throw new InvalidArgumentException('A task needs at least one step. It was not added. Each step is an object with title, agent, and description. description names the files, lists, and skills to use, and what to save or return.');
        }
        if (!$byUser) {
            $this->requireStepDetail($steps);
        }
        $description = implode("\n", array_map(fn(array $step): string => $step['title'], $steps));
        $agent = $this->agent((string)($args['agent'] ?? $args['assigned_agent'] ?? 'orchestrator'));
        if (!$byUser) {
            $stepTask = $this->openStepTask($projectId, $title);
            if ($stepTask) {
                throw new InvalidArgumentException('Error: Task #' . $stepTask['id'] . ' already has that open step: ' . $title . '. It was not added. Update that task. Do not create a new one.');
            }
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
            $title = $this->oneLine((string)$args['title']);
            if ($title === '' || strlen($title) > 200) {
                throw new InvalidArgumentException('Task title is required.');
            }
            $fields['title'] = $title;
        }
        $steps = $this->stepItems($args);
        $hasSteps = array_key_exists('steps', $args) || array_key_exists('subtasks', $args);
        if (!$byUser && $hasSteps && $this->researchSetup($args, $existing)) {
            throw new InvalidArgumentException('That research task is fixed. It was not changed. Do not rewrite the steps. To pause it, set schedule_time to never. A clock time and recurrence 15min turns it back on.');
        }
        if (!$byUser && $hasSteps) {
            if ($steps === []) {
                throw new InvalidArgumentException('A step needs a title and a description. It was not saved. Pass each step as an object with title, agent, and description. description names the files, lists, and skills to use, and what to save or return.');
            }
            $this->requireStepDetail($steps);
        }
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
        if ($saved && $byUser && array_key_exists('schedule_time', $args) && !$inactivate && $schedule === null) {
            $previous = $existing['schedule_time'] ?? null;
            $wasImmediate = ($previous === null || $previous === '') && (int)($existing['held'] ?? 0) !== 1;
            if (!$wasImmediate) {
                $this->db->releaseImmediateRun($id);
            }
        }
        if ($saved && $steps && $hasInstruction) {
            $taskResult = array_key_exists('result', $args) ? $this->oneLineResult((string)$args['result']) : '';
            $this->addSteps($id, $steps, !$hasSteps, $hasSteps && $this->wantsReplace($args), $taskResult);
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
            if (preg_match('/^-\s+\[(done|in_progress|scheduled)\]/i', $line)) {
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
            $waiting = $this->awaiting($task);
            $line = '#' . $task['id'] . ' [' . ($waiting ? 'scheduled' : $this->column($task)) . '] ' . $task['title'] . ' (' . $task['assigned_agent'] . ')';
            if ((int)($task['held'] ?? 0) === 1 && empty($task['schedule_time'])) {
                $line .= ' never';
            } elseif ($waiting) {
                $line .= ' scheduled for ' . $task['schedule_time'] . $this->startWaitNote($task);
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
                $subStatus = (string)($sub['status'] ?? '') === 'done' ? 'done' : ($waiting ? 'scheduled' : 'in_progress');
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
        $text = $lines ? implode("\n", $lines) : 'No open tasks.';
        if ($projectId !== null && $projectId > 0) {
            $working = $this->db->workingLines($projectId);
            if ($working) {
                $text .= "\n" . implode("\n", $working);
            }
        }
        return $text;
    }

    public function awaiting(array $task): bool
    {
        if ((int)($task['held'] ?? 0) === 1) {
            return false;
        }
        $when = $task['schedule_time'] ?? null;
        if (!is_string($when) || trim($when) === '') {
            return false;
        }
        $at = strtotime($when);
        return $at !== false && $at > time();
    }

    public function createdLine(array $task): string
    {
        $line = 'Created task #' . $task['id'];
        if ($this->awaiting($task)) {
            $line .= ', scheduled for ' . $task['schedule_time'];
        } else {
            $line .= ' in ' . $this->column($task);
        }
        return $line . ': ' . $task['title'] . $this->reviewNote($task);
    }

    public function updatedLine(array $task): string
    {
        $line = 'Updated task #' . $task['id'];
        if ($this->awaiting($task)) {
            $line .= ', scheduled for ' . $task['schedule_time'];
        } else {
            $line .= ' to ' . $this->column($task);
        }
        return $line . ': ' . $task['title'] . $this->reviewNote($task);
    }

    private function startWaitNote(array $task): string
    {
        if (!$this->soonStart($task)) {
            return '';
        }
        return ' 2 minutes ahead so a mistake can be corrected before it runs.';
    }

    private function soonStart(array $task): bool
    {
        if (!$this->awaiting($task)) {
            return false;
        }
        $at = strtotime((string)($task['schedule_time'] ?? ''));
        return $at !== false && $at <= time() + self::RESTART_DELAY;
    }

    private function reviewNote(array $task): string
    {
        $why = $this->soonStart($task) ? ' The time is 2 minutes ahead so you can correct a mistake before it runs.' : '';
        return $why . ' List the tasks and review every one. Check that the titles, steps, agents, and times match what was intended. If something is wrong, update that task. Do not delete it to start sooner, and do not delegate.';
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
        $add = function (string $title, string $agent, string $description, bool $done = false, string $result = '') use (&$items): void {
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
                'done' => $done,
                'result' => mb_substr(trim($result), 0, 2000),
            ];
        };
        if (is_string($raw)) {
            $add($raw, '', '');
        } elseif (is_array($raw)) {
            foreach ($raw as $step) {
                if (is_array($step)) {
                    $add((string)($step['title'] ?? $step['step'] ?? $step['text'] ?? ''), (string)($step['agent'] ?? $step['assigned_agent'] ?? ''), (string)($step['description'] ?? $step['detail'] ?? ''), $this->stepMarkedDone($step), $this->stepResultText($step));
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
            $title = trim((string)($args['title'] ?? ''));
            if ($title === '' || strcasecmp($title, $one) === 0) {
                $title = $this->shortStepTitle($one);
            }
            $add($title, '', $one);
        }
        return $items;
    }

    public function stepWrite(): array
    {
        return $this->lastStepWrite;
    }

    public function unchangedStepsNote(): string
    {
        $titles = [];
        foreach ($this->lastStepWrite['open_titles'] ?? [] as $title) {
            $title = trim((string)$title);
            if ($title !== '') {
                $titles[] = $title;
            }
        }
        if ($titles !== []) {
            $named = '"' . implode('", "', $titles) . '"';
            if (count($titles) === 1) {
                return ' Step ' . $named . ' is still open. It was not crossed off. To cross it off, pass that step with result set to one line of what it produced.';
            }
            return ' Steps ' . $named . ' are still open. They were not crossed off. To cross one off, pass that step with result set to one line of what it produced.';
        }
        if ((int)($this->lastStepWrite['already_done'] ?? 0) > 0) {
            return ' Those steps are already crossed off. Do not pass result again. List the tasks and delegate the next open step, or stop if none are open.';
        }
        return ' Those steps are already on the task. To cross one off, pass that step with result set to done, or to one line of what it produced. Do not replace the steps and do not create a new task.';
    }

    private function requireStepDetail(array $steps): void
    {
        foreach ($steps as $step) {
            if (!empty($step['done'])) {
                continue;
            }
            $detail = trim((string)($step['description'] ?? ''));
            if (mb_strlen($detail) >= 80) {
                continue;
            }
            $shown = trim((string)($step['title'] ?? ''));
            if ($shown === '') {
                $shown = 'untitled';
            }
            if (mb_strlen($shown) > 80) {
                $shown = rtrim(mb_substr($shown, 0, 77)) . '…';
            }
            $fault = $detail === '' ? 'needs a description' : 'needs a longer description';
            throw new InvalidArgumentException('Step "' . $shown . '" ' . $fault . '. It was not saved. Pass the step as an object with title, agent, and description. description is the instruction: name the files, lists, and skills to use, and what to save or return. A title alone is not enough.');
        }
    }

    private function shortStepTitle(string $text): string
    {
        $text = trim((string)preg_replace('/\s+/u', ' ', $text));
        if (mb_strlen($text) <= 80) {
            return $text;
        }
        $clip = mb_substr($text, 0, 77);
        $space = strrpos($clip, ' ');
        if ($space !== false && $space > 40) {
            $clip = mb_substr($clip, 0, $space);
        }
        return rtrim($clip, '.,;:') . '…';
    }

    private function addSteps(int $taskId, array $items, bool $onlyIfEmpty, bool $replace = false, string $taskResult = ''): void
    {
        $this->lastStepWrite = ['added' => 0, 'detailed' => 0, 'removed' => 0, 'crossed' => 0, 'still_open' => 0, 'already_done' => 0, 'open_titles' => []];
        $task = $this->db->task($taskId);
        if (!$task) {
            return;
        }
        $subs = [];
        foreach ($task['subtasks'] ?? [] as $sub) {
            if (is_array($sub) && trim((string)($sub['title'] ?? '')) !== '') {
                $subs[] = $sub;
            }
        }
        if ($onlyIfEmpty && $subs) {
            return;
        }
        $taskResult = $this->oneLineResult($taskResult);
        $this->closeDuplicateOpenSteps($subs);
        $touched = [];
        foreach ($items as $item) {
            $title = trim((string)($item['title'] ?? ''));
            if ($title === '') {
                continue;
            }
            $markDone = !empty($item['done']);
            $ownResult = trim((string)($item['result'] ?? ''));
            $openIndexes = [];
            if ($markDone) {
                $openIndexes = $this->exactStepIndexes($title, $subs, true);
            }
            if ($openIndexes === []) {
                $matchAt = $this->matchingStepIndex($title, $subs, true);
                if ($matchAt !== null) {
                    $openIndexes = [$matchAt];
                }
            }
            if ($openIndexes !== []) {
                foreach ($openIndexes as $matchAt) {
                    $fields = [];
                    $haveTitle = trim((string)($subs[$matchAt]['title'] ?? ''));
                    if (strcasecmp($title, $haveTitle) !== 0) {
                        $fields['title'] = $title;
                    }
                    $incoming = trim((string)($item['description'] ?? ''));
                    $have = trim((string)($subs[$matchAt]['description'] ?? ''));
                    $keepInstruction = $markDone && $have !== '' && mb_strlen($incoming) < 80;
                    if ($incoming !== '' && $incoming !== $have && !$keepInstruction) {
                        $fields['description'] = $incoming;
                    }
                    $agent = (string)($item['agent'] ?? '');
                    $haveAgent = strtolower(trim((string)($subs[$matchAt]['assigned_agent'] ?? '')));
                    if ($agent !== '' && $agent !== $haveAgent) {
                        $fields['assigned_agent'] = $agent;
                    }
                    $detailFields = $fields;
                    if ($markDone && (string)($subs[$matchAt]['status'] ?? '') !== 'done') {
                        $fields['status'] = 'done';
                        $existingResult = trim((string)($subs[$matchAt]['result'] ?? ''));
                        if ($ownResult !== '') {
                            $fields['result'] = $this->oneLineResult($ownResult);
                        } elseif ($existingResult === '' && $taskResult !== '') {
                            $fields['result'] = $taskResult;
                        }
                        $this->lastStepWrite['crossed']++;
                    }
                    if ($fields) {
                        $this->db->updateSubtask((int)$subs[$matchAt]['id'], $fields);
                        $subs[$matchAt] = array_merge($subs[$matchAt], $fields);
                    }
                    if ($detailFields) {
                        $this->lastStepWrite['detailed']++;
                    }
                    if ((string)($subs[$matchAt]['status'] ?? '') !== 'done') {
                        $openTitle = trim((string)($subs[$matchAt]['title'] ?? ''));
                        if ($openTitle === '') {
                            $openTitle = $title;
                        }
                        if ($openTitle !== '' && !in_array($openTitle, $this->lastStepWrite['open_titles'], true)) {
                            $this->lastStepWrite['open_titles'][] = $openTitle;
                            $this->lastStepWrite['still_open']++;
                        }
                    }
                    $touched[] = (int)$subs[$matchAt]['id'];
                }
                continue;
            }
            if ($this->matchingStepIndex($title, $subs, false) !== null) {
                $this->lastStepWrite['already_done']++;
                continue;
            }
            $created = $this->db->insertSubtask($taskId, $title, $markDone ? 'done' : 'in_progress', (string)($item['agent'] ?? ''), (string)($item['description'] ?? ''));
            if ($markDone) {
                $line = $ownResult !== '' ? $this->oneLineResult($ownResult) : $taskResult;
                if ($line !== '') {
                    $created = $this->db->updateSubtask((int)$created['id'], ['result' => $line]) ?? $created;
                }
                $this->lastStepWrite['crossed']++;
            } else {
                $this->lastStepWrite['added']++;
            }
            $subs[] = $created;
            $touched[] = (int)($created['id'] ?? 0);
        }
        if (!$replace) {
            return;
        }
        foreach ($subs as $sub) {
            if ((string)($sub['status'] ?? '') === 'done') {
                continue;
            }
            $id = (int)($sub['id'] ?? 0);
            if ($id <= 0 || in_array($id, $touched, true)) {
                continue;
            }
            $this->db->deleteSubtask($id);
            $this->lastStepWrite['removed']++;
        }
    }

    private function closeDuplicateOpenSteps(array &$subs): void
    {
        foreach ($subs as $index => $sub) {
            if (!is_array($sub) || (string)($sub['status'] ?? '') === 'done') {
                continue;
            }
            $title = trim((string)($sub['title'] ?? ''));
            $id = (int)($sub['id'] ?? 0);
            if ($title === '' || $id <= 0) {
                continue;
            }
            $doneAt = null;
            foreach ($subs as $otherIndex => $other) {
                if ($otherIndex === $index || !is_array($other) || (string)($other['status'] ?? '') !== 'done') {
                    continue;
                }
                if (strcasecmp($title, trim((string)($other['title'] ?? ''))) === 0) {
                    $doneAt = $otherIndex;
                    break;
                }
            }
            if ($doneAt === null) {
                continue;
            }
            $fields = ['status' => 'done'];
            $doneResult = trim((string)($subs[$doneAt]['result'] ?? ''));
            if ($doneResult !== '' && trim((string)($sub['result'] ?? '')) === '') {
                $fields['result'] = $doneResult;
            }
            $this->db->updateSubtask($id, $fields);
            $subs[$index] = array_merge($sub, $fields);
            $this->lastStepWrite['crossed']++;
        }
    }

    private function stepMarkedDone(array $step): bool
    {
        if (array_key_exists('done', $step) && $this->stepFlag($step['done'])) {
            return true;
        }
        $status = strtolower(trim((string)($step['status'] ?? '')));
        if (in_array($status, ['done', 'complete', 'completed', 'finished'], true)) {
            return true;
        }
        $result = $step['result'] ?? '';
        if ($result === true || $result === 1) {
            return true;
        }
        return trim((string)$result) !== '';
    }

    private function stepResultText(array $step): string
    {
        $result = $step['result'] ?? '';
        if (!is_string($result) && !is_numeric($result)) {
            return '';
        }
        $text = trim((string)$result);
        if ($this->stepFlag($text)) {
            return '';
        }
        return $text;
    }

    private function stepFlag(mixed $value): bool
    {
        if (is_bool($value) || is_int($value) || is_float($value)) {
            return (int)$value === 1;
        }
        return in_array(strtolower(trim((string)$value)), ['1', 'true', 'yes', 'done', 'complete', 'completed', 'finished'], true);
    }

    private function exactStepIndexes(string $title, array $subs, bool $openOnly): array
    {
        $found = [];
        foreach ($subs as $index => $sub) {
            if (!is_array($sub)) {
                continue;
            }
            $open = (string)($sub['status'] ?? '') !== 'done';
            if ($openOnly !== $open) {
                continue;
            }
            $have = trim((string)($sub['title'] ?? ''));
            if ($have !== '' && strcasecmp($title, $have) === 0) {
                $found[] = $index;
            }
        }
        return $found;
    }

    private function wantsReplace(array $args): bool
    {
        $value = $args['replace_steps'] ?? false;
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return (int)$value === 1;
        }
        return in_array(strtolower(trim((string)$value)), ['1', 'true', 'yes'], true);
    }

    private function matchingStepIndex(string $title, array $subs, bool $openOnly): ?int
    {
        $key = $this->stepKey($title);
        foreach ($subs as $index => $sub) {
            if (!is_array($sub)) {
                continue;
            }
            $open = (string)($sub['status'] ?? '') !== 'done';
            if ($openOnly !== $open) {
                continue;
            }
            $have = trim((string)($sub['title'] ?? ''));
            if ($have === '') {
                continue;
            }
            if (strcasecmp($title, $have) === 0) {
                return $index;
            }
            $other = $this->stepKey($have);
            if ($key !== '' && $key === $other) {
                return $index;
            }
        }
        return null;
    }

    private function stepKey(string $title): string
    {
        $title = strtolower($title);
        $title = preg_replace('/[^a-z0-9]+/', ' ', $title) ?? $title;
        $drop = ['a', 'an', 'the', 'and', 'or', 'to', 'of', 'for', 'in', 'on', 'it', 'file'];
        $words = [];
        foreach (preg_split('/\s+/', trim($title)) ?: [] as $word) {
            if ($word === '' || in_array($word, $drop, true)) {
                continue;
            }
            $words[] = $word;
        }
        return implode(' ', $words);
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

    private function openStepTask(int $projectId, string $title): ?array
    {
        $needle = strtolower(trim(preg_replace('/\s+/', ' ', $title) ?? $title));
        if ($needle === '') {
            return null;
        }
        foreach ($this->db->tasks($projectId) as $task) {
            if ((string)($task['status'] ?? '') !== 'in_progress') {
                continue;
            }
            foreach ($task['subtasks'] ?? [] as $sub) {
                if (!is_array($sub) || (string)($sub['status'] ?? '') === 'done') {
                    continue;
                }
                $step = strtolower(trim(preg_replace('/\s+/', ' ', (string)($sub['title'] ?? '')) ?? ''));
                if ($step !== '' && $step === $needle) {
                    return $task;
                }
            }
        }
        return null;
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
            if ($existingSchedule !== $schedule && !($this->soonWindow($existingSchedule) && $this->soonWindow($schedule))) {
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

    private function researchSetup(array $args, ?array $existing): bool
    {
        if ($existing !== null && $this->researchCard($existing)) {
            return true;
        }
        $title = trim((string)($args['title'] ?? ''));
        if (preg_match('/^research\s*:/i', $title) === 1) {
            return true;
        }
        $blob = '';
        foreach (['steps', 'subtasks', 'description', 'instruction'] as $key) {
            if (!array_key_exists($key, $args)) {
                continue;
            }
            $blob .= is_string($args[$key]) ? $args[$key] : (json_encode($args[$key]) ?: '');
        }
        return preg_match('/research_loop/i', $blob) === 1;
    }

    private function researchCard(array $task): bool
    {
        if (preg_match('/^research\s*:/i', trim((string)($task['title'] ?? ''))) === 1) {
            return true;
        }
        foreach ($task['subtasks'] ?? [] as $sub) {
            if (!is_array($sub)) {
                continue;
            }
            $text = (string)($sub['title'] ?? '') . "\n" . (string)($sub['description'] ?? '');
            if (preg_match('/research_loop/i', $text) === 1) {
                return true;
            }
        }
        return false;
    }

    private function oneLine(string $value): string
    {
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
        return trim($value);
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

    private function soon(): string
    {
        return date('Y-m-d H:i:s', time() + self::RESTART_DELAY);
    }

    private function soonWindow(?string $schedule): bool
    {
        if ($schedule === null || $schedule === '') {
            return true;
        }
        $at = strtotime($schedule);
        if ($at === false) {
            return false;
        }
        $now = time();
        return $at >= $now - self::RESTART_DELAY && $at <= $now + self::RESTART_DELAY;
    }

    private function schedule(mixed $value, bool $strict = false): ?string
    {
        if ($value === null || $value === '') {
            return $this->soon();
        }
        $value = str_replace('T', ' ', trim((string)$value));
        $value = preg_replace('/\.\d+$/', '', $value) ?? $value;
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $value)) {
            $value .= ':00';
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value)) {
            if ($strict) {
                throw new InvalidArgumentException('Time must be a date and a clock time. Leave it empty to run in 2 minutes.');
            }
            return $this->soon();
        }
        return $value;
    }
}
