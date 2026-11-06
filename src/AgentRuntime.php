<?php

class OutputLineFilter
{
    private string $pending = '';

    private bool $lineStart = true;

    public function push(string $token): string
    {
        if ($token === '') {
            return '';
        }
        $this->pending .= $token;
        $emitted = '';
        while ($this->pending !== '') {
            $break = strpos($this->pending, "\n");
            if ($break === false) {
                if ($this->lineStart && $this->couldBeMarker($this->pending)) {
                    break;
                }
                $emitted .= $this->pending;
                $this->pending = '';
                $this->lineStart = false;
                break;
            }
            $line = substr($this->pending, 0, $break);
            $this->pending = substr($this->pending, $break + 1);
            if ($this->lineStart && $this->isMarker($line)) {
                $this->lineStart = true;
                continue;
            }
            $emitted .= $line . "\n";
            $this->lineStart = true;
        }
        return $emitted;
    }

    public function finish(): string
    {
        $rest = $this->pending;
        $this->pending = '';
        if ($rest === '') {
            return '';
        }
        if ($this->lineStart && $this->isMarker($rest)) {
            $this->lineStart = true;
            return '';
        }
        $this->lineStart = !str_ends_with($rest, "\n");
        return $rest;
    }

    private function isMarker(string $line): bool
    {
        $text = trim($line);
        return strcasecmp($text, 'output') === 0 || strcasecmp($text, '<<output>>') === 0;
    }

    private function couldBeMarker(string $text): bool
    {
        $trim = trim($text, " \t\r");
        if ($trim === '') {
            return true;
        }
        foreach (['output', '<<output>>'] as $marker) {
            if (strlen($trim) <= strlen($marker) && strncasecmp($trim, $marker, strlen($trim)) === 0) {
                return true;
            }
        }
        return false;
    }
}

class RepeatLineGuard
{
    private const LIMIT = 5;

    private string $buf = '';

    private string $line = '';

    private int $sent = 0;

    private string $last = '';

    private int $run = 0;

    public bool $tripped = false;

    public string $repeated = '';

    public function push(string $token): string
    {
        if ($this->tripped || $token === '') {
            return '';
        }
        $this->buf .= $token;
        $emitted = '';
        while (($break = strpos($this->buf, "\n")) !== false) {
            $piece = substr($this->buf, 0, $break);
            $this->buf = substr($this->buf, $break + 1);
            $full = $this->line . $piece;
            $this->line = '';
            $key = rtrim($full, "\r");
            if (trim($key) === '') {
                $emitted .= $this->unsent($full) . "\n";
                $this->sent = 0;
                continue;
            }
            if ($key === $this->last) {
                $this->run++;
                $this->repeated = $key;
            } else {
                $this->last = $key;
                $this->run = 1;
            }
            if ($this->run > self::LIMIT) {
                $this->tripped = true;
                $this->buf = '';
                $this->line = '';
                $this->sent = 0;
                return $emitted;
            }
            $emitted .= $this->unsent($full) . "\n";
            $this->sent = 0;
        }
        $this->line .= $this->buf;
        $this->buf = '';
        if ($this->line === '' || ($this->run >= self::LIMIT && $this->sameSoFar($this->line))) {
            return $emitted;
        }
        $emitted .= $this->unsent($this->line);
        $this->sent = strlen($this->line);
        return $emitted;
    }

    public function finish(): string
    {
        if ($this->tripped) {
            $this->line = '';
            $this->sent = 0;
            return '';
        }
        $rest = $this->line;
        $this->line = '';
        if ($rest === '') {
            $this->sent = 0;
            return '';
        }
        $key = rtrim($rest, "\r");
        if (trim($key) !== '' && $key === $this->last && $this->run >= self::LIMIT) {
            $this->tripped = true;
            $this->repeated = $key;
            $this->sent = 0;
            return '';
        }
        $out = $this->unsent($rest);
        $this->sent = 0;
        return $out;
    }

    public function singleCopy(string $text): string
    {
        $line = $this->repeated;
        if ($line === '') {
            return trim($text);
        }
        $parts = explode("\n", $text);
        if ($parts !== [] && end($parts) === '') {
            array_pop($parts);
        }
        $cut = 0;
        $seen = 0;
        for ($i = count($parts) - 1; $i >= 0; $i--) {
            $key = rtrim($parts[$i], "\r");
            if (trim($key) === '') {
                $cut++;
                continue;
            }
            if ($key !== $line) {
                break;
            }
            $seen++;
            $cut++;
        }
        if ($seen < 2) {
            return trim($text);
        }
        $kept = array_slice($parts, 0, count($parts) - $cut);
        $kept[] = $line;
        return trim(implode("\n", $kept));
    }

    private function unsent(string $full): string
    {
        if ($this->sent <= 0) {
            return $full;
        }
        if ($this->sent >= strlen($full)) {
            return '';
        }
        return substr($full, $this->sent);
    }

    private function sameSoFar(string $text): bool
    {
        $key = rtrim($text, "\r");
        if ($this->last === '' || $key === '' || strlen($key) > strlen($this->last)) {
            return false;
        }
        return substr($this->last, 0, strlen($key)) === $key;
    }
}

class AgentRuntime
{
    public function __construct(private LLMClient $llm, private Database $db)
    {
    }

    public function maxTurns(): int
    {
        return max(1, (int)app_config()['max_turns']);
    }

    public function cloudMaxTurns(): int
    {
        $turns = (int)$this->db->getSetting('cloud_max_turns', (string)app_config()['cloud_max_turns']);
        return max(10, min(500, $turns));
    }

    public function run(string $agent, array $messages, array $toolHandlers, callable $emit, int $maxTurns, ?int $projectId, ?callable $shouldStop = null, bool $cloud = false, bool $scheduled = false, ?callable $betweenTurns = null, ?callable $onFallback = null): string
    {
        if (!$this->llm->usingFailsafe && ($cloud || (string)$this->db->getSetting('model_mode', '') === 'cloud')) {
            $maxTurns = $this->cloudMaxTurns();
        }
        $visible = '';
        $retryOpen = 0;
        $repeatTries = 0;
        $fallbackTries = 0;
        $skipCompact = false;
        for ($turn = 0; $turn < $maxTurns; $turn++) {
            if ($shouldStop && $shouldStop()) {
                throw new ChatStopped('Stopped.');
            }
            if ($turn > 0 && !$skipCompact && $betweenTurns) {
                $next = $betweenTurns($messages);
                if (is_array($next) && $next !== []) {
                    $messages = $next;
                }
            }
            $skipCompact = false;
            $this->db->setAgentStatus($agent, 'thinking');
            $emit(['type' => 'status', 'agent' => $agent, 'status' => 'thinking']);
            $parser = new ToolStreamParser();
            $markers = new OutputLineFilter();
            $turnText = '';
            $guard = new RepeatLineGuard();
            $show = function (string $text) use ($guard, $emit, $agent, &$visible, &$turnText): void {
                $text = $guard->push($text);
                if ($text === '') {
                    return;
                }
                $visible .= $text;
                $turnText .= $text;
                $emit(['type' => 'token', 'agent' => $agent, 'text' => $text]);
            };
            $toolNoted = false;
            $thinkBuf = '';
            $thinkAt = 0.0;
            $flushThinking = function (bool $force = false) use (&$thinkBuf, &$thinkAt, $emit, $agent): void {
                if ($thinkBuf === '') {
                    return;
                }
                if (!$force && strlen($thinkBuf) < 800 && (microtime(true) - $thinkAt) < 0.35) {
                    return;
                }
                $chunk = $thinkBuf;
                $thinkBuf = '';
                $thinkAt = microtime(true);
                $emit(['type' => 'thinking', 'agent' => $agent, 'text' => $chunk]);
            };
            try {
                $raw = $this->llm->stream($messages, function (string $token) use ($parser, $emit, $agent, &$toolNoted, $shouldStop, $markers, $guard, $show): void {
                    if ($shouldStop && $shouldStop()) {
                        throw new ChatStopped('Stopped.');
                    }
                    $text = $parser->push($markers->push($token));
                    if (!$toolNoted && $parser->isWritingTool()) {
                        $toolNoted = true;
                        $emit(['type' => 'activity', 'agent' => $agent, 'text' => 'Calling a tool']);
                    }
                    $show($text);
                    if ($guard->tripped) {
                        throw new OutputRepeated();
                    }
                }, $shouldStop, function (string $chunk) use ($shouldStop, &$thinkBuf, &$thinkAt, $flushThinking): void {
                    if ($chunk === '') {
                        return;
                    }
                    if ($shouldStop && $shouldStop()) {
                        throw new ChatStopped('Stopped.');
                    }
                    if ($thinkBuf === '') {
                        $thinkAt = microtime(true);
                    }
                    $thinkBuf .= $chunk;
                    $flushThinking(false);
                }, $cloud, $agent);
                $flushThinking(true);
                $this->flushRecoveries($emit, $projectId, $agent);
            } catch (ChatStopped $e) {
                $flushThinking(true);
                $show($this->releaseMarker($markers, $parser));
                $this->db->setAgentStatus($agent, 'idle');
                throw $e;
            } catch (OutputRepeated $e) {
                $flushThinking(true);
                $this->flushRecoveries($emit, $projectId, $agent);
                if ($turnText !== '') {
                    $this->db->logAgent($agent, $projectId, 'thinking', $turnText);
                }
                if (!$this->steerAfterRepeat($repeatTries, $messages, $guard->singleCopy($turnText), $guard->repeated, $visible, $emit, $agent, $skipCompact)) {
                    return trim($visible);
                }
                continue;
            } catch (ContextFallback $e) {
                $flushThinking(true);
                $this->flushRecoveries($emit, $projectId, $agent);
                if ($fallbackTries >= 1) {
                    $this->db->setAgentStatus($agent, 'error');
                    $notice = agent_error($agent, $e->getMessage());
                    $this->db->logAgent($agent, $projectId, 'error', $notice);
                    $emit(['type' => 'status', 'agent' => $agent, 'status' => 'error']);
                    throw new LlmException($notice, false);
                }
                $fallbackTries++;
                if ($onFallback) {
                    $next = $onFallback($messages);
                    if (is_array($next) && $next !== []) {
                        $messages = $next;
                    }
                }
                $skipCompact = true;
                continue;
            } catch (LlmException $e) {
                $flushThinking(true);
                $this->flushRecoveries($emit, $projectId, $agent);
                $this->db->setAgentStatus($agent, 'error');
                $notice = agent_error($agent, $e->getMessage());
                $this->db->logAgent($agent, $projectId, 'error', $notice);
                $emit(['type' => 'status', 'agent' => $agent, 'status' => 'error']);
                throw new LlmException($notice, $e->retryable);
            }
            $show($this->releaseMarker($markers, $parser));
            $tail = $parser->finish();
            $cutOff = !empty($tail['open']);
            if ($tail['text'] !== '' && !$cutOff) {
                $show($tail['text']);
            }
            $rest = $guard->finish();
            if ($rest !== '') {
                $visible .= $rest;
                $turnText .= $rest;
                $emit(['type' => 'token', 'agent' => $agent, 'text' => $rest]);
            }
            if ($turnText !== '') {
                $this->db->logAgent($agent, $projectId, 'thinking', $turnText);
            }
            if ($guard->tripped) {
                if (!$this->steerAfterRepeat($repeatTries, $messages, $guard->singleCopy($turnText), $guard->repeated, $visible, $emit, $agent, $skipCompact)) {
                    return trim($visible);
                }
                continue;
            }
            $repeatTries = 0;
            if ($agent === 'orchestrator' && !$tail['tools'] && !$cutOff && !$this->llm->incomplete) {
                $inferred = $this->proseDelegation($turnText);
                if ($inferred !== null) {
                    $tail['tools'] = [$inferred];
                }
            }
            $proseTools = !$tail['tools'] && !$cutOff && !$this->llm->incomplete && $this->announcedToolsWithoutCall($turnText);
            if (!$tail['tools'] && ($cutOff || $this->llm->incomplete || $proseTools)) {
                if ($retryOpen >= 5) {
                    throw new RuntimeException($proseTools
                        ? 'The model kept naming tools without calling them.'
                        : 'The tool call was cut off before it finished.');
                }
                $retryOpen++;
                $skipCompact = true;
                $messages[] = ['role' => 'assistant', 'content' => trim($turnText . ($cutOff ? $tail['text'] : ''))];
                if ($cutOff) {
                    $prompt = 'Your last tool call was cut off before it finished. Repeat that same tool call and finish it before doing anything else.';
                } elseif ($proseTools) {
                    $prompt = 'That was not a tool call. Do not write : tool_name or name a tool in prose. Emit exactly:\n<<tool>>\n{"name":"tool_name","args":{}}\n<<end>>';
                    if (preg_match('/\bdelegat/i', $turnText)) {
                        $prompt = 'That delegation was not run. Do not describe it. Emit the <<tool>> JSON block for kanban so the card is in_progress, then the <<tool>> JSON block for delegate. Pass task_id when the card already exists.';
                    }
                } else {
                    $prompt = 'The reply stopped before the work finished. Continue that same task. If a tool still needs to run, call it now.';
                }
                $messages[] = ['role' => 'user', 'content' => $prompt];
                $this->llm->incomplete = false;
                continue;
            }
            $retryOpen = 0;
            if (!$tail['tools']) {
                $this->db->setAgentStatus($agent, 'completed');
                $emit(['type' => 'status', 'agent' => $agent, 'status' => 'completed']);
                return trim($visible);
            }
            $messages[] = ['role' => 'assistant', 'content' => ($raw !== '' ? $raw : $turnText)];
            $delegated = false;
            $consulted = false;
            foreach ($tail['tools'] as $tool) {
                if ($shouldStop && $shouldStop()) {
                    throw new ChatStopped('Stopped.');
                }
                if ($agent === 'orchestrator' && ($tool['name'] ?? '') === 'delegate') {
                    if ($delegated) {
                        $skipped = 'Only one subagent can run at a time. This delegation was not started. Wait for the current result, then delegate the next task.';
                        $emit(['type' => 'tool_call', 'agent' => $agent, 'name' => 'delegate', 'args' => $tool['args']]);
                        $emit(['type' => 'tool_result', 'agent' => $agent, 'name' => 'delegate', 'text' => $skipped]);
                        $messages[] = ['role' => 'user', 'content' => "TOOL RESULT delegate:\n{$skipped}"];
                        continue;
                    }
                    $delegated = true;
                }
                if ($agent === 'orchestrator' && ($tool['name'] ?? '') === 'consult') {
                    if ($consulted) {
                        $skipped = 'The Consultant was already called this turn. It costs money, so wait for that result before asking again.';
                        $emit(['type' => 'tool_call', 'agent' => $agent, 'name' => 'consult', 'args' => $tool['args']]);
                        $emit(['type' => 'tool_result', 'agent' => $agent, 'name' => 'consult', 'text' => $skipped]);
                        $messages[] = ['role' => 'user', 'content' => "TOOL RESULT consult:\n{$skipped}"];
                        continue;
                    }
                    $consulted = true;
                }
                $this->db->setAgentStatus($agent, 'executing_tool');
                $emit(['type' => 'status', 'agent' => $agent, 'status' => 'executing_tool']);
                $emit(['type' => 'tool_call', 'agent' => $agent, 'name' => $tool['name'], 'args' => $tool['args']]);
                $this->db->logAgent($agent, $projectId, 'tool_call', $tool['name'] . ' ' . json_encode($tool['args'], JSON_UNESCAPED_UNICODE));
                try {
                    if (!isset($toolHandlers[$tool['name']])) {
                        $result = agent_error($agent, 'Unknown tool: ' . $tool['name']);
                    } else {
                        $result = (string)$toolHandlers[$tool['name']]($tool['args']);
                    }
                } catch (Throwable $e) {
                    $result = agent_error($agent, $e->getMessage());
                    $this->db->logAgent($agent, $projectId, 'error', $result);
                }
                $result = clip_text($result);
                $this->db->logAgent($agent, $projectId, 'output', $result);
                $emit(['type' => 'tool_result', 'agent' => $agent, 'name' => $tool['name'], 'text' => $result]);
                $messages[] = ['role' => 'user', 'content' => "TOOL RESULT {$tool['name']}:\n{$result}"];
                if ($this->kanbanFollowsDelegate($agent, (string)($tool['name'] ?? ''), $result)) {
                    $messages[] = ['role' => 'user', 'content' => 'The delegated job just finished. List the kanban and update the matching cards before you reply or delegate again. Do this even if you have no recollection of any tasks in this chat. A cleared or compressed context still has cards. Move finished work to review with a short result. Move the card you are starting to in_progress. You never mark a task done. Do not explain the board workflow in place of listing it and updating the cards.'];
                }
            }
            if ($cutOff) {
                $skipCompact = true;
                $messages[] = ['role' => 'user', 'content' => 'Your last tool call was cut off before it finished. Repeat that same tool call and finish it before doing anything else.'];
            }
        }
        $note = "\n\nStopped because of too many turns.";
        $visible .= $note;
        $emit(['type' => 'token', 'agent' => $agent, 'text' => $note]);
        $this->db->setAgentStatus($agent, 'completed');
        $emit(['type' => 'status', 'agent' => $agent, 'status' => 'completed']);
        return trim($visible);
    }

    private function kanbanFollowsDelegate(string $agent, string $tool, string $result): bool
    {
        if ($agent !== 'orchestrator' || $tool !== 'delegate') {
            return false;
        }
        foreach ([
            'Error:',
            'General chat has no project',
            'Delegation rejected',
            'Only one subagent can run at a time',
        ] as $prefix) {
            if (str_starts_with($result, $prefix)) {
                return false;
            }
        }
        return true;
    }

    private function announcedToolsWithoutCall(string $text): bool
    {
        $text = trim($text);
        if ($text === '') {
            return false;
        }
        if (preg_match('/(?:^|\n)\s*(?:\*\*)?delegate(?:\*\*)?\s*:/i', $text) && preg_match('/\bagent\s*:/i', $text) && preg_match('/\binstruction\s*:/i', $text)) {
            return true;
        }
        if (preg_match('/\b(?:creating|created)\b[^.\n]{0,80}\btask\s+#?\d+/i', $text) && preg_match('/\bdelegat(?:e|ing|ed)\b/i', $text)) {
            return true;
        }
        $names = [
            'delegate', 'consult', 'list_files', 'read_file', 'read_pdf', 'write_file', 'delete_file', 'move_file',
            'create_pdf', 'run_command', 'kanban', 'create_project', 'wiki_search', 'wiki_read', 'wiki_write',
            'wiki_reindex', 'list_vault', 'read_vault', 'restore_file', 'write_skill', 'remember', 'soul', 'send_email',
            'web_search', 'fetch_url', 'browse_url', 'ftp', 'mcp', 'download_large', 'task_breakdown',
            'research_pipeline', 'sqlite_helper', 'code_verifier', 'humanizer', 'write_article', 'make_graphic', 'save_note',
            'news_fetcher', 'atr_stop_loss',
        ];
        $altAll = implode('|', array_map(static fn(string $n): string => preg_quote($n, '/'), $names));
        if (preg_match('/^\s*:\s*(?:`)?(?:' . $altAll . ')(?:`)?\b/mi', $text)) {
            return true;
        }
        if (preg_match('/^\s*:\s*(?:`)?[\w.\/-]+\.(?:md|php|txt|json|csv|pdf|html)(?:`)?\s*$/mi', $text)) {
            return true;
        }
        if (preg_match('/^\s*(?:`)?(?:' . $altAll . ')(?:`)?\s*(?:,\s*args\b.*)?$/mi', $text)) {
            return true;
        }
        $found = [];
        foreach ($names as $name) {
            if (preg_match('/(?:^|[^A-Za-z0-9_])' . preg_quote($name, '/') . '(?:[^A-Za-z0-9_]|$)/i', $text)) {
                $found[] = $name;
            }
        }
        if ($found === []) {
            return false;
        }
        $alt = implode('|', array_map(static fn(string $n): string => preg_quote($n, '/'), $found));
        if (preg_match('/^\s*(?:\d+[.)]\s*|\*\s+|-\s+).*`(?:' . $alt . ')`/mi', $text)) {
            return true;
        }
        $listHits = preg_match_all('/^\s*(?:\d+[.)]\s*|\*\s+|-\s+).*(?:' . $alt . ')/mi', $text);
        if ($listHits >= 2) {
            return true;
        }
        if ($listHits >= 1 && preg_match('/\b(?:I will|I\'ll|Let me|I am going to|I\'m going to)\b/i', $text)) {
            return true;
        }
        foreach ($found as $name) {
            $q = preg_quote($name, '/');
            if (preg_match('/\b(?:I will|I\'ll|Let me|I am going to|I\'m going to)\b[^.\n]{0,120}\b' . $q . '\b/i', $text)) {
                return true;
            }
        }
        return false;
    }

    private function proseDelegation(string $text): ?array
    {
        if (!preg_match('/(?:^|\n)\s*\*{0,2}\s*delegate\s*\*{0,2}\s*:?\s*\*{0,2}\s*\n+\s*\*{0,2}\s*agent\s*\*{0,2}\s*:\s*\*{0,2}\s*([A-Za-z_]+)\s*\*{0,2}\s*\n+\s*\*{0,2}\s*instruction\s*\*{0,2}\s*:\s*\*{0,2}\s*>?\s*\n(.*)\z/is', $text, $match)) {
            return null;
        }
        $agent = strtolower(trim($match[1]));
        $instruction = trim((string)preg_replace('/\n+\s*\*{0,2}\s*note\s*\*{0,2}\s*:.*\z/is', '', $match[2]));
        if ($instruction === '' || !in_array($agent, ['researcher', 'writer', 'coder', 'analyst', 'marketer'], true)) {
            return null;
        }
        $args = ['agent' => $agent, 'instruction' => $instruction];
        if (preg_match('/#(\d+)\s*\(\s*in\s*progress\s*\)/i', $text, $id)) {
            $args['task_id'] = (int)$id[1];
        } elseif (preg_match('/\btask\s+#(\d+)/i', $text, $id)) {
            $args['task_id'] = (int)$id[1];
        }
        if (preg_match('/#\d+\s*\(\s*in\s*progress\s*\)\s*:?\s*\**\s*(.+)/i', $text, $title)) {
            $clean = trim(preg_replace('/\*+/', '', $title[1]) ?? $title[1]);
            $clean = trim($clean, " \t\n\r\0\x0B:-");
            if ($clean !== '' && strlen($clean) <= 200) {
                $args['title'] = $clean;
            }
        }
        return ['name' => 'delegate', 'args' => $args];
    }

    private function steerAfterRepeat(int &$tries, array &$messages, string $kept, string $line, string &$visible, callable $emit, string $agent, bool &$skipCompact): bool
    {
        if ($tries >= 3) {
            $note = "\n\nStopped because the same line was repeating.";
            $visible .= $note;
            $emit(['type' => 'token', 'agent' => $agent, 'text' => $note]);
            $this->db->setAgentStatus($agent, 'completed');
            $emit(['type' => 'status', 'agent' => $agent, 'status' => 'completed']);
            return false;
        }
        $tries++;
        $skipCompact = true;
        if ($kept !== '') {
            $messages[] = ['role' => 'assistant', 'content' => $kept];
        }
        $cue = 'You wrote the same line more than five times in a row. Stop repeating it. Continue the task from the last useful step. If a tool still needs to run, call it now.';
        if ($line !== '') {
            $cue = "You wrote this line more than five times in a row:\n" . clip_text($line, 400) . "\nStop repeating it. Continue the task from the last useful step. If a tool still needs to run, call it now.";
        }
        $messages[] = ['role' => 'user', 'content' => $cue];
        return true;
    }

    private function releaseMarker(OutputLineFilter $markers, ToolStreamParser $parser): string
    {
        return $parser->push($markers->finish());
    }

    private function flushRecoveries(callable $emit, ?int $projectId, string $agent): void
    {
        foreach ($this->llm->recoveries as $line) {
            $line = trim((string)$line);
            if ($line === '') {
                continue;
            }
            $this->db->addMessage($projectId ?? 0, 'notice', $line, $agent);
            $emit(['type' => 'notice', 'text' => $line]);
        }
        $this->llm->recoveries = [];
    }
}
