<?php

class OutputLineFilter
{
    public int $dropped = 0;

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
                $this->dropped++;
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
            $this->dropped++;
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

    private const PASS = ['chat'];

    public int $asideChars = 0;

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
                if ($tag['kind'] !== 'pass') {
                    $aside .= $tag['raw'];
                }
                $this->mode = $tag['kind'];
                $this->closer = $tag['closer'];
                $this->pending = substr($rest, strlen($tag['raw']));
                continue;
            }
            $closeAt = stripos($this->pending, $this->closer);
            $pass = $this->mode === 'pass';
            if ($closeAt === false) {
                if (!$end) {
                    $hold = $this->suffixHold($this->pending, $this->closer);
                    $cut = strlen($this->pending) - $hold;
                    if ($cut > 0) {
                        $chunk = substr($this->pending, 0, $cut);
                        if ($pass) {
                            $visible .= $chunk;
                        } else {
                            $aside .= $chunk;
                        }
                        $this->pending = substr($this->pending, $cut);
                    }
                    break;
                }
                if ($pass) {
                    $visible .= $this->pending;
                } else {
                    $aside .= $this->pending;
                }
                $this->pending = '';
                $this->mode = '';
                $this->closer = '';
                break;
            }
            $chunk = substr($this->pending, 0, $closeAt);
            $closerLen = strlen($this->closer);
            if ($pass) {
                $visible .= $chunk;
            } else {
                $aside .= $chunk . substr($this->pending, $closeAt, $closerLen);
            }
            $this->pending = substr($this->pending, $closeAt + $closerLen);
            $this->mode = '';
            $this->closer = '';
        }
        $this->asideChars += strlen($aside);
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
        if (in_array($name, self::PASS, true)) {
            return ['kind' => 'pass', 'raw' => $raw, 'closer' => '</' . $match[1] . '>'];
        }
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

    public function releaseLocalModel(): void
    {
        $this->llm->releaseLocalModel();
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
        $finishChecks = 0;
        $skipCompact = false;
        $writeStreakPath = '';
        $writeStreak = 0;
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
            $this->db->loop($agent, 'process', 'Turn ' . ($turn + 1) . '/' . $maxTurns, $projectId);
            $this->llm->onCpuChange = function (bool $cpu) use ($emit): void {
                $emit(['type' => 'cpu', 'cpu' => $cpu]);
            };
            $this->llm->restoreGpu(function (string $text) use ($emit, $projectId, $agent, $scheduled): void {
                $this->postNotice($emit, $projectId, $agent, $text, $scheduled, 'note');
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
            $thinkingGuard = new RepeatLineGuard();
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
                }, $shouldStop, function (string $chunk) use ($shouldStop, &$thinkBuf, &$thinkAt, $flushThinking, $thinkingGuard): void {
                    if ($chunk === '') {
                        return;
                    }
                    if ($shouldStop && $shouldStop()) {
                        throw new ChatStopped('Stopped.');
                    }
                    $thinkingGuard->push($chunk);
                    if ($thinkingGuard->tripped) {
                        throw new OutputRepeated();
                    }
                    if ($thinkBuf === '') {
                        $thinkAt = microtime(true);
                    }
                    $thinkBuf .= $chunk;
                    $flushThinking(false);
                }, $cloud, $agent);
                $flushThinking(true);
                $this->flushRecoveries($emit, $projectId, $agent, $scheduled);
            } catch (ChatStopped $e) {
                $present($this->releaseMarker($markers, $parser));
                $finishNoise();
                $flushThinking(true);
                $this->traceFilters($agent, $projectId, $markers, $noise, $guard);
                $this->db->setAgentStatus($agent, 'idle');
                throw $e;
            } catch (OutputRepeated $e) {
                $finishNoise();
                $flushThinking(true);
                $this->flushRecoveries($emit, $projectId, $agent, $scheduled);
                $repeatGuard = $thinkingGuard->tripped ? $thinkingGuard : $guard;
                $this->traceFilters($agent, $projectId, $markers, $noise, $repeatGuard);
                if ($turnText !== '') {
                    $this->db->logAgent($agent, $projectId, 'thinking', $turnText);
                }
                if (!$this->steerAfterRepeat($repeatTries, $messages, $repeatGuard->singleCopy($turnText), $repeatGuard->repeated, $visible, $emit, $agent, $skipCompact)) {
                    return $this->finishVisible($visible);
                }
                continue;
            } catch (ContextFallback $e) {
                $flushThinking(true);
                $emit(['type' => 'cpu', 'cpu' => true]);
                $this->flushRecoveries($emit, $projectId, $agent, $scheduled);
                $this->traceFilters($agent, $projectId, $markers, $noise, $guard);
                if ($fallbackTries >= 1) {
                    $this->db->loop($agent, 'stop', 'context fallback · ' . $e->getMessage(), $projectId);
                    $this->db->setAgentStatus($agent, 'error');
                    $notice = agent_error($agent, $e->getMessage());
                    $this->db->logAgent($agent, $projectId, 'error', $notice);
                    $emit(['type' => 'status', 'agent' => $agent, 'status' => 'error']);
                    throw new LlmException($notice, false);
                }
                $fallbackTries++;
                $this->db->loop($agent, 'steer', 'context fallback · ' . $e->getMessage(), $projectId);
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
                $this->flushRecoveries($emit, $projectId, $agent, $scheduled);
                $this->traceFilters($agent, $projectId, $markers, $noise, $guard);
                $this->db->loop($agent, 'stop', $e->getMessage(), $projectId);
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
            $this->traceFilters($agent, $projectId, $markers, $noise, $guard);
            $this->traceOutcome($agent, $projectId, $turnText, $tail, $cutOff);
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
                    $this->db->loop($agent, 'steer', 'prose delegate · ' . (string)($inferred['args']['agent'] ?? ''), $projectId);
                }
            }
            $proseTools = !$tail['tools'] && !$cutOff && !$this->llm->incomplete && $this->announcedToolsWithoutCall($turnText);
            $emptyFences = !$tail['tools'] && !$cutOff && !$this->llm->incomplete && $this->emptyPayloadFences($turnText);
            if (!$tail['tools'] && ($cutOff || $this->llm->incomplete || $proseTools || $emptyFences)) {
                $ranOut = $this->llm->ranOut;
                if ($retryOpen >= 5 && !$ranOut) {
                    $why = $emptyFences ? 'empty fences' : ($proseTools ? 'prose tool' : 'cutoff');
                    $this->db->loop($agent, 'stop', $why . ' · retry limit', $projectId);
                    if ($emptyFences) {
                        throw new RuntimeException('The model kept returning empty code fences instead of a tool call.');
                    }
                    throw new RuntimeException($proseTools
                        ? 'The model kept naming tools without calling them.'
                        : 'The tool call was cut off before it finished.');
                }
                if (!$ranOut) {
                    $retryOpen++;
                }
                $why = 'incomplete';
                if ($cutOff) {
                    $why = 'cutoff';
                } elseif ($emptyFences) {
                    $why = 'empty fences';
                } elseif ($proseTools) {
                    $why = 'prose tool';
                } elseif ($ranOut) {
                    $why = 'ran out · brief on';
                }
                $this->db->loop($agent, 'steer', $why . ($ranOut ? '' : ' · retry ' . $retryOpen . '/5'), $projectId);
                $skipCompact = true;
                $replay = $turnText;
                if ($ranOut && trim($replay) === '') {
                    $replay = 'The previous reply used the whole output limit while thinking and wrote nothing.';
                }
                if ($emptyFences && !$cutOff) {
                    $replay = trim($this->stripLeakedThink($turnText));
                    if ($replay === '' || preg_match('/^\s*```[A-Za-z0-9_+-]*\s*$/', $replay)) {
                        $replay = 'The previous reply was empty code fences.';
                    }
                }
                $messages[] = ['role' => 'assistant', 'content' => strip_chat_tags(trim($replay . ($cutOff ? $tail['text'] : '')))];
                if ($cutOff) {
                    $prompt = 'Your last tool call was cut off before it finished. Repeat that same tool call and finish it before doing anything else.';
                } elseif ($emptyFences) {
                    $prompt = 'That reply was an empty code fence, so nothing was called or saved. ```markdown is not a tool call. Do not output ``` at all. Do not ask a question. Emit one tool call and stop:' . "\n" . '<<tool>>' . "\n" . '{"name":"tool_name","args":{}}' . "\n" . '<<end>>' . "\n" . 'A skill is write_skill with name, description, and source. The same name updates a custom skill. source is the SKILL.md instructions. A project file is filesystem with action write. A wiki file is wiki_memory with action write. Send the full content.';
                } elseif ($proseTools) {
                    $prompt = 'That was not a tool call. Do not write : tool_name or name a tool in prose. Do not open a markdown code fence. Emit exactly:' . "\n" . '<<tool>>' . "\n" . '{"name":"tool_name","args":{}}' . "\n" . '<<end>>';
                    if (preg_match('/\bdelegat/i', $turnText)) {
                        $prompt = 'That delegation was not run. Do not describe it. Emit the <<tool>> JSON block for kanban so the card is in_progress, then the <<tool>> JSON block for delegate. Pass task_id when the card already exists.';
                    } elseif (($xmlTool = $this->fakeXmlToolName($turnText)) !== null) {
                        $prompt = 'XML tags are not a tool call. Do not write <' . $xmlTool . '>. Emit exactly:' . "\n" . '<<tool>>' . "\n" . '{"name":"' . $xmlTool . '","args":{}}' . "\n" . '<<end>>' . "\n" . 'Fill args with the real JSON for that tool, then stop.';
                    } elseif ($this->claimedSkillWithoutCall($turnText)) {
                        $prompt = 'That did not register a skill. Do not write it into the project and do not open a code fence. Call write_skill now with name, description, and source, then stop. The same name updates a custom skill.';
                    } elseif ($this->claimedFileWriteWithoutCall($turnText)) {
                        $prompt = $this->claimedWriteRetryPrompt($turnText);
                    }
                } elseif ($ranOut) {
                    $prompt = $agent === 'orchestrator'
                        ? 'That reply used the whole output limit while thinking and wrote nothing. Keep this reply short. Do not restate the plan. Split the remaining work into smaller tasks and delegate the next one. One tool call, then stop.'
                        : 'That reply used the whole output limit while thinking and wrote nothing. Keep this reply short. Do one small step and call the tool. Then stop.';
                    $this->llm->brief = true;
                    $note = 'The reply ran out of room while thinking. Continuing with a shorter step.';
                    $this->postNotice($emit, $projectId, $agent, $note, $scheduled);
                } else {
                    $prompt = 'The reply stopped before the work finished. Continue that same task. If a tool still needs to run, call it now.';
                }
                $messages[] = ['role' => 'user', 'content' => $prompt];
                $this->llm->incomplete = false;
                $this->llm->ranOut = false;
                continue;
            }
            $retryOpen = 0;
            if (!$tail['tools']) {
                if ($agent === 'orchestrator' && $finishChecks < 2 && $this->finishCheckSaysContinue($messages, $turnText, $scheduled, $shouldStop)) {
                    $finishChecks++;
                    $this->db->loop($agent, 'steer', 'finish-check continue · ' . $finishChecks . '/2', $projectId);
                    $skipCompact = true;
                    $replay = trim($turnText);
                    if ($replay === '') {
                        $replay = trim($visible);
                    }
                    if ($replay === '') {
                        $replay = 'The previous reply was empty.';
                    }
                    $messages[] = ['role' => 'assistant', 'content' => strip_chat_tags($replay)];
                    $messages[] = ['role' => 'user', 'content' => $this->finishSteer($scheduled)];
                    continue;
                }
                $this->db->loop($agent, 'stop', 'completed', $projectId);
                $this->db->setAgentStatus($agent, 'completed');
                $emit(['type' => 'status', 'agent' => $agent, 'status' => 'completed']);
                return $this->finishVisible($visible);
            }
            $messages[] = ['role' => 'assistant', 'content' => strip_chat_tags($raw !== '' ? $raw : $turnText)];
            $delegated = false;
            $savedThisTurn = '';
            foreach ($tail['tools'] as $tool) {
                if ($shouldStop && $shouldStop()) {
                    throw new ChatStopped('Stopped.');
                }
                $toolName = $this->knownTool((string)($tool['name'] ?? ''), $toolHandlers);
                $tool['name'] = $toolName;
                $toolArgs = is_array($tool['args'] ?? null) ? $tool['args'] : [];
                $writePath = $this->repeatWritePath($toolName, $toolArgs);
                if ($writePath !== '' && $writePath === $writeStreakPath && $writeStreak >= 2) {
                    $this->db->loop($agent, 'stop', 'write-streak · ' . $writePath, $projectId);
                    $this->db->setAgentStatus($agent, 'completed');
                    $emit(['type' => 'status', 'agent' => $agent, 'status' => 'completed']);
                    return 'Saved ' . $writePath . '.';
                }
                if ($agent === 'orchestrator' && ($tool['name'] ?? '') === 'delegate') {
                    if ($delegated) {
                        $skipped = 'Only one subagent can run at a time. This delegation was not started. Wait for the current result, then delegate the next task.';
                        $this->db->loop($agent, 'tool', 'delegate skipped · one at a time', $projectId);
                        $emit(['type' => 'tool_call', 'agent' => $agent, 'name' => 'delegate', 'args' => $tool['args']]);
                        $emit(['type' => 'tool_result', 'agent' => $agent, 'name' => 'delegate', 'text' => $skipped]);
                        $messages[] = ['role' => 'user', 'content' => "TOOL RESULT delegate:\n{$skipped}"];
                        continue;
                    }
                    $delegated = true;
                }
                $this->db->setAgentStatus($agent, 'executing_tool');
                $emit(['type' => 'status', 'agent' => $agent, 'status' => 'executing_tool']);
                $emit(['type' => 'tool_call', 'agent' => $agent, 'name' => $tool['name'], 'args' => $tool['args']]);
                $this->db->logAgent($agent, $projectId, 'tool_call', $tool['name'] . ' ' . json_encode($tool['args'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                $this->db->loop($agent, 'tool', $this->toolTrace($toolName, $toolArgs), $projectId);
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
                if ($this->recordsWrite($toolName, $toolArgs)) {
                    if ($writePath !== '' && $this->writeSucceeded($result)) {
                        if ($writePath === $writeStreakPath) {
                            $writeStreak++;
                        } else {
                            $writeStreakPath = $writePath;
                            $writeStreak = 1;
                        }
                        $savedThisTurn = $writePath;
                        if ($writeStreak >= 2) {
                            $result .= "\nThat file is already saved. Do not write it again. Reply with the path and stop.";
                        }
                    }
                } else {
                    $writeStreakPath = '';
                    $writeStreak = 0;
                }
                $result = clip_text($result);
                $this->db->loop($agent, 'tool', $this->toolResultTrace($toolName, $result), $projectId);
                $this->db->logAgent($agent, $projectId, 'output', $result);
                $emit(['type' => 'tool_result', 'agent' => $agent, 'name' => $tool['name'], 'text' => $result]);
                if ($toolName === 'web_search' && str_starts_with($result, 'The search provider rejected this request.')) {
                    $this->db->loop($agent, 'stop', 'search provider rejected', $projectId);
                    $this->db->setAgentStatus($agent, 'completed');
                    $emit(['type' => 'status', 'agent' => $agent, 'status' => 'completed']);
                    return $this->finishVisible($result);
                }
                $messages[] = ['role' => 'user', 'content' => "TOOL RESULT {$tool['name']}:\n{$result}"];
                if ($this->kanbanFollowsDelegate($agent, (string)($tool['name'] ?? ''), $result)) {
                    $this->db->loop($agent, 'steer', 'kanban after delegate', $projectId);
                    $messages[] = ['role' => 'user', 'content' => 'The delegated job just finished. List the kanban and read the result the subagent added. Check that the work was done, then you move or update the matching cards before you reply or delegate again. A subagent cannot move or edit a card. Do this even if you have no recollection of any tasks in this chat. A cleared or compressed context still has cards. Move a finished run-once card to review with a short result. Leave a repeating card in in_progress. Move the card you are starting to in_progress. You never mark a task done. Do not explain the board workflow in place of listing it and updating the cards.'];
                }
            }
            if ($cutOff) {
                $skipCompact = true;
                $this->db->loop($agent, 'steer', $savedThisTurn !== '' ? 'already saved · ' . $savedThisTurn : 'cutoff · after tools', $projectId);
                $messages[] = ['role' => 'user', 'content' => $savedThisTurn !== ''
                    ? 'That file is already saved. Do not write it again. Reply with the path and stop.'
                    : 'Your last tool call was cut off before it finished. Repeat that same tool call and finish it before doing anything else.'];
            }
        }
        $this->db->loop($agent, 'stop', 'max-turns ' . $maxTurns, $projectId);
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

    private function recordsWrite(string $tool, array $args): bool
    {
        if ($tool === 'write_file' || $tool === 'wiki_write') {
            return true;
        }
        $action = strtolower(trim((string)($args['action'] ?? '')));
        return ($tool === 'filesystem' && $action === 'write') || ($tool === 'wiki_memory' && $action === 'write');
    }

    private function knownTool(string $name, array $handlers): string
    {
        $name = strtolower(trim($name));
        if ($name === '' || isset($handlers[$name])) {
            return $name;
        }
        $needle = str_replace('-', '_', $name);
        foreach (array_keys($handlers) as $known) {
            if (str_replace('-', '_', (string)$known) === $needle) {
                return (string)$known;
            }
        }
        return $name;
    }

    private function repeatWritePath(string $tool, array $args): string
    {
        $action = strtolower(trim((string)($args['action'] ?? '')));
        if ($tool === 'filesystem' && $action === 'write') {
            $tool = 'write_file';
        } elseif ($tool === 'wiki_memory' && $action === 'write') {
            $tool = 'wiki_write';
        }
        if ($tool !== 'write_file' && $tool !== 'wiki_write') {
            return '';
        }
        $raw = '';
        if ($tool === 'write_file') {
            $raw = (string)($args['path'] ?? '');
        } else {
            foreach (['path', 'file', 'filepath', 'name'] as $key) {
                $value = trim((string)($args[$key] ?? ''));
                if ($value !== '') {
                    $raw = $value;
                    break;
                }
            }
        }
        $path = str_replace('\\', '/', trim($raw));
        $path = preg_replace('#/+#', '/', $path) ?? $path;
        $path = ltrim($path, '/');
        while (str_starts_with($path, './')) {
            $path = substr($path, 2);
        }
        if ($path === '' || str_contains($path, '..')) {
            return '';
        }
        if ($tool === 'wiki_write' && !str_contains($path, '/')) {
            $path = 'wiki/' . basename($path);
        }
        return $path;
    }

    private function writeSucceeded(string $result): bool
    {
        return str_starts_with($result, 'Wrote ') || str_starts_with($result, 'Updated ');
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
            'delegate', 'filesystem', 'list_files', 'read_file', 'read_image', 'read_pdf', 'screenshot', 'write_file', 'delete_file', 'move_file',
            'create_pdf', 'run_command', 'run_python', 'py_install', 'kanban', 'wiki_memory', 'wiki_search', 'wiki_read', 'wiki_write',
            'wiki_reindex', 'list_vault', 'read_vault', 'restore_file', 'write_skill', 'test_skill', 'remember', 'send_email',
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
        $prompt = 'You described a wiki or file write but did not call a tool. Showing markdown in chat is not a write. Emit the <<tool>> JSON block for wiki_memory with action write, or filesystem with action write, now with the full content. Do not say it is done until the tool result returns.';
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
        if ($instruction === '' || !in_array($agent, ['researcher', 'writer', 'coder', 'analyst', 'marketer', 'consultant'], true)) {
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
            $this->db->loop($agent, 'stop', 'repeat-line');
            $note = "\n\nStopped because the same line was repeating.";
            $visible .= $note;
            $emit(['type' => 'token', 'agent' => $agent, 'text' => $note]);
            $this->db->setAgentStatus($agent, 'completed');
            $emit(['type' => 'status', 'agent' => $agent, 'status' => 'completed']);
            return false;
        }
        $tries++;
        $shown = preg_replace('/\s+/', ' ', trim($line)) ?? '';
        if (strlen($shown) > 60) {
            $shown = substr($shown, 0, 59) . '…';
        }
        $this->db->loop($agent, 'steer', 'repeat-line · try ' . $tries . '/3' . ($shown !== '' ? ' · ' . $shown : ''));
        $skipCompact = true;
        if ($kept !== '') {
            $messages[] = ['role' => 'assistant', 'content' => strip_chat_tags($kept)];
        }
        $cue = 'You wrote the same line more than five times in a row. Stop repeating it. Continue the task from the last useful step. If a tool still needs to run, call it now.';
        if ($line !== '') {
            $cue = "You wrote this line more than five times in a row:\n" . clip_text($line, 400) . "\nStop repeating it. Continue the task from the last useful step. If a tool still needs to run, call it now.";
        }
        $messages[] = ['role' => 'user', 'content' => $cue];
        return true;
    }

    private function finishCheckSaysContinue(array $messages, string $reply, bool $scheduled, ?callable $shouldStop): bool
    {
        if ($shouldStop && $shouldStop()) {
            throw new ChatStopped('Stopped.');
        }
        $prompt = $this->finishCheckPrompt($messages, $reply, $scheduled);
        $this->db->loop('orchestrator', 'model', 'finish-check · ' . strlen($prompt) . ' chars');
        $verdict = $this->llm->yesNo($prompt, $shouldStop);
        if ($shouldStop && $shouldStop()) {
            throw new ChatStopped('Stopped.');
        }
        if ($verdict === null) {
            $this->db->loop('orchestrator', 'process', 'finish-check skipped');
        } else {
            $this->db->loop('orchestrator', 'process', $verdict ? 'finish-check done' : 'finish-check not done');
        }
        return $verdict === false;
    }

    private function finishCheckPrompt(array $messages, string $reply, bool $scheduled): string
    {
        $request = clip_text(trim($this->latestUserRequest($messages)), 1500);
        $tools = $this->recentToolResults($messages);
        $reply = clip_text(trim($reply), 1500);
        if ($scheduled) {
            $question = 'This is a scheduled task and nobody is here to answer. Is that task finished? Answer yes or no. yes only if the task is finished. A question to the user is no.';
        } else {
            $question = 'Is the job finished, or is the agent only waiting for the user? Answer yes or no. yes = the requested work is done, or the agent asked the user a real question, or it is waiting for the user to choose, including now versus scheduled. no = work is still unfinished. The last tool failed and was not fixed, or the reply describes a next step it did not take.';
        }
        return $question . "\n\nUser request:\n" . ($request !== '' ? $request : '(none)')
            . "\n\nRecent tool results:\n" . ($tools !== '' ? $tools : '(none)')
            . "\n\nAgent reply:\n" . ($reply !== '' ? $reply : '(empty)')
            . "\n\nAnswer yes or no.";
    }

    private function latestUserRequest(array $messages): string
    {
        for ($i = count($messages) - 1; $i >= 0; $i--) {
            $message = $messages[$i];
            if (!is_array($message) || ($message['role'] ?? '') !== 'user') {
                continue;
            }
            $content = ltrim((string)($message['content'] ?? ''));
            if ($content === '' || str_starts_with($content, 'TOOL RESULT ') || $this->isRuntimeSteer($content)) {
                continue;
            }
            return $content;
        }
        return '';
    }

    private function recentToolResults(array $messages): string
    {
        $found = [];
        foreach ($messages as $message) {
            if (!is_array($message) || ($message['role'] ?? '') !== 'user') {
                continue;
            }
            $content = (string)($message['content'] ?? '');
            if (!str_starts_with($content, 'TOOL RESULT ')) {
                continue;
            }
            $found[] = $content;
        }
        $lines = [];
        foreach (array_slice($found, -3) as $content) {
            $lines[] = clip_text($content, 600);
        }
        return implode("\n\n", $lines);
    }

    private function isRuntimeSteer(string $content): bool
    {
        foreach ([
            'Your last tool call was cut off',
            'That reply was an empty code fence',
            'That was not a tool call',
            'That delegation was not run',
            'XML tags are not a tool call',
            'That did not register a skill',
            'The reply stopped before the work finished',
            'That reply used the whole output limit',
            'The delegated job just finished',
            'You described a wiki or file write',
            'That file is already saved',
            'You wrote the same line',
            'You wrote this line more than five times',
            'The job is not finished',
            'The scheduled task is not finished',
        ] as $prefix) {
            if (str_starts_with($content, $prefix)) {
                return true;
            }
        }
        return false;
    }

    private function finishSteer(bool $scheduled): string
    {
        if ($scheduled) {
            return 'The scheduled task is not finished. Nobody is here to answer. If the last tool failed, fix that call and run it again. Choose the sensible option and finish the task. Call the tool that is still needed. Do not only describe it.';
        }
        return 'The job is not finished. You stopped before the work was done. If the last tool failed, fix that call and run it again. If you described a next step, do it now with a real tool call. Do not only describe it.';
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
        return strip_chat_tags($text);
    }

    private function releaseMarker(OutputLineFilter $markers, ToolStreamParser $parser): string
    {
        return $parser->push($markers->finish());
    }

    private function flushRecoveries(callable $emit, ?int $projectId, string $agent, bool $scheduled = false): void
    {
        foreach ($this->llm->recoveries as $line) {
            $this->postNotice($emit, $projectId, $agent, (string)$line, $scheduled);
        }
        $this->llm->recoveries = [];
    }

    private function traceFilters(string $agent, ?int $projectId, OutputLineFilter $markers, ReplyNoiseFilter $noise, RepeatLineGuard $guard): void
    {
        if ($markers->dropped === 0 && $noise->asideChars === 0 && !$guard->tripped) {
            return;
        }
        $parts = [];
        if ($markers->dropped > 0) {
            $parts[] = 'output markers ' . $markers->dropped;
        }
        if ($noise->asideChars > 0) {
            $parts[] = 'noise ' . $noise->asideChars . ' chars';
        }
        if ($guard->tripped) {
            $repeated = preg_replace('/\s+/', ' ', trim($guard->repeated)) ?? '';
            if (strlen($repeated) > 60) {
                $repeated = substr($repeated, 0, 59) . '…';
            }
            $parts[] = 'repeat' . ($repeated !== '' ? ' · ' . $repeated : '');
        }
        $this->db->loop($agent, 'filter', implode(' · ', $parts), $projectId);
    }

    private function traceOutcome(string $agent, ?int $projectId, string $turnText, array $tail, bool $cutOff): void
    {
        $reason = $this->llm->doneReason !== '' ? $this->llm->doneReason : 'stop';
        $line = 'done ' . $reason . ' · tools ' . count($tail['tools'] ?? []) . ' · visible ' . strlen($turnText) . ' chars';
        $flags = [];
        if ($this->llm->incomplete) {
            $flags[] = 'incomplete';
        }
        if ($this->llm->ranOut) {
            $flags[] = 'ran out';
        }
        if ($cutOff) {
            $flags[] = 'cutoff';
        }
        if ($flags !== []) {
            $line .= ' · ' . implode(' · ', $flags);
        }
        $this->db->loop($agent, 'model', $line, $projectId);
    }

    private function toolTrace(string $name, array $args): string
    {
        $detail = '';
        foreach (['path', 'file', 'filepath', 'agent', 'name'] as $key) {
            $value = trim((string)($args[$key] ?? ''));
            if ($value !== '') {
                $detail = $key . '=' . $value;
                break;
            }
        }
        $size = 0;
        foreach (['content', 'source', 'text', 'instruction', 'body'] as $key) {
            if (isset($args[$key]) && is_string($args[$key])) {
                $size += strlen($args[$key]);
            }
        }
        $line = $name !== '' ? $name : 'tool';
        if ($detail !== '') {
            $line .= ' · ' . $detail;
        }
        $line .= ' · args ' . count($args);
        if ($size > 0) {
            $line .= ' · content ' . $size . ' chars';
        }
        return $line;
    }

    private function toolResultTrace(string $name, string $result): string
    {
        $failed = str_starts_with($result, 'Error:');
        $flat = preg_replace('/\s+/', ' ', trim($result)) ?? '';
        if (strlen($flat) > 80) {
            $flat = substr($flat, 0, 79) . '…';
        }
        $line = ($name !== '' ? $name : 'tool') . ' · ' . ($failed ? 'error' : 'ok') . ' · ' . strlen($result) . ' chars';
        if ($flat !== '') {
            $line .= ' · ' . $flat;
        }
        return $line;
    }

    private function postNotice(callable $emit, ?int $projectId, string $agent, string $text, bool $scheduled, string $logType = 'error'): void
    {
        $text = trim($text);
        if ($text === '') {
            return;
        }
        if ($scheduled) {
            $this->db->logAgent($agent, $projectId, $logType, $text);
            $emit(['type' => 'task_note', 'text' => $text]);
            return;
        }
        $this->db->addMessage($projectId ?? 0, 'notice', $text, $agent);
        $emit(['type' => 'notice', 'text' => $text]);
    }
}
