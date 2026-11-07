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

class ReplyNoiseFilter
{
    private const THINK = ['think', 'thinking', 'reasoning', 'thought'];

    private string $pending = '';

    private string $mode = '';

    private string $closer = '';

    public function __construct(private array $toolNames)
    {
    }

    public function push(string $token): array
    {
        if ($token === '') {
            return ['visible' => '', 'aside' => ''];
        }
        $this->pending .= $token;
        return $this->drain(false);
    }

    public function finish(): array
    {
        return $this->drain(true);
    }

    private function drain(bool $end): array
    {
        $visible = '';
        $aside = '';
        while ($this->pending !== '') {
            if ($this->mode === '') {
                $at = strpos($this->pending, '<');
                if ($at === false) {
                    $visible .= $this->pending;
                    $this->pending = '';
                    break;
                }
                $visible .= substr($this->pending, 0, $at);
                $rest = substr($this->pending, $at);
                $tag = $this->readOpen($rest, $end);
                if ($tag === null) {
                    $this->pending = $rest;
                    break;
                }
                if ($tag['kind'] === 'literal') {
                    $visible .= $tag['raw'];
                    $this->pending = substr($rest, strlen($tag['raw']));
                    continue;
                }
                $aside .= $tag['raw'];
                $this->mode = $tag['kind'];
                $this->closer = $tag['closer'];
                $this->pending = substr($rest, strlen($tag['raw']));
                continue;
            }
            $closeAt = stripos($this->pending, $this->closer);
            if ($closeAt === false) {
                if (!$end) {
                    $hold = $this->suffixHold($this->pending, $this->closer);
                    $cut = strlen($this->pending) - $hold;
                    if ($cut > 0) {
                        $aside .= substr($this->pending, 0, $cut);
                        $this->pending = substr($this->pending, $cut);
                    }
                    break;
                }
                $aside .= $this->pending;
                $this->pending = '';
                $this->mode = '';
                $this->closer = '';
                break;
            }
            $aside .= substr($this->pending, 0, $closeAt) . substr($this->pending, $closeAt, strlen($this->closer));
            $this->pending = substr($this->pending, $closeAt + strlen($this->closer));
            $this->mode = '';
            $this->closer = '';
        }
        return ['visible' => $visible, 'aside' => $aside];
    }

    private function readOpen(string $rest, bool $end): ?array
    {
        if ($rest === '' || $rest[0] !== '<') {
            return ['kind' => 'literal', 'raw' => $rest === '' ? '' : $rest[0], 'closer' => ''];
        }
        if (isset($rest[1]) && $rest[1] === '<') {
            return ['kind' => 'literal', 'raw' => '<', 'closer' => ''];
        }
        if (!preg_match('/\A<([A-Za-z_][\w-]*)(\s[^>]*)?>/', $rest, $match)) {
            if (!$end && strlen($rest) < 80 && !str_contains(substr($rest, 1), '<') && !str_contains($rest, "\n")) {
                return null;
            }
            return ['kind' => 'literal', 'raw' => '<', 'closer' => ''];
        }
        $name = strtolower($match[1]);
        $raw = $match[0];
        if (in_array($name, self::THINK, true) || in_array($name, $this->toolNames, true)) {
            return ['kind' => $name, 'raw' => $raw, 'closer' => '</' . $match[1] . '>'];
        }
        return ['kind' => 'literal', 'raw' => '<', 'closer' => ''];
    }

    private function suffixHold(string $text, string $closer): int
    {
        $max = min(strlen($text), strlen($closer) - 1);
        $hold = 0;
        for ($i = 1; $i <= $max; $i++) {
            if (stripos($closer, substr($text, -$i)) === 0) {
                $hold = $i;
            }
        }
        return $hold;
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
            $this->llm->restoreGpu(function (string $text) use ($emit, $projectId, $agent): void {
                $this->db->addMessage($projectId ?? 0, 'notice', $text, $agent);
                $emit(['type' => 'notice', 'text' => $text]);
            });
            $emit(['type' => 'cpu', 'cpu' => $this->llm->onCpu()]);
            $this->scrubAssistantFences($messages);
            $this->db->setAgentStatus($agent, 'thinking');
            $emit(['type' => 'status', 'agent' => $agent, 'status' => 'thinking']);
            $parser = new ToolStreamParser($this->toolNames());
            $markers = new OutputLineFilter();
            $noise = new ReplyNoiseFilter($this->toolNames());
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
            $keepAside = function (string $aside) use (&$thinkBuf, &$thinkAt, $flushThinking, &$turnText): void {
                if ($aside === '') {
                    return;
                }
                $turnText .= $aside;
                if ($thinkBuf === '') {
                    $thinkAt = microtime(true);
                }
                $thinkBuf .= $aside;
                $flushThinking(false);
            };
            $present = function (string $text) use ($noise, $keepAside, $show): void {
                $split = $noise->push($text);
                $keepAside($split['aside']);
                $show($split['visible']);
            };
            $finishNoise = function () use ($noise, $keepAside, $show): void {
                $split = $noise->finish();
                $keepAside($split['aside']);
                $show($split['visible']);
            };
            try {
                $raw = $this->llm->stream($messages, function (string $token) use ($parser, $emit, $agent, &$toolNoted, $shouldStop, $markers, $guard, $present): void {
                    if ($shouldStop && $shouldStop()) {
                        throw new ChatStopped('Stopped.');
                    }
                    $text = $parser->push($markers->push($token));
                    if (!$toolNoted && $parser->isWritingTool()) {
                        $toolNoted = true;
                        $emit(['type' => 'activity', 'agent' => $agent, 'text' => 'Calling a tool']);
                    }
                    $present($text);
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
                $present($this->releaseMarker($markers, $parser));
                $finishNoise();
                $flushThinking(true);
                $this->db->setAgentStatus($agent, 'idle');
                throw $e;
            } catch (OutputRepeated $e) {
                $finishNoise();
                $flushThinking(true);
                $this->flushRecoveries($emit, $projectId, $agent);
                if ($turnText !== '') {
                    $this->db->logAgent($agent, $projectId, 'thinking', $turnText);
                }
                if (!$this->steerAfterRepeat($repeatTries, $messages, $guard->singleCopy($turnText), $guard->repeated, $visible, $emit, $agent, $skipCompact)) {
                    return $this->finishVisible($visible);
                }
                continue;
            } catch (ContextFallback $e) {
                $flushThinking(true);
                $emit(['type' => 'cpu', 'cpu' => true]);
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
            $present($this->releaseMarker($markers, $parser));
            $tail = $parser->finish();
            $cutOff = !empty($tail['open']);
            if ($tail['text'] !== '' && !$cutOff) {
                $present($tail['text']);
            }
            $finishNoise();
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
                    return $this->finishVisible($visible);
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
            $emptyFences = !$tail['tools'] && !$cutOff && !$this->llm->incomplete && $this->emptyPayloadFences($turnText);
            if (!$tail['tools'] && ($cutOff || $this->llm->incomplete || $proseTools || $emptyFences)) {
                if ($retryOpen >= 5) {
                    if ($emptyFences) {
                        throw new RuntimeException('The model kept returning empty code fences instead of a tool call.');
                    }
                    throw new RuntimeException($proseTools
                        ? 'The model kept naming tools without calling them.'
                        : 'The tool call was cut off before it finished.');
                }
                $retryOpen++;
                $skipCompact = true;
                $replay = $turnText;
                if ($emptyFences && !$cutOff) {
                    $replay = trim($this->stripLeakedThink($turnText));
                    if ($replay === '' || preg_match('/^\s*```[A-Za-z0-9_+-]*\s*$/', $replay)) {
                        $replay = 'The previous reply was empty code fences.';
                    }
                }
                $messages[] = ['role' => 'assistant', 'content' => trim($replay . ($cutOff ? $tail['text'] : ''))];
                if ($cutOff) {
                    $prompt = 'Your last tool call was cut off before it finished. Repeat that same tool call and finish it before doing anything else.';
                } elseif ($emptyFences) {
                    $prompt = 'That reply was an empty code fence, so nothing was called or saved. ```markdown is not a tool call. Do not output ``` at all. Do not ask a question. Emit one tool call and stop:' . "\n" . '<<tool>>' . "\n" . '{"name":"tool_name","args":{}}' . "\n" . '<<end>>' . "\n" . 'A skill, PHP or Python, is write_skill with the full source in args.source. A project file is write_file or wiki_write with the full content.';
                } elseif ($proseTools) {
                    $prompt = 'That was not a tool call. Do not write : tool_name or name a tool in prose. Do not open a markdown code fence. Emit exactly:' . "\n" . '<<tool>>' . "\n" . '{"name":"tool_name","args":{}}' . "\n" . '<<end>>';
                    if (preg_match('/\bdelegat/i', $turnText)) {
                        $prompt = 'That delegation was not run. Do not describe it. Emit the <<tool>> JSON block for kanban so the card is in_progress, then the <<tool>> JSON block for delegate. Pass task_id when the card already exists.';
                    } elseif (($xmlTool = $this->fakeXmlToolName($turnText)) !== null) {
                        $prompt = 'XML tags are not a tool call. Do not write <' . $xmlTool . '>. Emit exactly:' . "\n" . '<<tool>>' . "\n" . '{"name":"' . $xmlTool . '","args":{}}' . "\n" . '<<end>>' . "\n" . 'Fill args with the real JSON for that tool, then stop.';
                    } elseif ($this->claimedSkillWithoutCall($turnText)) {
                        $prompt = 'That did not register a skill. Do not write it into the project and do not open a code fence. Pick PHP or Python yourself. Call write_skill now with the full source, then stop.';
                    } elseif ($this->claimedFileWriteWithoutCall($turnText)) {
                        $prompt = $this->claimedWriteRetryPrompt($turnText);
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
                return $this->finishVisible($visible);
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
        return $this->finishVisible($visible);
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
        $names = $this->toolNames();
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
        if ($this->fakeXmlToolName($text) !== null) {
            return true;
        }
        if ($this->claimedFileWriteWithoutCall($text)) {
            return true;
        }
        if ($this->claimedSkillWithoutCall($text)) {
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

    private function toolNames(): array
    {
        return [
            'delegate', 'consult', 'list_files', 'read_file', 'read_pdf', 'write_file', 'delete_file', 'move_file',
            'create_pdf', 'run_command', 'kanban', 'wiki_search', 'wiki_read', 'wiki_write',
            'wiki_reindex', 'list_vault', 'read_vault', 'restore_file', 'write_skill', 'test_skill', 'remember', 'soul', 'send_email',
            'web_search', 'fetch_url', 'browse_url', 'ftp', 'mcp', 'download_large', 'task_breakdown',
            'research_pipeline', 'sqlite_helper', 'code_verifier', 'humanizer', 'write_article', 'make_graphic', 'save_note',
            'news_fetcher', 'atr_stop_loss',
        ];
    }

    private function fakeXmlToolName(string $text): ?string
    {
        $alt = implode('|', array_map(static fn(string $n): string => preg_quote($n, '/'), $this->toolNames()));
        if (!preg_match('/<\s*(' . $alt . ')\b[^>]*>/i', $text, $match)) {
            return null;
        }
        return strtolower($match[1]);
    }

    private function scrubAssistantFences(array &$messages): void
    {
        foreach ($messages as &$message) {
            if (!is_array($message) || ($message['role'] ?? '') !== 'assistant') {
                continue;
            }
            $content = (string)($message['content'] ?? '');
            if ($content === '' || !$this->emptyPayloadFences($content)) {
                continue;
            }
            $clean = trim($this->stripLeakedThink($content));
            if ($clean === '' || preg_match('/^\s*```[A-Za-z0-9_+-]*\s*$/', $clean)) {
                $message['content'] = 'The previous reply was empty code fences and nothing was called.';
                continue;
            }
            $message['content'] = $clean;
        }
        unset($message);
    }

    private function emptyPayloadFences(string $text): bool
    {
        if (trim($text) === '') {
            return false;
        }
        if (preg_match('/```[^\n`]*\r?\n\s*```/', $text)) {
            return true;
        }
        $clean = trim($this->stripLeakedThink($text));
        if ($clean === '') {
            return true;
        }
        if (preg_match('/^\s*```[A-Za-z0-9_+-]*\s*$/', $clean)) {
            return true;
        }
        return substr_count($clean, '```') % 2 === 1 && (bool)preg_match('/```[A-Za-z0-9_+-]*\s*$/', $clean);
    }

    private function claimedSkillWithoutCall(string $text): bool
    {
        return (bool)preg_match('/\b(?:let me|i will|i\'ll|i am going to|i\'m going to|i\'ll proceed|let me proceed)\b[^.\n]{0,200}\b(?:skill|skills|data\/skills)\b/i', $text)
            && (bool)preg_match('/\b(?:writ|creat|regist|sav)/i', $text);
    }

    private function claimedFileWriteWithoutCall(string $text): bool
    {
        $text = trim($text);
        if ($text === '') {
            return false;
        }
        $target = 'wiki\/[\w.-]+\.md|project description|description file|\.md\b|marketing (?:strategy |plan )?file|\bfile\b';
        $intent = 'I am|I\'m|I will|I\'ll|Let me|I am going to|I\'m going to';
        $doing = 'updat(?:e|ing)|creat(?:e|ing)|writ(?:e|ing)|sav(?:e|ing)|delet(?:e|ing)|add(?:ing)?|edit(?:ing)?';
        $done = 'updated|created|written|wrote|saved|deleted|edited';
        if (preg_match('/\b(?:' . $intent . ')\b(?!\s+not\b)[^.\n]{0,120}\b(?:' . $doing . ')\b[^.\n]{0,160}(?:' . $target . ')/i', $text)) {
            return true;
        }
        if (preg_match('/\b(?:I have|I\'ve)\b(?!\s+not\b)[^.\n]{0,120}\b(?:' . $done . ')\b[^.\n]{0,160}(?:' . $target . ')/i', $text)) {
            return true;
        }
        if (preg_match('/\b(?:' . $doing . '|' . $done . ')\b[^.\n]{0,80}(?:wiki\/[\w.-]+\.md|project description)/i', $text)
            && preg_match('/\b(?:' . $intent . '|I have|I\'ve)\b(?!\s+not\b)/i', $text)) {
            return true;
        }
        if (preg_match('/(?:`?wiki\/[\w.-]+\.md`?)\s*content\s*:/i', $text)) {
            return true;
        }
        if (preg_match('/wiki\/[\w.-]+\.md/i', $text) && preg_match('/```(?:markdown|md)?\s*\n[\s\S]{80,}```/', $text)
            && preg_match('/\b(?:' . $intent . '|I have|I\'ve)\b(?!\s+not\b)[^.\n]{0,120}\b(?:' . $doing . '|' . $done . ')\b/i', $text)) {
            return true;
        }
        return false;
    }

    private function claimedWriteRetryPrompt(string $text): string
    {
        $path = '';
        if (preg_match('/wiki\/[\w.-]+\.md/i', $text, $match)) {
            $path = $match[0];
        }
        $prompt = 'You described a wiki or file write but did not call a tool. Showing markdown in chat is not a write. Emit the <<tool>> JSON block for wiki_write (or write_file) now with the full content. Do not say it is done until the tool result returns.';
        if ($path !== '') {
            $prompt .= ' Use path ' . $path . ' when that is the file you meant.';
        }
        return $prompt;
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

    private function finishVisible(string $visible): string
    {
        return trim($this->stripLeakedThink($visible));
    }

    private function stripLeakedThink(string $text): string
    {
        $text = preg_replace('/<(?:think|thinking|reasoning|thought)\b[^>]*>[\s\S]*?<\/(?:think|thinking|reasoning|thought)>/i', '', $text) ?? $text;
        if (preg_match_all('/<\/(?:think|thinking|reasoning|thought)>/i', $text, $matches, PREG_OFFSET_CAPTURE) && $matches[0] !== []) {
            $last = $matches[0][count($matches[0]) - 1];
            $text = substr($text, $last[1] + strlen($last[0]));
        }
        $text = preg_replace('/<(?:think|thinking|reasoning|thought)\b[^>]*>[\s\S]*$/i', '', $text) ?? $text;
        $text = preg_replace('/```[^\n]*\n\s*```/', '', $text) ?? $text;
        return $text;
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
