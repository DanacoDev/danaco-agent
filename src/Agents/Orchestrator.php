<?php

class Orchestrator
{
    private bool $taskIsolate = false;

    public function __construct(
        private AgentRuntime $runtime,
        private SubagentRunner $subagents,
        private Database $db,
        private MemoryManager $memory,
        private SkillEngine $skills
    ) {
    }

    public function handle(int $projectId, callable $emit, ?callable $shouldStop = null, bool $scheduled = false): string
    {
        $project = $projectId > 0 ? $this->db->project($projectId) : null;
        if ($projectId > 0 && !$project) {
            throw new RuntimeException('Project not found.');
        }
        $this->taskIsolate = $scheduled && $this->db->parallelWork();
        $agent = $this->taskIsolate ? 'scheduler' : 'orchestrator';
        try {
        $this->db->waitContextCompress($projectId, $shouldStop);
        if ($shouldStop && $shouldStop()) {
            throw new ChatStopped('Stopped.');
        }
        $packed = $this->prepare($projectId, $project, !$this->taskIsolate, $shouldStop, false, $emit);
        if (!empty($packed['updated'])) {
            $this->db->addMessage($projectId, 'notice', ContextWindow::NOTICE, 'orchestrator');
            $emit(['type' => 'notice', 'text' => ContextWindow::NOTICE]);
        }
        $emit(['type' => 'context', 'used' => $packed['used'], 'total' => $packed['total']]);
        $messages = $packed['messages'];
        if (!empty($packed['updated'])) {
            $this->db->loop($agent, 'context', count($messages) . ' msgs · summary updated', $projectId > 0 ? $projectId : null);
        }
        if ($this->endsWithCutOff($messages)) {
            $this->db->loop($agent, 'steer', 'cutoff · carried into this run', $projectId > 0 ? $projectId : null);
            $messages[] = ['role' => 'user', 'content' => 'Your last tool call was cut off before it finished. Repeat that same tool call and finish it before doing anything else.'];
        }
        return $this->runtime->run(
            $agent,
            $messages,
            $this->tools($project, $emit, $projectId, $shouldStop, $scheduled),
            $emit,
            $this->runtime->maxTurns(),
            $projectId > 0 ? $projectId : null,
            $shouldStop,
            false,
            $scheduled,
            fn(array $running): array => $this->betweenTurns($running, $project, $emit, $projectId, $shouldStop),
            fn(array $running): array => $this->repackForFailsafe($running, $project, $emit, $shouldStop)
        );
        } finally {
            $this->taskIsolate = false;
        }
    }

    public function contextUsage(int $projectId): array
    {
        $project = $projectId > 0 ? $this->db->project($projectId) : null;
        $packed = $this->prepare($projectId, $project, false, null);
        return ['used' => $packed['used'], 'total' => $packed['total']];
    }

    public function contextForDownload(int $projectId): array
    {
        $project = $projectId > 0 ? $this->db->project($projectId) : null;
        if ($projectId > 0 && !$project) {
            throw new RuntimeException('Project not found.');
        }
        $packed = $this->prepare($projectId, $project, true, null);
        return $packed['messages'];
    }

    public function compressContext(int $projectId): array
    {
        $project = $projectId > 0 ? $this->db->project($projectId) : null;
        if ($projectId > 0 && !$project) {
            throw new RuntimeException('Project not found.');
        }
        if ($this->db->hasRunningJob($projectId)) {
            $this->db->setSetting('context_compress_pending_' . max(0, $projectId), '1');
            $usage = $this->contextUsage($projectId);
            return [
                'used' => $usage['used'],
                'total' => $usage['total'],
                'compressed' => false,
                'deferred' => true,
            ];
        }
        $packed = $this->prepare($projectId, $project, true, null, true);
        if (!empty($packed['updated'])) {
            $this->db->addMessage($projectId, 'notice', ContextWindow::NOTICE, 'orchestrator');
        }
        return [
            'used' => $packed['used'],
            'total' => $packed['total'],
            'compressed' => !empty($packed['compressed']),
        ];
    }

    private function prepare(int $projectId, ?array $project, bool $writeSummary, ?callable $shouldStop, bool $activate = false, ?callable $emit = null): array
    {
        $history = [];
        foreach ($this->db->messages($projectId, 100) as $row) {
            if (!in_array($row['role'], ['user', 'assistant'], true)) {
                continue;
            }
            $id = (int)($row['id'] ?? 0);
            $history[] = [
                'id' => $id,
                'role' => $row['role'] === 'assistant' ? 'assistant' : 'user',
                'content' => $this->stamped($row),
            ];
            $status = $this->subagentStatus($row);
            if ($status !== '') {
                $history[] = [
                    'id' => $id,
                    'role' => 'user',
                    'content' => $status,
                ];
            }
        }
        if ($this->taskIsolate) {
            $history = $this->snapshotTaskHistory($history);
        } elseif ($this->db->parallelWork() && $this->db->taskRunning()) {
            $history = $this->withoutOpenTask($history);
        }
        $history = ContextWindow::stripLookups($history);
        $total = $this->contextTotal();
        $pendingKey = 'context_compress_pending_' . max(0, $projectId);
        $pending = $this->db->getSetting($pendingKey, '0') === '1';
        $manual = $activate || ($writeSummary && $pending);
        $cutOff = $this->endsWithCutOff($history);
        if ($cutOff) {
            $manual = false;
        }
        $folder = is_array($project) ? (string)$project['folder_name'] : null;
        $summary = $this->memory->readContextSummary($folder);
        $until = (int)$this->db->getSetting('context_summary_until_' . max(0, $projectId), '0');
        $live = [];
        foreach ($history as $message) {
            if (trim($summary) === '' || (int)($message['id'] ?? 0) > $until) {
                $live[] = $message;
            }
        }
        $system = $this->systemPrompt($project, $this->skills->usedNames($live));
        $measureSystem = $system;
        if (trim($summary) !== '') {
            $measureSystem .= "\n" . ContextWindow::earlierChatLine($summary);
        }
        $split = ContextWindow::split($measureSystem, $live, $total, $manual);
        if ($cutOff) {
            $keep = !empty($split['compressed']) ? $split['kept'] : $live;
            if ($this->skills->usedNames($keep) !== $this->skills->usedNames($live)) {
                $system = $this->systemPrompt($project, $this->skills->usedNames($keep));
            }
            $messages = [['role' => 'system', 'content' => $system]];
            foreach ($keep as $message) {
                $row = [
                    'role' => $message['role'],
                    'content' => $message['content'],
                ];
                $id = (int)($message['id'] ?? 0);
                if ($id > 0) {
                    $row['id'] = $id;
                }
                $messages[] = $row;
            }
            return [
                'messages' => $messages,
                'used' => $split['used'],
                'total' => $split['total'],
                'compressed' => false,
                'updated' => false,
            ];
        }
        if ($this->taskIsolate && !empty($split['compressed'])) {
            $kept = $this->keepDelegateTail($split['dropped'], $split['kept']);
            if ($this->skills->usedNames($kept) !== $this->skills->usedNames($live)) {
                $system = $this->systemPrompt($project, $this->skills->usedNames($kept));
            }
            $packed = ContextWindow::apply($system, $summary, $kept, $total, 0);
            $packed['compressed'] = false;
            $packed['updated'] = false;
            return $packed;
        }
        if ($split['compressed'] && $writeSummary) {
            $dropped = $split['dropped'];
            $kept = $split['kept'];
            if ($dropped !== [] && $kept !== []) {
                $tailId = (int)($dropped[count($dropped) - 1]['id'] ?? 0);
                $head = $kept[0];
                $headText = ltrim((string)($head['content'] ?? ''));
                if ($tailId > 0 && $tailId === (int)($head['id'] ?? 0) && str_starts_with($headText, 'TOOL RESULT delegate:')) {
                    $dropped[] = array_shift($kept);
                }
            }
            $fresh = [];
            foreach ($dropped as $message) {
                if ((int)($message['id'] ?? 0) > $until) {
                    $fresh[] = $message;
                }
            }
            if ($fresh) {
                if ($this->skills->usedNames($kept) !== $this->skills->usedNames($live)) {
                    $system = $this->systemPrompt($project, $this->skills->usedNames($kept));
                }
                if ($emit) {
                    $emit(['type' => 'context', 'used' => $split['used'], 'total' => $split['total']]);
                }
                $packed = $this->finishCompression($projectId, $folder, $system, $fresh, $kept, $summary, $total, $shouldStop, $emit);
                if ($packed !== null) {
                    $this->db->setSetting($pendingKey, '0');
                    return $packed;
                }
            }
        }
        if ($writeSummary && $pending) {
            $this->db->setSetting($pendingKey, '0');
            if ($emit) {
                $emit(['type' => 'phase', 'phase' => '', 'settled' => true]);
            }
        }
        if (trim($summary) !== '') {
            $packed = ContextWindow::apply($system, $summary, $live, $total, $split['used']);
            $packed['compressed'] = false;
            $packed['updated'] = false;
            return $packed;
        }
        $messages = [['role' => 'system', 'content' => $system]];
        foreach ($history as $message) {
            $row = [
                'role' => $message['role'],
                'content' => $message['content'],
            ];
            $id = (int)($message['id'] ?? 0);
            if ($id > 0) {
                $row['id'] = $id;
            }
            $messages[] = $row;
        }
        return [
            'messages' => $messages,
            'used' => $split['used'],
            'total' => $split['total'],
            'compressed' => false,
            'updated' => false,
        ];
    }

    private function betweenTurns(array $messages, ?array $project, callable $emit, int $projectId, ?callable $shouldStop): array
    {
        $before = count($messages);
        $pendingKey = 'context_compress_pending_' . max(0, $projectId);
        $pending = !$this->taskIsolate && $this->db->getSetting($pendingKey, '0') === '1';
        $last = $messages[count($messages) - 1] ?? null;
        if (is_array($last) && $this->toolWasCutOff((string)($last['content'] ?? ''))) {
            return $this->refreshSystem($messages, $project);
        }
        $previous = (string)($messages[0]['content'] ?? '');
        $rest = array_slice($messages, 1);
        $plain = 0;
        foreach ($rest as $message) {
            $plain += strlen((string)($message['content'] ?? ''));
        }
        $rest = $this->withoutStoredSummary(ContextWindow::stripLookups($rest));
        $stripped = 0;
        foreach ($rest as $message) {
            $stripped += strlen((string)($message['content'] ?? ''));
        }
        $bare = $this->systemPrompt($project, $this->skills->usedNames($rest));
        $system = $this->withSummaryNotice($bare, $project);
        $messages = array_merge([['role' => 'system', 'content' => $system]], $rest);
        $total = $this->contextTotal();
        $split = ContextWindow::split($system, $rest, $total, $pending);
        if (!$split['compressed']) {
            if ($pending) {
                $this->db->setSetting($pendingKey, '0');
                $emit(['type' => 'phase', 'phase' => '', 'settled' => true]);
            }
            if ($stripped < $plain || $system !== $previous) {
                $emit(['type' => 'context', 'used' => ContextWindow::measure($system, $rest), 'total' => $total]);
            }
            return $messages;
        }
        $kept = $split['kept'];
        if ($this->skills->usedNames($kept) !== $this->skills->usedNames($rest)) {
            $bare = $this->systemPrompt($project, $this->skills->usedNames($kept));
            $system = $this->withSummaryNotice($bare, $project);
            $messages[0]['content'] = $system;
        }
        if ($this->taskIsolate) {
            $kept = $this->keepDelegateTail($split['dropped'], $kept);
            $packed = $this->packKept($system, $kept);
            $emit(['type' => 'context', 'used' => ContextWindow::measure($system, $kept), 'total' => $total]);
            $after = count($packed);
            if ($after !== $before) {
                $this->db->loop('scheduler', 'context', $before . ' → ' . $after . ' msgs', $projectId > 0 ? $projectId : null);
            }
            return $packed;
        }
        $folder = is_array($project) ? (string)$project['folder_name'] : null;
        $packed = $this->finishCompression($projectId, $folder, $bare, $split['dropped'], $kept, $this->memory->readContextSummary($folder), $total, $shouldStop, $emit);
        if ($packed === null) {
            if ($pending) {
                $this->db->setSetting($pendingKey, '0');
                $emit(['type' => 'phase', 'phase' => '', 'settled' => true]);
            }
            return $messages;
        }
        $this->db->setSetting($pendingKey, '0');
        $emit(['type' => 'notice', 'text' => ContextWindow::NOTICE]);
        $emit(['type' => 'context', 'used' => $packed['used'], 'total' => $packed['total']]);
        $after = count($packed['messages']);
        if ($after !== $before) {
            $this->db->loop('orchestrator', 'context', $before . ' → ' . $after . ' msgs', $projectId > 0 ? $projectId : null);
        }
        return $packed['messages'];
    }

    private function repackForFailsafe(array $messages, ?array $project, callable $emit, ?callable $shouldStop): array
    {
        $before = count($messages);
        $rest = $this->withoutStoredSummary(ContextWindow::stripLookups(array_slice($messages, 1)));
        $bare = $this->systemPrompt($project, $this->skills->usedNames($rest));
        $system = $this->withSummaryNotice($bare, $project);
        $messages = array_merge([['role' => 'system', 'content' => $system]], $rest);
        $total = $this->contextTotal();
        $split = ContextWindow::fit($system, $rest, $total);
        if (empty($split['compressed'])) {
            $emit(['type' => 'context', 'used' => $split['used'], 'total' => $split['total']]);
            return $messages;
        }
        $kept = $split['kept'];
        if ($this->skills->usedNames($kept) !== $this->skills->usedNames($rest)) {
            $bare = $this->systemPrompt($project, $this->skills->usedNames($kept));
            $system = $this->withSummaryNotice($bare, $project);
            $messages[0]['content'] = $system;
        }
        if ($this->taskIsolate) {
            $packed = $this->packKept($messages[0]['content'], $kept);
            $emit(['type' => 'context', 'used' => ContextWindow::measure((string)$messages[0]['content'], $kept), 'total' => $total]);
            $after = count($packed);
            if ($after !== $before) {
                $this->db->loop('scheduler', 'context', 'failsafe · ' . $before . ' → ' . $after . ' msgs');
            }
            return $packed;
        }
        $folder = is_array($project) ? (string)$project['folder_name'] : null;
        $projectId = is_array($project) ? (int)($project['id'] ?? 0) : 0;
        $packed = $this->finishCompression($projectId, $folder, $bare, $split['dropped'], $kept, $this->memory->readContextSummary($folder), $total, $shouldStop, $emit);
        if ($packed === null) {
            $emit(['type' => 'context', 'used' => $split['used'], 'total' => $split['total']]);
            return $messages;
        }
        $emit(['type' => 'notice', 'text' => ContextWindow::NOTICE]);
        $emit(['type' => 'context', 'used' => $packed['used'], 'total' => $packed['total']]);
        $after = count($packed['messages']);
        if ($after !== $before) {
            $this->db->loop('orchestrator', 'context', 'failsafe · ' . $before . ' → ' . $after . ' msgs');
        }
        return $packed['messages'];
    }

    private function contextTotal(): int
    {
        $mode = (string)$this->db->getSetting('model_mode', 'cpu_failsafe');
        if (app_services()['llm']->usingFailsafe) {
            $mode = 'cpu_failsafe';
        }
        return app_services()['hardware']->effectiveContext($mode);
    }

    private function endsWithCutOff(array $messages): bool
    {
        for ($i = count($messages) - 1; $i >= 0; $i--) {
            if (!is_array($messages[$i])) {
                continue;
            }
            $content = ltrim((string)($messages[$i]['content'] ?? ''));
            if (str_starts_with($content, 'TOOL RESULT delegate:')) {
                continue;
            }
            return $this->toolWasCutOff($content);
        }
        return false;
    }

    private function toolWasCutOff(string $content): bool
    {
        $markers = ['<<tool>>', '<tool>>', '<<tool>', '<tool>', '[[tool]]', '[tool]]', '[[tool]', '[tool]', '<|tool|>'];
        $pos = false;
        foreach ($markers as $marker) {
            $at = strrpos($content, $marker);
            if ($at !== false && ($pos === false || $at > $pos)) {
                $pos = $at;
            }
        }
        if ($pos === false) {
            return false;
        }
        $rest = substr($content, $pos);
        foreach (['<<end>>', '<end>>', '<<end>', '<end>', '[[end]]', '[end]]', '[[end]', '[end]', '[[/tool]]', '[/tool]]', '[[/tool]', '[/tool]', '<</tool>>', '</tool>>', '<</tool>', '</tool>', '<|end|>'] as $end) {
            if (str_contains($rest, $end)) {
                return false;
            }
        }
        return true;
    }

    private function finishCompression(
        int $projectId,
        ?string $folder,
        string $system,
        array $dropped,
        array $kept,
        string $summary,
        int $total,
        ?callable $shouldStop,
        ?callable $emit
    ): ?array {
        $kept = ContextWindow::stripLookups($kept);
        $dropped = ContextWindow::stripLookups($dropped);
        $this->db->holdContextCompress($projectId);
        try {
            $summary = $this->summarize($summary, $dropped, $shouldStop, $emit);
            if (trim($summary) === '' && $dropped !== [] && ContextWindow::summarySource($dropped) === []) {
                $summary = 'Earlier listings were omitted.';
            }
            $summary = $this->memory->compactContextSummary($summary);
            if ($summary === '') {
                return null;
            }
            $packed = ContextWindow::apply($system, $summary, $kept, $total, 0);
            $limit = (int)floor(max(512, $total) * 0.60);
            $start = ContextWindow::tailStart($kept);
            if ((int)$packed['used'] > $limit && $start > 0 && $start < count($kept)) {
                $extra = array_slice($kept, 0, $start);
                $kept = array_slice($kept, $start);
                $folded = $this->summarize($summary, $extra, $shouldStop, null);
                if (trim($folded) !== '') {
                    $summary = $folded;
                }
                $dropped = array_merge($dropped, $extra);
                $packed = ContextWindow::apply($system, $summary, $kept, $total, 0);
            }
            $this->memory->writeContextSummary($folder, $summary);
            $lastId = 0;
            foreach ($dropped as $message) {
                $id = (int)($message['id'] ?? 0);
                if ($id > $lastId) {
                    $lastId = $id;
                }
            }
            if ($lastId > 0) {
                $this->db->setSetting('context_summary_until_' . max(0, $projectId), (string)$lastId);
            }
            $packed['updated'] = true;
            $packed['summary'] = $summary;
            if ($emit) {
                $emit(['type' => 'notice', 'text' => ContextWindow::NOTICE]);
                $emit(['type' => 'context', 'used' => $packed['used'], 'total' => $packed['total']]);
            }
            return $packed;
        } finally {
            $this->db->releaseContextCompress($projectId);
        }
    }

    private function summarize(string $previous, array $fresh, ?callable $shouldStop, ?callable $emit = null): string
    {
        if ($emit) {
            $emit(['type' => 'phase', 'phase' => 'compressing']);
        }
        $fresh = ContextWindow::summarySource($fresh);
        if (trim($previous) === '' && ContextWindow::isShort($fresh)) {
            return $this->memory->compactContextSummary($this->mechanicalSummary($fresh));
        }
        return $this->memory->compactContextSummary($this->summarizeForWindow($previous, ContextWindow::projectForSummary($fresh), $shouldStop, $this->contextTotal()));
    }

    private function mechanicalSummary(array $fresh): string
    {
        $log = [];
        foreach ($fresh as $message) {
            $text = trim((string)($message['content'] ?? ''));
            if ($text === '') {
                continue;
            }
            $who = ($message['role'] ?? '') === 'assistant' ? 'a' : 'u';
            $text = trim(preg_replace('/\s+/', ' ', $text) ?? '');
            if ($text === '') {
                continue;
            }
            $log[] = $who . ': ' . $text;
        }
        if ($log === []) {
            return '';
        }
        $json = json_encode(['log' => $log], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return is_string($json) ? $json : '';
    }

    private function summarizeForWindow(string $previous, array $fresh, ?callable $shouldStop, int $window): string
    {
        $piece = max(160, (int)floor(($window - 700) / 2));
        $chunks = [];
        $buffer = '';
        foreach ($fresh as $message) {
            $who = ($message['role'] ?? '') === 'assistant' ? 'Agent' : 'User';
            $line = $who . ': ' . clip_text(trim((string)($message['content'] ?? '')), 1500) . "\n\n";
            if (ContextWindow::tokens($line) > $piece) {
                $line = clip_text($line, max(200, $piece * 4));
            }
            if ($buffer !== '' && ContextWindow::tokens($buffer . $line) > $piece) {
                $chunks[] = $buffer;
                $buffer = '';
            }
            $buffer .= $line;
        }
        if ($buffer !== '') {
            $chunks[] = $buffer;
        }
        $previous = trim($previous);
        if ($chunks === []) {
            return $previous;
        }
        $budget = max(160, $window - 700);
        if (count($chunks) === 1) {
            if ($shouldStop && $shouldStop()) {
                throw new ChatStopped('Stopped.');
            }
            if ($previous !== '' && ContextWindow::tokens($previous) + ContextWindow::tokens($chunks[0]) > $budget) {
                $partial = trim($this->summarizeChunk('', $chunks[0], $shouldStop));
                return trim($this->summarizeChunk($previous, $partial, $shouldStop));
            }
            return trim($this->summarizeChunk($previous, $chunks[0], $shouldStop));
        }
        $partials = [];
        foreach ($chunks as $chunk) {
            if ($shouldStop && $shouldStop()) {
                throw new ChatStopped('Stopped.');
            }
            $partials[] = $this->summarizeChunk('', $chunk, $shouldStop);
        }
        $summary = $this->mergePartials($partials, $shouldStop, $piece);
        if ($previous === '') {
            return trim($summary);
        }
        if ($summary === '') {
            return $previous;
        }
        return trim($this->summarizeChunk($previous, $summary, $shouldStop));
    }

    private function mergePartials(array $partials, ?callable $shouldStop, int $piece): string
    {
        $clean = [];
        foreach ($partials as $part) {
            $part = trim((string)$part);
            if ($part !== '') {
                $clean[] = $part;
            }
        }
        if (!$clean) {
            return '';
        }
        if (count($clean) === 1) {
            return $clean[0];
        }
        $groups = [];
        $buffer = '';
        foreach ($clean as $part) {
            $line = $part . "\n\n";
            if ($buffer !== '' && ContextWindow::tokens($buffer . $line) > $piece) {
                $groups[] = $buffer;
                $buffer = '';
            }
            if (ContextWindow::tokens($line) > $piece) {
                $line = clip_text($line, max(200, $piece * 4));
            }
            $buffer .= $line;
        }
        if ($buffer !== '') {
            $groups[] = $buffer;
        }
        if (count($groups) === 1) {
            return trim($this->summarizeChunk('', $groups[0], $shouldStop));
        }
        $merged = [];
        foreach ($groups as $group) {
            if ($shouldStop && $shouldStop()) {
                throw new ChatStopped('Stopped.');
            }
            $merged[] = $this->summarizeChunk('', $group, $shouldStop);
        }
        if (count($merged) >= count($clean)) {
            return trim(clip_text(implode("\n\n", $merged), max(200, $piece * 4)));
        }
        return $this->mergePartials($merged, $shouldStop, $piece);
    }

    private function summarizeChunk(string $previous, string $chunk, ?callable $shouldStop): string
    {
        $instructions = <<<TXT
You compress older chat into one JSON object that replaces any previous summary.
Join the previous summary and the new messages into one current summary. If there is no previous summary, summarize the new messages only.
Keep names, dates, numbers, and paths that the new messages still rely on.
Drop a subject the new messages do not continue. This is the current thread, not a history of everything the chat once covered.
Do not add anything that is not in the previous summary or the new messages.
Do not call tools. Do not write a wiki file.
Reply with one line of JSON and nothing else.
Keys: decisions, results, facts, open. Each value is an array of short strings. Omit empty keys.
If the previous summary has a log array, fold anything still current into those keys and omit log.
TXT;
        $source = $chunk;
        if ($previous !== '') {
            $source = "Previous summary:\n" . $previous . "\n\nNew messages to fold in:\n" . $chunk;
        }
        try {
            $text = app_services()['llm']->stream([
                ['role' => 'system', 'content' => $instructions],
                ['role' => 'user', 'content' => $source],
            ], static function (): void {
            }, $shouldStop);
            $text = trim($text);
            if ($text !== '') {
                $compact = $this->memory->compactContextSummary($text);
                if ($compact !== '') {
                    return $compact;
                }
            }
        } catch (ChatStopped $e) {
            throw $e;
        } catch (Throwable $e) {
            $this->db->logAgent('orchestrator', null, 'error', 'Context summary failed: ' . $e->getMessage());
        }
        return $this->memory->compactContextSummary($this->failedSummary($previous, $chunk));
    }

    private function failedSummary(string $previous, string $chunk): string
    {
        $note = trim(preg_replace('/\s+/', ' ', clip_text($chunk, 400)) ?? '');
        $previous = trim($previous);
        if ($note !== '') {
            $note = 'note: ' . $note;
        }
        if ($previous === '') {
            if ($note === '') {
                return '';
            }
            $json = json_encode(['log' => [$note]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            return is_string($json) ? $json : '';
        }
        if ($note === '') {
            return $previous;
        }
        $decoded = json_decode($previous, true);
        if (!is_array($decoded) || array_is_list($decoded)) {
            $decoded = ['text' => trim(preg_replace('/\s+/', ' ', $previous) ?? '')];
        }
        $log = $decoded['log'] ?? [];
        if (!is_array($log) || !array_is_list($log)) {
            $log = is_string($log) && trim($log) !== '' ? [trim($log)] : [];
        }
        $log[] = $note;
        $decoded['log'] = $log;
        $json = json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return is_string($json) ? $json : $previous;
    }

    private function projectIndex(): string
    {
        $path = app_config()['data_dir'] . '/wiki/projects_index.md';
        if (!is_file($path)) {
            return '';
        }
        $text = trim((string)file_get_contents($path));
        if ($text === '') {
            return '';
        }
        return "Projects that already exist. This is a short index, not the full description of each project:\n" . clip_text($text, 4000);
    }

    private function refreshSystem(array $messages, ?array $project): array
    {
        if ($messages === []) {
            return $messages;
        }
        $tail = $this->withoutStoredSummary(array_slice($messages, 1));
        $bare = $this->systemPrompt($project, $this->skills->usedNames($tail));
        return array_merge([['role' => 'system', 'content' => $this->withSummaryNotice($bare, $project)]], $tail);
    }

    private function withSummaryNotice(string $system, ?array $project): string
    {
        $folder = is_array($project) ? (string)($project['folder_name'] ?? '') : '';
        $summary = $this->memory->readContextSummary($folder !== '' ? $folder : null);
        if ($summary === '') {
            return $system;
        }
        return $system . "\n" . ContextWindow::earlierChatLine($summary);
    }

    private function withoutStoredSummary(array $messages): array
    {
        $out = [];
        foreach ($messages as $message) {
            if (!is_array($message)) {
                $out[] = $message;
                continue;
            }
            $content = ltrim((string)($message['content'] ?? ''));
            if (str_starts_with($content, 'Summary of the earlier conversation:')) {
                continue;
            }
            $out[] = $message;
        }
        return $out;
    }

    private function systemPrompt(?array $project, array $expand = []): string
    {
        if ($project === null) {
            $context = <<<TXT
This is General chat. There is no project tag.
Answer the user directly. Do not invent a project tag.
The user creates projects manually from the menu. You cannot create one. If the user asks, tell them to do it from the menu.
Files the user attaches are stored under uploads/. Read an attached file before you answer about it. When you cite one, put its path alone on a line, such as uploads/photo.png. The chat adds a download button at the top right of that line. A png, jpg, gif, or webp on its own line is shown in the chat.
TXT;
        } else {
            $folder = $project['folder_name'];
            $rename = Projects::renameLine($project);
            if ($rename !== '') {
                $rename .= "\n";
            }
            $context = <<<TXT
Assigned project folder: {$folder}
Assigned project name: {$project['name']}
{$rename}Store project markdown only in {$folder}/wiki/. The description file is wiki/PROJECT.md. It holds only the general description: what the project is, contributors, credentials, and main goals. A research mission is one research_loop action mission. That call creates database/research-<slug>.db and the one task. Do not create the task. Other findings get their own markdown file in that folder. Other structured rows go in their own SQLite file in database/. wiki/NOTES.md is the doodle board. Small reminders and ideas for later go there with save_note, not in the description file. The task list has no backlog. An idea that is not scheduled does not get a task.
When you create or cite a file, put its path alone on a line, such as wiki/NOTES.md or report.pdf. The chat adds a download button at the top right of that line. A png, jpg, gif, or webp on its own line is shown in the chat.
A plan, a rule, a decision, or a conclusion is written in the same turn it is made. Read the matching wiki file, then update it with wiki_memory action write. Do this again when a later message changes it. Do not wait to be asked, and do not leave that record only in the chat. Do not copy the whole chat into the wiki. Update the file that already holds that topic, or start a new file for a new plan.
Tag every delegation exactly as project: {$folder}
TXT;
            $brief = Projects::promptContext((string)$folder, true);
            if ($brief !== '') {
                $context .= "\n" . $brief . "\n";
            }
            $index = $this->projectIndex();
            if ($index !== '') {
                $context .= "\n" . $index;
            }
        }
        $now = app_local_stamp();
        $state = $this->db->consultantState();
        if ($state['active']) {
            $consultNote = "The Consultant is on. Delegate to it with agent consultant only as a last resort. Each call costs the user money.\n";
        } elseif (($state['reason'] ?? '') === 'no_key') {
            $consultNote = "The Consultant exists, but you cannot call it until an API key is saved. It is a paid external model for a hard multi-step problem the team should not handle alone, and it can use the same project file tools. If that would help, tell the user that adding a key on the Setup page, under External LLM provider, and using that API for the Consultant, gives the agent superpowers on hard work. Do not describe the Consultant as missing.\n";
        } else {
            $consultNote = "The Consultant exists, but it is turned off, so you cannot call it. It is a paid external model for a hard multi-step problem, and it can use the same project file tools. If a task is too hard for the team, tell the user they can turn the Consultant on from the Activity page. It costs tokens while it is on. Do not describe the Consultant as missing.\n";
        }
        $team = $this->subagents->teamBrief($project ? (int)$project['id'] : 0);
        $full = $this->db->fullSystemControl();
        $controlNote = $full
            ? "Full system control is on. You may use sudo, and a path that starts with / can read or change any file on this computer. Prefer the assigned project when there is one. When this chat is not tied to a project, do not invent paths or settings. Guessing can hallucinate and can break the computer.\n"
            : '';
        $installNote = "Only you may install dependencies on this machine. The chat asks the user before an install runs. Subagents are stopped if they try. If one reports a missing install, run it yourself only after the user agrees.\n";
        $pdfNote = $project !== null
            ? "A PDF is not written in this chat. Delegate it to the writer with the file name and the brief. When the user names no design, tell the writer that every line stays black, including the title. Do not name a color. When the user names a font color, a size, or a picture, put that in the brief. A picture already in the project is named by its path. An illustration that still has to be drawn is make_graphic first, then that file in the PDF. The coder does not make PDFs.\n"
            : '';
        $rules = <<<TXT
{$context}
{$controlNote}{$installNote}Local time now: {$now}
That is the server clock. Use it for schedules and for any time you say. Do not name a timezone, such as CET or UTC, and do not convert the hour, unless the user asks.
Each message from the user starts with the local time it was sent, in brackets. Trust that time. Do not repeat the brackets in your reply.

{$team}

{$consultNote}When you need a tool, emit exactly this and stop so the system can run it. Put nothing after <<end>> in the same turn:
<<tool>>
{"name":"filesystem","args":{"action":"list"}}
<<end>>
Other correct examples:
<<tool>>
{"name":"wiki_memory","args":{"action":"read"}}
<<end>>
<<tool>>
{"name":"tasklist","args":{"action":"list"}}
<<end>>
Never invent another call format. These do nothing:
: filesystem
: wiki/NOTES.md
filesystem
<tasklist>...</tasklist>
<delegate><agent>writer</agent></delegate>
Naming a tool in a list or sentence does nothing. Only the <<tool>> ... <<end>> block runs it.
Do not print a tool call in the reply. Do not write a tool name followed by a JSON object. Draft XML such as <delegate> is thinking, not something the user should read.
Do not say you wrote, updated, or created a file until a tool result confirms it. Showing the planned markdown in chat is not a write. Call wiki_memory with action write first, then answer. A project file is written by a subagent with filesystem action write.

{$pdfNote}These are the skills at your disposal. delegate, tasklist, and wiki_memory are written out in full. Each other line is the description and the arguments. After another skill is called, its instructions are added here and stay while that call is still in this chat.
TXT;
        $projectId = $project ? (int)$project['id'] : 0;
        $omit = ['create_pdf', 'run_command', 'run_python'];
        if ($projectId <= 0 || !$this->db->emailAccounts($projectId)) {
            $omit[] = 'send_email';
        }
        if ($projectId <= 0 || !$this->db->ftpAccounts($projectId)) {
            $omit[] = 'ftp';
        }
        $expand = array_values(array_unique(array_merge(['delegate', 'tasklist', 'wiki_memory'], $expand)));
        $catalog = $this->skills->promptCatalog($omit, [
            'web_search' => $this->researchToolLine('web_search'),
            'fetch_url' => $this->researchToolLine('fetch_url'),
            'browse_url' => $this->researchToolLine('browse_url'),
            'yahoo_finance' => $this->researchToolLine('yahoo_finance'),
        ], $expand);
        $lines = [];
        $sawEmail = false;
        foreach ((new CapabilityTools($this->db))->descriptions($projectId) as $line) {
            if (!preg_match('/^- (send_email|ftp|mcp):/', $line, $match) || in_array($match[1], $omit, true)) {
                continue;
            }
            $lines[] = $line;
            if ($match[1] === 'send_email') {
                $sawEmail = true;
            }
        }
        if (!$sawEmail) {
            $lines[] = '- send_email: this chat has no mailbox. Say that a mailbox is added on the Setup page, under Email, and linked to this project. There is no Tools tab. Do not describe it as a missing capability.';
        }
        $lines[] = '- py_install: args packages. Installs a Python library into the open project, such as yfinance. The chat asks the user first. Call this only after run_python says that library is missing. It is saved inside the project. Then delegate the script again.';
        $tail = Prompts::ORCHESTRATOR . "\n\n" . $rules . "\n" . $catalog;
        if ($lines !== []) {
            $tail .= "\n\n" . implode("\n\n", $lines);
        }
        $notes = $this->memory->toolNotesForPrompt();
        if ($notes !== '') {
            $tail .= "\n\n" . $notes;
        }
        if ($project !== null) {
            if ($this->db->getSetting('reread_desc_' . (int)$project['id'], '0') === '1') {
                $desc = 'wiki/' . Projects::descFile((string)$project['folder_name']);
                $tail .= "\n\nThe chat was just cleared. Before you answer or do other work, read {$desc} so you know what this project is. Use wiki_memory with action read and no name.\n";
            }
        }
        if ($this->taskIsolate) {
            $tail .= "\n\nYou are the task manager for this scheduled run. Nobody can answer you here. Do not ask a question. Do not ask the user to approve, choose, or confirm. If hypotheses are proposed and waiting, list each one as a statement and stop. End with the line awaiting approval. The user approves later in the chat with research_loop action review. Do not call review yourself.\n";
        }
        return $tail;
    }

    private function clearDescReread(int $projectId, string $folder, string $path): void
    {
        if ($projectId <= 0 || $folder === '') {
            return;
        }
        $path = ltrim(str_replace('\\', '/', trim($path)), '/');
        if ($path !== 'wiki/' . Projects::descFile($folder)) {
            return;
        }
        $this->db->setSetting('reread_desc_' . $projectId, '0');
    }

    private function keepDelegateTail(array $dropped, array $kept): array
    {
        if ($dropped === [] || $kept === []) {
            return $kept;
        }
        $tailId = (int)($dropped[count($dropped) - 1]['id'] ?? 0);
        $head = $kept[0];
        $headText = ltrim((string)($head['content'] ?? ''));
        if ($tailId > 0 && $tailId === (int)($head['id'] ?? 0) && str_starts_with($headText, 'TOOL RESULT delegate:')) {
            array_shift($kept);
        }
        return $kept;
    }

    private function packKept(string $system, array $kept): array
    {
        $messages = [['role' => 'system', 'content' => $system]];
        foreach ($kept as $message) {
            if (!is_array($message)) {
                continue;
            }
            $row = [
                'role' => (string)($message['role'] ?? 'user'),
                'content' => (string)($message['content'] ?? ''),
            ];
            $id = (int)($message['id'] ?? 0);
            if ($id > 0) {
                $row['id'] = $id;
            }
            $messages[] = $row;
        }
        return $messages;
    }

    private function snapshotTaskHistory(array $history): array
    {
        $cut = 0;
        foreach ($history as $message) {
            if (($message['role'] ?? '') !== 'user') {
                continue;
            }
            $text = ltrim((string)($message['content'] ?? ''));
            if (!str_starts_with($text, 'Scheduled task:')) {
                continue;
            }
            $id = (int)($message['id'] ?? 0);
            if ($id > $cut) {
                $cut = $id;
            }
        }
        if ($cut <= 0) {
            return $history;
        }
        $kept = [];
        foreach ($history as $message) {
            if ((int)($message['id'] ?? 0) > $cut) {
                continue;
            }
            $kept[] = $message;
        }
        return $kept;
    }

    private function withoutOpenTask(array $history): array
    {
        $open = [];
        $count = count($history);
        for ($i = 0; $i < $count; $i++) {
            $message = $history[$i];
            if (($message['role'] ?? '') !== 'user') {
                continue;
            }
            $text = ltrim((string)($message['content'] ?? ''));
            if (!str_starts_with($text, 'Scheduled task:')) {
                continue;
            }
            $id = (int)($message['id'] ?? 0);
            $answered = false;
            for ($j = $i + 1; $j < $count; $j++) {
                if ((int)($history[$j]['id'] ?? 0) <= $id) {
                    continue;
                }
                if (($history[$j]['role'] ?? '') === 'assistant') {
                    $answered = true;
                    break;
                }
            }
            if (!$answered && $id > 0) {
                $open[$id] = true;
            }
        }
        if ($open === []) {
            return $history;
        }
        $kept = [];
        foreach ($history as $message) {
            $id = (int)($message['id'] ?? 0);
            $text = ltrim((string)($message['content'] ?? ''));
            if ($id > 0 && isset($open[$id]) && ($message['role'] ?? '') === 'user' && str_starts_with($text, 'Scheduled task:')) {
                continue;
            }
            $kept[] = $message;
        }
        return $kept;
    }

    private function stamped(array $row): string
    {
        $content = (string)($row['content'] ?? '');
        if (($row['role'] ?? '') === 'assistant') {
            $content = strip_tool_result_leak(strip_tool_markers(strip_chat_tags($content)));
        }
        $content = clip_text($content, 8000);
        if (($row['role'] ?? '') !== 'user' || str_starts_with($content, 'Scheduled task:')) {
            return $content;
        }
        return '[' . app_local_stamp((string)($row['created_at'] ?? '')) . "]\n" . $content;
    }

    private function subagentStatus(array $row): string
    {
        if (($row['role'] ?? '') !== 'assistant' || !is_array($row['detail'] ?? null)) {
            return '';
        }
        $blocks = [];
        foreach ($row['detail']['tools'] ?? [] as $tool) {
            if (!is_array($tool) || ($tool['name'] ?? '') !== 'delegate') {
                continue;
            }
            $result = trim((string)($tool['result'] ?? ''));
            if ($result === '') {
                continue;
            }
            $args = is_array($tool['args'] ?? null) ? $tool['args'] : [];
            $agent = trim((string)($args['agent'] ?? ''));
            if ($agent === '') {
                $agent = 'subagent';
            }
            $instruction = $this->oneLine((string)($args['instruction'] ?? ''));
            $line = $agent;
            if ($instruction !== '') {
                $line .= ': ' . $instruction;
            }
            $blocks[] = $line . "\n" . $this->shortResult($result);
        }
        if ($blocks === []) {
            return '';
        }
        return "TOOL RESULT delegate:\n" . implode("\n\n", $blocks);
    }

    private function oneLine(string $text, int $max = 200): string
    {
        $text = trim(preg_replace('/\s+/', ' ', $text) ?? '');
        if ($text === '' || strlen($text) <= $max) {
            return $text;
        }
        return rtrim(substr($text, 0, $max - 1)) . '…';
    }

    private function shortResult(string $text): string
    {
        $text = trim($text);
        if (strlen($text) <= 900) {
            return $text;
        }
        $mark = "\n…\n";
        $keep = 900 - strlen($mark);
        $head = intdiv($keep, 2);
        $tail = $keep - $head;
        return substr($text, 0, $head) . $mark . substr($text, -$tail);
    }

    private function confirmInstall(string $command, callable $emit, ?callable $shouldStop, ?int $projectId = null): bool
    {
        return $this->confirmAction('install', 'Install: ' . $command, $emit, $shouldStop, $projectId, ['command' => $command]);
    }

    private function confirmAction(string $kind, string $summary, callable $emit, ?callable $shouldStop, ?int $projectId = null, array $extra = []): bool
    {
        if (PHP_SAPI === 'cli') {
            return false;
        }
        $id = $this->db->beginActionAsk($kind, $summary, $projectId);
        $payload = array_merge(['type' => 'action_prompt', 'id' => $id, 'kind' => $kind, 'summary' => $summary], $extra);
        if ($kind === 'install' && isset($extra['command'])) {
            $payload['command'] = $extra['command'];
        }
        $emit($payload);
        $deadline = time() + 180;
        try {
            while (time() < $deadline) {
                if ($shouldStop && $shouldStop()) {
                    return false;
                }
                $answer = (string)$this->db->getSetting('action_ask_' . $id, '');
                if ($answer === 'yes') {
                    return true;
                }
                if ($answer === 'no') {
                    return false;
                }
                usleep(300000);
            }
            return false;
        } finally {
            $this->db->clearActionAsk($id);
        }
    }

    private function gateExternal(string $kind, string $summary, callable $run, callable $emit, ?callable $shouldStop, ?int $projectId = null): string
    {
        if ($this->db->hitlExternalActions()) {
            $scope = $projectId && $projectId > 0 ? $projectId : null;
            if (PHP_SAPI === 'cli') {
                $this->db->loop('orchestrator', 'process', 'approval · ' . $kind . ' · scheduled cannot ask · ' . $summary, $scope);
                return 'This action needs human approval, but a scheduled run cannot ask. Turn off extra approval gates, or run it from chat.';
            }
            $this->db->loop('orchestrator', 'process', 'approval · ' . $kind . ' · needs approval · ' . $summary, $scope);
            if (!$this->confirmAction($kind, $summary, $emit, $shouldStop, $projectId)) {
                $this->db->loop('orchestrator', 'process', 'approval · ' . $kind . ' · denied', $scope);
                return 'The user did not allow this action.';
            }
            $this->db->loop('orchestrator', 'process', 'approval · ' . $kind . ' · allowed', $scope);
        }
        $result = $run();
        $this->db->logCompliance('tool_' . $kind, 'agent', $summary, ['result' => clip_text($result, 500)], $projectId);
        return $result;
    }

    private function acceptsProjectTag(string $tagged, string $folder, string $name, string $former, int $projectId): bool
    {
        if ($tagged === '' || strcasecmp($tagged, $folder) === 0) {
            return true;
        }
        foreach ([$name, $former] as $label) {
            if ($label !== '' && strcasecmp($tagged, $label) === 0) {
                return true;
            }
        }
        try {
            if ($name !== '' && strcasecmp($tagged, Projects::folderFromName($name)) === 0) {
                return true;
            }
            if ($former !== '' && strcasecmp($tagged, Projects::folderFromName($former)) === 0) {
                return true;
            }
        } catch (InvalidArgumentException $e) {
        }
        $other = $this->db->projectByFolder(strtolower($tagged));
        return !$other || (int)$other['id'] === $projectId;
    }

    private function tools(?array $project, callable $emit, int $projectId, ?callable $shouldStop, bool $scheduled = false): array
    {
        $folder = is_array($project) ? (string)$project['folder_name'] : '';
        $projectName = is_array($project) ? trim((string)($project['name'] ?? '')) : '';
        $formerName = is_array($project) ? trim((string)($project['former_name'] ?? '')) : '';
        $shownProject = $projectName !== '' ? $projectName : $folder;
        $createdTaskIds = [];
        $files = function (array $args) use ($folder, $projectId): string {
            $action = strtolower(trim((string)($args['action'] ?? '')));
            if (in_array($action, ['write', 'delete', 'move', 'copy'], true)) {
                return 'Only a subagent changes files. Delegate it. The main agent uses filesystem with action list or read.';
            }
            if ($action !== 'list' && $action !== 'read') {
                return 'filesystem needs action list or read here. A subagent uses write, delete, move, or copy.';
            }
            $path = (string)($args['path'] ?? '');
            if ($action === 'list' && $folder === '' && trim(str_replace('\\', '/', $path)) === '') {
                $path = 'uploads';
            }
            $useFolder = $this->uploadsFolder($folder, $path);
            if ($action === 'list') {
                if ($this->generalUploadsMissing($useFolder)) {
                    return '[]';
                }
                $blocked = $this->needsProject($useFolder, $path, 'Open a project before listing its files.');
                if ($blocked !== null) {
                    return $blocked;
                }
                $tree = (new SafetyInterceptor(app_config()['data_dir']))->listFiles($useFolder, $path);
                return json_encode($tree, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]';
            }
            if ($this->generalUploadsMissing($useFolder)) {
                return 'File not found.';
            }
            $blocked = $this->needsProject($useFolder, $path, 'Open a project before reading its files.');
            if ($blocked !== null) {
                return $blocked;
            }
            $this->clearDescReread($projectId, $useFolder, $path);
            return (new SafetyInterceptor(app_config()['data_dir']))->readFile($useFolder, $path);
        };
        $tools = [
            'delegate' => function (array $args) use ($folder, $projectName, $formerName, $shownProject, $emit, $projectId, $shouldStop, $scheduled, &$createdTaskIds): string {
                $agent = strtolower(trim((string)($args['agent'] ?? '')));
                $instruction = trim((string)($args['instruction'] ?? ''));
                if ($agent === 'consultant') {
                    if (!$this->db->consultantActive()) {
                        $reason = (string)($this->db->consultantState()['reason'] ?? '');
                        if ($reason === 'no_key') {
                            $this->db->loop('orchestrator', 'process', 'Delegate rejected · consultant has no key');
                            return 'The Consultant has no API key. Tell the user to add one on the Setup page, under External LLM provider, and use that API for the Consultant.';
                        }
                        $this->db->loop('orchestrator', 'process', 'Delegate rejected · consultant turned off');
                        return 'The Consultant is turned off. Tell the user they can turn it on from the Activity page. It costs tokens while it is on.';
                    }
                    if ($instruction === '') {
                        $this->db->loop('orchestrator', 'process', 'Delegate rejected · no instruction');
                        return 'The Consultant needs an assignment.';
                    }
                    if ($folder !== '') {
                        $tagged = trim((string)($args['project'] ?? $folder));
                        if (!$this->acceptsProjectTag($tagged, $folder, $projectName, $formerName, $projectId)) {
                            $this->db->loop('orchestrator', 'process', 'Delegate rejected · project tag');
                            return 'Delegation rejected. The project tag must be project: ' . $folder;
                        }
                    }
                    $note = '';
                    if ($folder !== '') {
                        try {
                            $note = $this->startDelegatedTask($projectId, $agent, $instruction, $args, $createdTaskIds);
                        } catch (InvalidArgumentException $e) {
                            $this->db->loop('orchestrator', 'process', 'Delegate rejected · no steps');
                            return $e->getMessage();
                        } catch (Throwable $e) {
                            $note = '';
                        }
                        if ($note === '') {
                            $this->db->loop('orchestrator', 'process', 'Delegate rejected · no task');
                            return 'Delegation rejected. The task is not on the list. Create it with tasklist action create, list the tasks and read the new id. If the list says scheduled, do not delegate. The scheduler starts it. Do not invent an id.';
                        }
                    }
                    $wait = $this->humanStepWait($note);
                    if ($wait !== '') {
                        $this->db->loop('orchestrator', 'process', 'Delegate waiting · human step');
                        return $wait;
                    }
                    $runInstruction = $this->currentStepInstruction($note, $instruction);
                    $emit(['type' => 'delegation', 'agent' => 'consultant', 'project' => $shownProject !== '' ? $shownProject : 'general', 'text' => $instruction]);
                    try {
                        return $this->subagents->consult($folder, $runInstruction, $emit, $projectId, $shouldStop, $scheduled) . $this->freshTaskNote($note);
                    } catch (ChatStopped $e) {
                        throw $e;
                    } catch (Throwable $e) {
                        $notice = agent_error('consultant', $e->getMessage());
                        $emit(['type' => 'token', 'agent' => $agent, 'text' => $notice]);
                        return $notice . $note;
                    }
                }
                if ($folder === '') {
                    $this->db->loop('orchestrator', 'process', 'Delegate rejected · no project');
                    return 'General chat has no project. Tell the user to create a project manually from the menu before delegating.';
                }
                $tagged = trim((string)($args['project'] ?? $folder));
                if (!$this->acceptsProjectTag($tagged, $folder, $projectName, $formerName, $projectId)) {
                    $this->db->loop('orchestrator', 'process', 'Delegate rejected · project tag');
                    return 'Delegation rejected. The project tag must be project: ' . $folder;
                }
                if (!in_array($agent, ['researcher', 'writer', 'coder', 'analyst', 'marketer'], true)) {
                    $reported = trim((string)($args['result'] ?? ''));
                    if ($reported !== '') {
                        $this->db->loop('orchestrator', 'process', 'Delegate rejected · result is not a delegation');
                        return "Delegation was not started. delegate needs agent and instruction. id and result do not start a subagent. Do not call delegate again. Tell the user this and stop:\n" . $reported;
                    }
                    $this->db->loop('orchestrator', 'process', 'Delegate rejected · unknown agent');
                    return 'Unknown subagent.';
                }
                if ($instruction === '') {
                    $this->db->loop('orchestrator', 'process', 'Delegate rejected · no instruction');
                    return 'The subagent needs an instruction.';
                }
                $note = '';
                try {
                    $note = $this->startDelegatedTask($projectId, $agent, $instruction, $args, $createdTaskIds);
                } catch (InvalidArgumentException $e) {
                    $this->db->loop('orchestrator', 'process', 'Delegate rejected · no steps');
                    return $e->getMessage();
                } catch (Throwable $e) {
                    $note = '';
                }
                if ($note === '') {
                    $this->db->loop('orchestrator', 'process', 'Delegate rejected · no task');
                    return 'Delegation rejected. The task is not on the list. Create it with tasklist action create, list the tasks and read the new id. If the list says scheduled, do not delegate. The scheduler starts it. Do not invent an id.';
                }
                $wait = $this->humanStepWait($note);
                if ($wait !== '') {
                    $this->db->loop('orchestrator', 'process', 'Delegate waiting · human step');
                    return $wait;
                }
                $runInstruction = $this->currentStepInstruction($note, $instruction);
                $emit(['type' => 'delegation', 'agent' => $agent, 'project' => $shownProject, 'text' => $instruction]);
                $jobId = $this->db->startJob('subagent', $projectId > 0 ? $projectId : null, [
                    'agent' => $agent,
                    'folder' => $folder,
                    'instruction' => $runInstruction,
                    'scheduled' => $scheduled,
                ]);
                $this->db->loopPush($jobId, $projectId > 0 ? $projectId : null);
                $watch = function () use ($jobId, $projectId): bool {
                    $this->db->touchJob($jobId);
                    return $this->db->runStopRequested($projectId) || !$this->db->jobIsRunning($jobId);
                };
                $leave = false;
                $spoken = '';
                try {
                    $this->db->loop($agent, 'process', 'Subagent job ' . $jobId . ' started');
                    try {
                        $result = $this->subagents->run($agent, $folder, $runInstruction, $emit, $projectId, $watch, $scheduled);
                        $this->db->loop($agent, 'process', 'Subagent job ' . $jobId . ' finished');
                        $this->db->finishJob($jobId);
                        $spoken = $result . $this->freshTaskNote($note);
                        if ($shouldStop && $shouldStop()) {
                            $leave = true;
                        } else {
                            return $spoken;
                        }
                    } catch (ChatStopped $e) {
                        $this->db->loop($agent, 'process', 'Subagent job ' . $jobId . ' stopped');
                        $this->db->finishJob($jobId);
                        $this->db->acknowledgeRunStop($projectId, $jobId);
                        throw $e;
                    } catch (RuntimeException $e) {
                        if ($e->getMessage() === 'Only one subagent can run at a time.') {
                            $this->db->loop($agent, 'process', 'Subagent job ' . $jobId . ' closed · one already running');
                            $this->db->finishJob($jobId);
                            return 'Only one subagent can run at a time. Wait for the current one to finish, then delegate the next task.' . $note;
                        }
                        $this->db->failJob($jobId, $e->getMessage(), Heartbeat::MAX_ATTEMPTS);
                        $this->db->loop($agent, 'process', 'Subagent job ' . $jobId . ' failed · ' . $e->getMessage());
                        $notice = agent_error($agent, $e->getMessage());
                        $emit(['type' => 'token', 'agent' => $agent, 'text' => $notice]);
                        return $notice . $note;
                    } catch (Throwable $e) {
                        $this->db->failJob($jobId, $e->getMessage(), Heartbeat::MAX_ATTEMPTS);
                        $this->db->loop($agent, 'process', 'Subagent job ' . $jobId . ' failed · ' . $e->getMessage());
                        $notice = agent_error($agent, $e->getMessage());
                        $emit(['type' => 'token', 'agent' => $agent, 'text' => $notice]);
                        return $notice . $note;
                    }
                } finally {
                    $this->db->loopPop();
                }
                if ($leave) {
                    $text = trim($spoken);
                    if ($text !== '') {
                        $emit(['type' => 'token', 'agent' => 'orchestrator', 'text' => $text]);
                    }
                    throw new ChatStopped('Stopped.');
                }
            },
            'tasklist' => function (array $args) use ($projectId, &$createdTaskIds): string {
                return $this->kanban($projectId, $args, $createdTaskIds);
            },
            'task_breakdown' => function (array $args) use ($projectId, &$createdTaskIds): string {
                $args['action'] = 'create';
                if (!array_key_exists('tasks', $args)) {
                    $title = trim((string)($args['title'] ?? ''));
                    if ($title !== '') {
                        $args['tasks'] = [['title' => $title, 'agent' => $args['agent'] ?? $args['assigned_agent'] ?? 'orchestrator']];
                    }
                }
                return $this->kanban($projectId, $args, $createdTaskIds);
            },
            'kanban' => function (array $args) use ($projectId, &$createdTaskIds): string {
                return $this->kanban($projectId, $args, $createdTaskIds);
            },
            'create_task' => function (array $args) use ($projectId, &$createdTaskIds): string {
                $args['action'] = 'create';
                return $this->kanban($projectId, $args, $createdTaskIds);
            },
            'list_tasks' => function () use ($projectId): string {
                return $this->kanban($projectId, ['action' => 'list']);
            },
            'filesystem' => $files,
            'list_files' => function (array $args) use ($files): string {
                $args['action'] = 'list';
                return $files($args);
            },
            'read_file' => function (array $args) use ($files): string {
                $args['action'] = 'read';
                return $files($args);
            },
            'write_file' => function (array $args) use ($files): string {
                $args['action'] = 'write';
                return $files($args);
            },
            'delete_file' => function (array $args) use ($files): string {
                $args['action'] = 'delete';
                return $files($args);
            },
            'move_file' => function (array $args) use ($files): string {
                $copy = $args['copy'] ?? false;
                $args['action'] = ($copy === true || $copy === 1 || in_array(strtolower(trim((string)$copy)), ['1', 'true', 'yes'], true)) ? 'copy' : 'move';
                return $files($args);
            },
            'read_pdf' => function (array $args) use ($folder, $shouldStop): string {
                $path = (string)($args['path'] ?? '');
                $folder = $this->uploadsFolder($folder, $path);
                if ($this->generalUploadsMissing($folder)) {
                    return 'File not found.';
                }
                $blocked = $this->needsProject($folder, $path, 'Open a project before reading a PDF.');
                if ($blocked !== null) {
                    return $blocked;
                }
                return PdfFile::readProject(new SafetyInterceptor(app_config()['data_dir']), $folder, $path, (string)($args['question'] ?? ''), $shouldStop);
            },
            'read_image' => function (array $args) use ($folder, $shouldStop): string {
                $path = (string)($args['path'] ?? '');
                $folder = $this->uploadsFolder($folder, $path);
                if ($this->generalUploadsMissing($folder)) {
                    return 'File not found.';
                }
                $blocked = $this->needsProject($folder, $path, 'Open a project before looking at an image.');
                if ($blocked !== null) {
                    return $blocked;
                }
                return Sight::look(new SafetyInterceptor(app_config()['data_dir']), $folder, $path, (string)($args['question'] ?? ''), $shouldStop);
            },
            'screenshot' => function (array $args) use ($folder, $shouldStop): string {
                if ($folder === '') {
                    return 'Open a project before taking a screenshot.';
                }
                return Sight::shot(new SafetyInterceptor(app_config()['data_dir']), $folder, $args, $shouldStop);
            },
            'create_pdf' => function (array $args) use ($folder): string {
                if ($folder === '') {
                    return 'Open a project before creating a PDF.';
                }
                return 'Do not write the PDF in this chat. Delegate it to the writer with the file name and the brief. When the user names no design, say that every line stays black. When the user names a font color, a size, or a picture, put that in the brief. The writer calls create_pdf in its own context, so a long document does not fill this chat. The coder is for software, not for PDFs.';
            },
            'create_project' => function (array $args): string {
                return 'You cannot create a project. Tell the user to create it manually from the menu.';
            },
            'wiki_memory' => function (array $args) use ($folder, $projectId): string {
                $action = strtolower(trim((string)($args['action'] ?? '')));
                if ($action === 'search') {
                    return $this->memory->search((string)($args['query'] ?? ''), $folder !== '' ? $folder : null);
                }
                if ($action === 'read') {
                    if ($folder === '') {
                        return 'General chat has no project wiki.';
                    }
                    try {
                        $relative = Projects::wikiRelative($folder, (string)($args['name'] ?? ''));
                    } catch (InvalidArgumentException $e) {
                        return $e->getMessage();
                    }
                    $this->clearDescReread($projectId, $folder, $relative);
                    try {
                        return (new SafetyInterceptor(app_config()['data_dir']))->readFile($folder, $relative, 100000);
                    } catch (Throwable $e) {
                        return 'That wiki file does not exist yet.';
                    }
                }
                if ($action === 'write') {
                    if ($folder === '') {
                        return 'General chat has no project wiki. Open a project before writing project notes.';
                    }
                    try {
                        $relative = Projects::wikiRelativeFromArgs($folder, $args);
                    } catch (InvalidArgumentException $e) {
                        return $e->getMessage();
                    }
                    $content = (string)($args['content'] ?? '');
                    $safety = new SafetyInterceptor(app_config()['data_dir']);
                    if (!empty($args['append'])) {
                        try {
                            $existing = $safety->readFile($folder, $relative, 100000);
                        } catch (Throwable $e) {
                            $existing = '';
                        }
                        $content = rtrim($existing) . "\n\n" . $content;
                    }
                    $written = $safety->writeFile($folder, $relative, $content);
                    return Projects::wikiSavedLine($args, $written['path']);
                }
                if ($action === 'reindex') {
                    return $this->memory->update();
                }
                return 'wiki_memory needs action search, read, write, or reindex.';
            },
            'wiki_search' => function (array $args) use ($folder): string {
                return $this->memory->search((string)($args['query'] ?? ''), $folder !== '' ? $folder : null);
            },
            'wiki_read' => function (array $args) use ($folder, $projectId): string {
                if ($folder === '') {
                    return 'General chat has no project wiki.';
                }
                try {
                    $relative = Projects::wikiRelative($folder, (string)($args['name'] ?? ''));
                } catch (InvalidArgumentException $e) {
                    return $e->getMessage();
                }
                $this->clearDescReread($projectId, $folder, $relative);
                try {
                    return (new SafetyInterceptor(app_config()['data_dir']))->readFile($folder, $relative, 100000);
                } catch (Throwable $e) {
                    return 'That wiki file does not exist yet.';
                }
            },
            'wiki_write' => function (array $args) use ($folder): string {
                if ($folder === '') {
                    return 'General chat has no project wiki. Open a project before writing project notes.';
                }
                try {
                    $relative = Projects::wikiRelativeFromArgs($folder, $args);
                } catch (InvalidArgumentException $e) {
                    return $e->getMessage();
                }
                $content = (string)($args['content'] ?? '');
                $safety = new SafetyInterceptor(app_config()['data_dir']);
                if (!empty($args['append'])) {
                    try {
                        $existing = $safety->readFile($folder, $relative, 100000);
                    } catch (Throwable $e) {
                        $existing = '';
                    }
                    $content = rtrim($existing) . "\n\n" . $content;
                }
                $written = $safety->writeFile($folder, $relative, $content);
                return Projects::wikiSavedLine($args, $written['path']);
            },
            'wiki_reindex' => function (): string {
                return $this->memory->update();
            },
            'list_vault' => function (): string {
                return (new SafetyInterceptor(app_config()['data_dir']))->vaultListing();
            },
            'read_vault' => function (array $args): string {
                return (new SafetyInterceptor(app_config()['data_dir']))->readVault((string)($args['kind'] ?? ''), (string)($args['stored_as'] ?? ''));
            },
            'restore_file' => function (array $args): string {
                $result = (new SafetyInterceptor(app_config()['data_dir']))->restore((string)($args['kind'] ?? ''), (string)($args['stored_as'] ?? ''));
                return 'Restored ' . $result['restored'] . ' into ' . $result['project_folder'] . '.';
            },
            'write_skill' => function (array $args): string {
                try {
                    return $this->skills->register($args, 'orchestrator');
                } catch (InvalidArgumentException | RuntimeException $e) {
                    return $e->getMessage();
                }
            },
            'test_skill' => function (array $args) use ($folder): string {
                return $this->skills->testCall($args, $folder);
            },
            'send_email' => function (array $args) use ($projectId, $emit, $shouldStop): string {
                $to = trim((string)($args['to'] ?? ''));
                $subject = trim((string)($args['subject'] ?? ''));
                $summary = 'Send email to ' . ($to !== '' ? $to : '(unknown)') . ($subject !== '' ? ' — ' . $subject : '');
                return $this->gateExternal(
                    'email',
                    $summary,
                    fn(): string => (new CapabilityTools($this->db))->sendForProject($projectId, $args),
                    $emit,
                    $shouldStop,
                    $projectId > 0 ? $projectId : null
                );
            },
            'ftp' => function (array $args) use ($projectId, $folder, $emit, $shouldStop): string {
                $action = strtolower(trim((string)($args['action'] ?? '')));
                $summary = 'FTP ' . ($action !== '' ? $action : 'action');
                return $this->gateExternal(
                    'ftp',
                    $summary,
                    fn(): string => (new CapabilityTools($this->db))->ftpForProject($projectId, $folder, $args),
                    $emit,
                    $shouldStop,
                    $projectId > 0 ? $projectId : null
                );
            },
            'mcp' => function (array $args) use ($projectId, $emit, $shouldStop): string {
                $server = trim((string)($args['server'] ?? ''));
                $tool = trim((string)($args['tool'] ?? ''));
                $summary = 'MCP' . ($server !== '' ? ' ' . $server : '') . ($tool !== '' ? ' / ' . $tool : '');
                return $this->gateExternal(
                    'mcp',
                    $summary,
                    fn(): string => (new CapabilityTools($this->db))->mcp($args),
                    $emit,
                    $shouldStop,
                    $projectId > 0 ? $projectId : null
                );
            },
            'remember' => function (array $args): string {
                return $this->memory->remember((string)($args['tool'] ?? ''), (string)($args['note'] ?? ''));
            },
            'py_install' => function (array $args) use ($folder, $emit, $shouldStop, $projectId): string {
                if ($folder === '') {
                    return 'Open a project before installing a Python library.';
                }
                $tools = new CapabilityTools($this->db);
                $names = $tools->pythonPackageNames($args['packages'] ?? $args['package'] ?? '');
                if ($names === []) {
                    return 'Name the Python library to install, such as yfinance.';
                }
                $summary = 'Install Python ' . (count($names) === 1 ? 'library' : 'libraries') . ' into this project: ' . implode(', ', $names);
                if (!$this->confirmAction('install', $summary, $emit, $shouldStop, $projectId > 0 ? $projectId : null)) {
                    return 'The user did not approve this Python library install.';
                }
                return $tools->installPythonPackages($folder, $names);
            },
            'web_search' => function (): string {
                return $this->researchDelegationNotice('web_search');
            },
            'search' => function (): string {
                return $this->researchDelegationNotice('web_search');
            },
            'fetch_url' => function (): string {
                return $this->researchDelegationNotice('fetch_url');
            },
            'browse_url' => function (): string {
                return $this->researchDelegationNotice('browse_url');
            },
            'yahoo_finance' => function (): string {
                return $this->researchDelegationNotice('yahoo_finance');
            },
        ];
        if ($folder !== '') {
            foreach ($this->skills->activeHandlers($folder) as $name => $handler) {
                if (!isset($tools[$name])) {
                    if ($name === 'sqlite_helper') {
                        $tools[$name] = function (array $args) use ($handler, $emit, $shouldStop, $projectId): string {
                            $action = strtolower(trim((string)($args['action'] ?? '')));
                            if ($action === '') {
                                $action = strtolower(trim((string)($args['command'] ?? '')));
                            }
                            $sql = trim((string)($args['sql'] ?? ''));
                            $destructive = in_array($action, ['drop', 'delete'], true)
                                || ($action === 'execute' && preg_match('/^\s*DROP\s+TABLE\b/i', $sql));
                            if (!$destructive) {
                                return $handler($args);
                            }
                            $database = trim((string)($args['database'] ?? ''));
                            $table = trim((string)($args['table'] ?? ''));
                            $summary = 'Drop SQLite table ' . ($table !== '' ? $table : '(unspecified)') . ' from ' . ($database !== '' ? $database : '(unspecified)');
                            if (!$this->confirmAction('database_drop', $summary, $emit, $shouldStop, $projectId > 0 ? $projectId : null)) {
                                return 'The user did not approve this database table drop.';
                            }
                            return $handler($args);
                        };
                        continue;
                    }
                    if ($name === 'research_loop') {
                        $tools[$name] = function (array $args) use ($handler): string {
                            $action = strtolower(trim((string)($args['action'] ?? '')));
                            if (in_array($action, ['add', 'next', 'commit'], true)) {
                                return 'The researcher does this pass. Delegate the open step. Do not call research_loop action ' . $action . '.';
                            }
                            return $handler($args);
                        };
                        continue;
                    }
                    $tools[$name] = $handler;
                }
            }
        }
        foreach (array_keys($tools) as $name) {
            if (!$this->skills->offered($name)) {
                unset($tools[$name]);
            }
        }
        return $tools;
    }

    private function startDelegatedTask(int $projectId, string $agent, string $instruction, array $args, array &$createdTaskIds): string
    {
        if ($projectId <= 0) {
            return '';
        }
        $board = new Kanban($this->db);
        $id = (int)($args['task_id'] ?? $args['task'] ?? 0);
        if ($id <= 0 && preg_match_all('/#(\d+)/', $instruction, $found)) {
            $unique = array_values(array_unique(array_map('intval', $found[1])));
            if (count($unique) === 1) {
                $id = $unique[0];
            }
        }
        $open = null;
        if ($id > 0) {
            $task = $this->db->task($id);
            if ($task && (int)$task['project_id'] === $projectId && !in_array($board->column($task), ['done', 'archived'], true)) {
                $open = $task;
            }
        }
        if ($open === null && count($createdTaskIds) === 1) {
            $task = $this->db->task((int)$createdTaskIds[0]);
            if ($task && (int)$task['project_id'] === $projectId && !in_array($board->column($task), ['done', 'archived'], true)) {
                $open = $task;
            }
        }
        if ($open !== null) {
            $this->refuseIfScheduled($board, $open);
            if (!$this->taskHasStep($open)) {
                $id = (int)$open['id'];
                throw new InvalidArgumentException('Task #' . $id . ' has no steps. It was not started. Add at least one step with tasklist action update and id ' . $id . ', then delegate again. Pass steps as a list. Each step is an object with title, agent, and description. description names the files, lists, and skills to use, and what to save or return.');
            }
            $saved = $board->move((int)$open['id'], ['status' => 'in_progress', 'agent' => $agent], false);
            return "\nTask #" . $saved['id'] . ' is in in_progress.';
        }
        $title = trim((string)($args['title'] ?? ''));
        if ($title === '') {
            return '';
        }
        if (mb_strlen($title) > 125) {
            $title = rtrim(mb_substr($title, 0, 122)) . '...';
        }
        foreach ($this->db->tasks($projectId) as $task) {
            if (strcasecmp(trim((string)$task['title']), $title) !== 0) {
                continue;
            }
            if (in_array($board->column($task), ['done', 'archived'], true)) {
                continue;
            }
            $this->refuseIfScheduled($board, $task);
            if (!$this->taskHasStep($task)) {
                $id = (int)$task['id'];
                throw new InvalidArgumentException('Task #' . $id . ' has no steps. It was not started. Add at least one step with tasklist action update and id ' . $id . ', then delegate again. Pass steps as a list. Each step is an object with title, agent, and description. description names the files, lists, and skills to use, and what to save or return.');
            }
            $saved = $board->move((int)$task['id'], ['status' => 'in_progress', 'agent' => $agent], false);
            return "\nTask #" . $saved['id'] . ' is in in_progress.';
        }
        $saved = $board->create($projectId, [
            'title' => $title,
            'description' => $instruction,
            'agent' => $agent,
            'status' => 'in_progress',
        ], false);
        $createdTaskIds[] = (int)$saved['id'];
        $this->refuseIfScheduled($board, $saved);
        return "\nCreated task #" . $saved['id'] . ' in in_progress.';
    }

    private function refuseIfScheduled(Kanban $board, array $task): void
    {
        if (!$board->awaiting($task)) {
            return;
        }
        $when = trim((string)($task['schedule_time'] ?? ''));
        throw new InvalidArgumentException('Task #' . (int)$task['id'] . ' is scheduled for ' . $when . '. It was not started. That time is so you can correct a mistake before it runs. List the tasks and review them. If something is wrong, update that task. Do not delete it to start sooner. The scheduler starts it when that time arrives.');
    }

    private function pieceSteps(string $title, array $steps): ?array
    {
        if (!preg_match('/\b(?:pdf|story|poem|article)\b/i', $title)) {
            return null;
        }
        $onlyTitle = count($steps) === 1;
        if ($onlyTitle) {
            $one = $steps[0];
            $text = trim((string)(is_array($one) ? ($one['title'] ?? $one['step'] ?? $one['text'] ?? '') : $one));
            $onlyTitle = strcasecmp($text, $title) === 0 || $text === '';
        }
        if (!$onlyTitle && $steps !== []) {
            return null;
        }
        $noPdf = preg_match('/\bno pdf\b|\bonly output\b|\bin the chat\b|\bdo not (?:make|create) a pdf\b|\bdon\'t (?:make|create) a pdf\b/i', $title) === 1;
        $piece = trim($title);
        $plan = [[
            'title' => 'Write the piece and save it as markdown in wiki/',
            'description' => 'Write "' . $piece . '" and save it as markdown in wiki/. Use a file name that matches the piece. Do not put it in the project description.',
        ]];
        if (!$noPdf && preg_match('/\bpdf\b/i', $title) === 1) {
            $plan[] = [
                'title' => 'Create the PDF in docs/',
                'description' => 'Create the PDF in docs/ from the markdown just saved in wiki/. The PDF contains that piece and no other project text.',
            ];
            $plan[] = [
                'title' => 'Read the PDF and check it',
                'description' => 'Read the PDF in docs/ and check that its text matches the markdown in wiki/. Note any missing section before you finish.',
            ];
        }
        $plan[] = [
            'title' => 'Report the file path and cross off the steps',
            'description' => 'Report the file path that was saved, including the PDF in docs/ when one was written. Then cross off the steps. Do not start another piece.',
        ];
        return $plan;
    }

    private function humanStepWait(string $note): string
    {
        if (!preg_match('/Task #(\d+)/', $note, $card)) {
            return '';
        }
        $task = $this->db->task((int)$card[1]);
        if (!$task) {
            return '';
        }
        foreach ($task['subtasks'] ?? [] as $sub) {
            if (!is_array($sub) || (string)($sub['status'] ?? '') === 'done') {
                continue;
            }
            $title = trim((string)($sub['title'] ?? ''));
            if ($title === '') {
                continue;
            }
            $agent = strtolower(trim((string)($sub['assigned_agent'] ?? '')));
            if ($agent === '') {
                $agent = strtolower(trim((string)($task['assigned_agent'] ?? '')));
            }
            if ($agent !== 'human') {
                return '';
            }
            $line = 'Task #' . $task['id'] . ' is waiting on the human. Ask the user and wait. Do not delegate.' . "\n" . $title;
            $detail = trim((string)($sub['description'] ?? ''));
            if ($detail !== '') {
                $line .= "\n" . $detail;
            }
            return $line;
        }
        return '';
    }

    private function currentStepInstruction(string $note, string $fallback): string
    {
        if (!preg_match('/Task #(\d+)/', $note, $card)) {
            return $fallback;
        }
        $task = $this->db->task((int)$card[1]);
        if (!$task) {
            return $fallback;
        }
        $current = null;
        foreach ($task['subtasks'] ?? [] as $sub) {
            if (!is_array($sub) || (string)($sub['status'] ?? '') === 'done') {
                continue;
            }
            if (trim((string)($sub['title'] ?? '')) === '') {
                continue;
            }
            $current = $sub;
            break;
        }
        if (!$current) {
            return $fallback;
        }
        $title = trim((string)$current['title']);
        $detail = trim((string)($current['description'] ?? ''));
        $text = 'This is task #' . $task['id'] . ". Do only this step. Stop when it is finished. Do not start the next step.\n\n" . $title;
        if ($detail !== '') {
            $text .= "\n" . $detail;
        }
        $prior = trim((string)($task['result'] ?? ''));
        if ($prior !== '') {
            $text .= "\n\nEarlier result: " . $prior;
        }
        return $text . "\n\nWhen this step is finished, add one line with tasklist action result and id " . $task['id'] . '. That crosses off this step only. If an earlier step is still open, the task is not done. Do not say the task is complete.';
    }

    private function freshTaskNote(string $note): string
    {
        if (!preg_match('/Task #(\d+)/', $note, $found)) {
            return $note;
        }
        $task = $this->db->task((int)$found[1]);
        if (!$task) {
            return $note;
        }
        $board = new Kanban($this->db);
        if ($board->column($task) !== 'done') {
            $wait = $this->humanStepWait($note);
            if ($wait !== '') {
                return "\n" . $wait;
            }
            $open = [];
            foreach ($task['subtasks'] ?? [] as $sub) {
                if (!is_array($sub) || (string)($sub['status'] ?? '') === 'done') {
                    continue;
                }
                $title = trim((string)($sub['title'] ?? ''));
                if ($title !== '') {
                    $open[] = $title;
                }
            }
            if ($open === []) {
                $recurrence = strtolower(trim((string)($task['recurrence_interval'] ?? 'none')));
                if ($recurrence !== '' && $recurrence !== 'none') {
                    return "\nTask #" . $task['id'] . ' finished its steps for this run. Leave it in progress. Do not delegate it again.';
                }
                return $note;
            }
            $line = "\nTask #" . $task['id'] . ' is not done. Open steps:';
            foreach ($open as $title) {
                $line .= "\n- " . $title;
            }
            return $line . "\nDo not tell the user the task is finished. Delegate only the first open step.";
        }
        $path = trim((string)($task['result'] ?? ''));
        $line = 'Task #' . $task['id'] . ' is done.';
        if ($path !== '') {
            $line .= ' ' . $path;
        }
        return "\n" . $line . ' Do not delegate it again.';
    }

    private function stepCount(array $task): int
    {
        $count = 0;
        foreach ($task['subtasks'] ?? [] as $sub) {
            if (is_array($sub) && trim((string)($sub['title'] ?? '')) !== '') {
                $count++;
            }
        }
        return $count;
    }

    private function taskHasStep(array $task): bool
    {
        if (trim((string)($task['description'] ?? '')) !== '') {
            return true;
        }
        foreach ($task['subtasks'] ?? [] as $sub) {
            if (is_array($sub) && trim((string)($sub['title'] ?? '')) !== '') {
                return true;
            }
        }
        return false;
    }

    private function kanban(int $projectId, array $args, ?array &$createdTaskIds = null): string
    {
        $board = new Kanban($this->db);
        $action = strtolower(trim((string)($args['action'] ?? 'list')));
        try {
            if ($projectId <= 0) {
                return $action === 'list' ? 'Open a project to see its tasks.' : 'Tasks belong to a project. Create or open a project first.';
            }
            if ($action === 'list') {
                return $board->listing($projectId);
            }
            if ($action === 'stop') {
                return $this->db->stopRunningWork($projectId);
            }
            if ($action === 'create') {
                $batch = $this->taskBatch($args);
                if ($batch !== null) {
                    return $this->createNowTasks($projectId, $batch, $createdTaskIds, $board);
                }
                $task = $board->create($projectId, $args, false);
                if ($createdTaskIds !== null) {
                    $createdTaskIds[] = (int)$task['id'];
                }
                return $board->createdLine($task);
            }
            if ($action === 'move' || $action === 'update') {
                $id = $this->kanbanCardId($projectId, $args);
                if ($id <= 0) {
                    return 'Task not found.' . "\n" . $this->kanbanOpenCards($projectId);
                }
                $task = $board->move($id, $args, false);
                $held = (int)($task['held'] ?? 0) === 1 ? ' The task stays held and will not start.' : '';
                $stepsNote = '';
                if (array_key_exists('steps', $args) || array_key_exists('subtasks', $args)) {
                    $write = $board->stepWrite();
                    $added = (int)($write['added'] ?? 0);
                    $detailed = (int)($write['detailed'] ?? 0);
                    $removed = (int)($write['removed'] ?? 0);
                    if ($added > 0) {
                        $stepsNote = ' Added ' . $added . ($added === 1 ? ' step.' : ' steps.');
                    }
                    if ($detailed > 0) {
                        $stepsNote .= ' Updated ' . $detailed . ($detailed === 1 ? ' existing step.' : ' existing steps.');
                    }
                    if ($removed > 0) {
                        $stepsNote .= ' Removed ' . $removed . ' open ' . ($removed === 1 ? 'step.' : 'steps.');
                    }
                    $crossed = (int)($write['crossed'] ?? 0);
                    if ($crossed > 0) {
                        $stepsNote .= ' Crossed off ' . $crossed . ($crossed === 1 ? ' step.' : ' steps.');
                    }
                    if ($stepsNote === '') {
                        $stepsNote = $board->unchangedStepsNote();
                    }
                }
                return $board->updatedLine($task) . $held . $stepsNote;
            }
            if ($action === 'delete') {
                $id = (int)($args['id'] ?? 0);
                $task = $this->db->task($id);
                if (!$task || (int)$task['project_id'] !== $projectId) {
                    return 'Task not found.';
                }
                $this->db->deleteTask($id);
                return 'Deleted task #' . $id . ': ' . $task['title'];
            }
        } catch (InvalidArgumentException $e) {
            return $e->getMessage();
        } catch (RuntimeException $e) {
            return $e->getMessage();
        }
        return 'Unknown tasklist action. Use list, create, update, delete, or stop.';
    }

    private function taskBatch(array $args): ?array
    {
        if (!array_key_exists('tasks', $args)) {
            return null;
        }
        $tasks = $args['tasks'];
        if (is_string($tasks)) {
            $decoded = json_decode($tasks, true);
            $tasks = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($tasks)) {
            return [];
        }
        if (array_key_exists('title', $tasks) && !array_is_list($tasks)) {
            return [$tasks];
        }
        return $tasks;
    }

    private function createNowTasks(int $projectId, array $tasks, ?array &$createdTaskIds, Kanban $board): string
    {
        if ($tasks === []) {
            return 'Pass tasks. Each task has a title and an agent.';
        }
        $cards = [];
        foreach ($tasks as $task) {
            if (is_string($task)) {
                $title = trim($task);
                $agent = 'orchestrator';
            } elseif (is_array($task)) {
                $title = trim((string)($task['title'] ?? ''));
                $agent = strtolower(trim((string)($task['agent'] ?? $task['assigned_agent'] ?? 'orchestrator')));
            } else {
                continue;
            }
            if ($title === '') {
                continue;
            }
            if ($agent === 'human' || $agent === 'user') {
                return 'Cards are not personal todos. Assign orchestrator for planning with the user, or the subagent who will produce the result.';
            }
            if (mb_strlen($title) > 125) {
                $title = rtrim(mb_substr($title, 0, 122)) . '...';
            }
            $steps = [$title];
            if (is_array($task)) {
                $given = $task['steps'] ?? $task['subtasks'] ?? null;
                if (is_array($given) && $given !== []) {
                    $steps = $given;
                }
            }
            if ($agent === 'writer') {
                $plan = $this->pieceSteps($title, $steps);
                if ($plan !== null) {
                    $steps = $plan;
                }
            }
            $cards[] = ['title' => $title, 'agent' => $agent, 'steps' => $steps];
            if (count($cards) >= 40) {
                break;
            }
        }
        if ($cards === []) {
            return 'Pass tasks with a title and an agent.';
        }
        $added = [];
        $duplicates = [];
        foreach ($cards as $card) {
            try {
                $saved = $board->create($projectId, [
                    'title' => $card['title'],
                    'steps' => $card['steps'],
                    'agent' => $card['agent'],
                    'status' => 'in_progress',
                ], false);
            } catch (InvalidArgumentException $e) {
                $message = $e->getMessage();
                if (str_starts_with($message, 'Error: Task #')) {
                    $duplicates[] = $message;
                    continue;
                }
                return $message;
            }
            if ($createdTaskIds !== null) {
                $createdTaskIds[] = (int)$saved['id'];
            }
            $when = trim((string)($saved['schedule_time'] ?? ''));
            $added[] = '#' . $saved['id'] . ' ' . $saved['title'] . ($when !== '' ? ' scheduled for ' . $when : '');
        }
        $lines = [];
        if ($added !== []) {
            $lines[] = 'Added ' . count($added) . ' ' . (count($added) === 1 ? 'task' : 'tasks') . ': ' . implode('; ', $added) . '. The time is 2 minutes ahead so you can correct a mistake before it runs. List the tasks and review every one. Check that the titles, steps, agents, and times match what was intended. If something is wrong, update that task. Do not delete it to start sooner, and do not delegate. The scheduler starts it.';
        }
        if ($duplicates !== []) {
            $lines[] = 'These tasks are already on the task list and were not added. Use those tasks. ' . implode(' ', $duplicates);
        }
        return $lines === [] ? 'Pass tasks with a title and an agent.' : implode("\n", $lines);
    }

    private function kanbanCardId(int $projectId, array $args): int
    {
        $id = $this->kanbanId($args);
        if ($id > 0) {
            $task = $this->db->task($id);
            if ($task && (int)$task['project_id'] === $projectId) {
                return $id;
            }
        }
        $title = trim((string)($args['title'] ?? ''));
        if ($title === '') {
            return 0;
        }
        foreach ($this->db->tasks($projectId) as $task) {
            if ((string)($task['status'] ?? '') === 'archived') {
                continue;
            }
            if (strcasecmp(trim((string)($task['title'] ?? '')), $title) === 0) {
                return (int)$task['id'];
            }
        }
        return 0;
    }

    private function kanbanId(array $args): int
    {
        foreach (['id', 'task_id', 'task'] as $key) {
            if (!array_key_exists($key, $args)) {
                continue;
            }
            $id = $this->kanbanDigits($args[$key]);
            if ($id > 0) {
                return $id;
            }
        }
        return 0;
    }

    private function kanbanDigits(mixed $raw): int
    {
        if (is_int($raw) || is_float($raw)) {
            $id = (int)$raw;
            return $id > 0 ? $id : 0;
        }
        $text = trim((string)$raw);
        if ($text !== '' && preg_match('/(\d+)/', $text, $found)) {
            return (int)$found[1];
        }
        return 0;
    }

    private function kanbanOpenCards(int $projectId): string
    {
        $lines = [];
        foreach ($this->db->tasks($projectId) as $task) {
            if ((string)($task['status'] ?? '') === 'archived') {
                continue;
            }
            $lines[] = '#' . (int)$task['id'] . ' ' . trim((string)($task['title'] ?? ''));
        }
        return $lines === [] ? 'No open tasks.' : implode("\n", $lines);
    }

    private function uploadsFolder(string $folder, string $path): string
    {
        if ($folder !== '') {
            return $folder;
        }
        $raw = str_replace('\\', '/', trim($path));
        if (str_starts_with($raw, '/')) {
            return '';
        }
        $norm = ltrim($raw, '/');
        if ($norm === 'uploads' || str_starts_with($norm, 'uploads/')) {
            return Projects::generalFolder();
        }
        return '';
    }

    private function generalUploadsMissing(string $folder): bool
    {
        return $folder === Projects::generalFolder() && !is_dir(Projects::root($folder) . '/uploads');
    }

    private function needsProject(string $folder, string $path, string $message): ?string
    {
        if ($folder !== '') {
            return null;
        }
        if (!$this->db->fullSystemControl()) {
            return $message;
        }
        if (!str_starts_with(str_replace('\\', '/', trim($path)), '/')) {
            return 'Use a full path, such as /Users/name/file.';
        }
        return null;
    }

    private function researchToolLine(string $tool): string
    {
        return match ($tool) {
            'web_search' => '- web_search: args query. You know this tool. It searches the web. Only the researcher can call it. Delegate the search to the researcher. Do not call it yourself, and do not say you cannot look something up.',
            'fetch_url' => '- fetch_url: args url. You know this tool. It opens a public web page. Only the researcher can call it. Delegate the visit to the researcher. Do not say you cannot visit a website.',
            'browse_url' => '- browse_url: args url. You know this tool. It opens a public page in the headless browser, so scripts on the page run. Only the researcher can call it. Delegate the visit to the researcher.',
            'yahoo_finance' => '- yahoo_finance: args symbol. Optional action, range, interval, start, end, exchange, type. The trading and financial research tool: stocks, bonds, commodities, crypto, currencies, indexes, ETFs, and futures, including price, history, dividends, and splits. A US listing has no suffix. Any other exchange needs the Yahoo suffix, such as .ST for Stockholm. Only the researcher can call it. Delegate that to the researcher.',
            default => '',
        };
    }

    private function researchDelegationNotice(string $tool): string
    {
        return 'Error: ' . $tool . ' must be delegated to the researcher. You know this tool. Only the researcher can call it. Delegate with a clear instruction.';
    }
}
