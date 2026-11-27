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
        return strcasecmp($text, 'output') === 0 || strcasecmp($text, '<<output>>') === 0 || strcasecmp($text, 'Tool call:') === 0;
    }

    private function couldBeMarker(string $text): bool
    {
        $trim = trim($text, " \t\r");
        if ($trim === '') {
            return true;
        }
        foreach (['output', '<<output>>', 'Tool call:'] as $marker) {
            if (strlen($trim) <= strlen($marker) && strncasecmp($trim, $marker, strlen($trim)) === 0) {
                return true;
            }
        }
        return false;
    }
}

class ToolEchoFilter
{
    private string $pending = '';

    public function __construct(private array $toolNames)
    {
    }

    public function push(string $token): string
    {
        if ($token === '') {
            return '';
        }
        $this->pending .= $token;
        return $this->drain(false);
    }

    public function finish(): string
    {
        $text = $this->drain(true);
        $this->pending = '';
        return $text;
    }

    public function strip(string $text): string
    {
        return $this->push($text) . $this->finish();
    }

    private function drain(bool $end): string
    {
        $out = '';
        while ($this->pending !== '') {
            $at = $this->findEchoStart($this->pending);
            if ($at === null) {
                $hold = $end ? 0 : $this->suffixHold($this->pending);
                $cut = strlen($this->pending) - $hold;
                if ($cut > 0) {
                    $out .= substr($this->pending, 0, $cut);
                    $this->pending = substr($this->pending, $cut);
                }
                break;
            }
            if ($at > 0) {
                $out .= substr($this->pending, 0, $at);
                $this->pending = substr($this->pending, $at);
            }
            $len = $this->echoLength($this->pending, $end);
            if ($len === null) {
                break;
            }
            if ($len <= 0) {
                $out .= substr($this->pending, 0, 1);
                $this->pending = substr($this->pending, 1);
                continue;
            }
            $this->pending = substr($this->pending, $len);
        }
        return $out;
    }

    private function findEchoStart(string $text): ?int
    {
        $best = null;
        foreach (['called a tool', 'the tool already ran'] as $phrase) {
            if (preg_match_all('/' . preg_quote($phrase, '/') . '/i', $text, $hits, PREG_OFFSET_CAPTURE)) {
                foreach ($hits[0] as $hit) {
                    $at = $hit[1];
                    if ($at > 0 && preg_match('/[A-Za-z]/', $text[$at - 1])) {
                        continue;
                    }
                    if ($best === null || $at < $best) {
                        $best = $at;
                    }
                }
            }
        }
        $length = strlen($text);
        foreach ($this->toolNames as $name) {
            $from = 0;
            while ($from < $length) {
                $at = stripos($text, $name, $from);
                if ($at === false) {
                    break;
                }
                $from = $at + 1;
                if ($at > 0 && preg_match('/[A-Za-z0-9_]/', $text[$at - 1])) {
                    continue;
                }
                $after = $at + strlen($name);
                if ($after < $length && preg_match('/[A-Za-z0-9_]/', $text[$after])) {
                    continue;
                }
                if ($best === null || $at < $best) {
                    $best = $at;
                }
            }
        }
        return $best;
    }

    private function echoLength(string $text, bool $end): ?int
    {
        foreach (['called a tool', 'the tool already ran'] as $phrase) {
            if (preg_match('/\A' . preg_quote($phrase, '/') . '\.?/i', $text, $match)) {
                return strlen($match[0]);
            }
            if ($this->isPrefix($text, $phrase)) {
                return $end ? 0 : null;
            }
        }
        $name = $this->nameAt($text, 0);
        if ($name === null) {
            if (!$end && $this->isToolPrefix($text)) {
                return null;
            }
            return 0;
        }
        $after = strlen($name);
        if (isset($text[$after]) && ($text[$after] === ' ' || $text[$after] === "\t")) {
            $probe = $after + 1;
            while (isset($text[$probe]) && ($text[$probe] === ' ' || $text[$probe] === "\t")) {
                $probe++;
            }
            $second = $this->nameAt($text, $probe);
            if ($second !== null && strcasecmp($second, $name) === 0) {
                $parenAt = $probe + strlen($second);
                if (!isset($text[$parenAt])) {
                    return $end ? 0 : null;
                }
                if ($text[$parenAt] === '(') {
                    $close = $this->parenEnd($text, $parenAt);
                    return $close === null ? ($end ? strlen($text) : null) : $close;
                }
            } elseif ($second === null && !$end && $this->isToolPrefix(ltrim(substr($text, $after)))) {
                return null;
            }
        }
        $cursor = $after;
        while (isset($text[$cursor]) && ($text[$cursor] === ' ' || $text[$cursor] === "\t")) {
            $cursor++;
        }
        if (!isset($text[$cursor])) {
            return $end ? 0 : null;
        }
        if ($text[$cursor] === '{') {
            $close = $this->jsonEnd($text, $cursor);
            return $close === null ? ($end ? strlen($text) : null) : $close;
        }
        if ($text[$cursor] === '(') {
            $close = $this->parenEnd($text, $cursor);
            return $close === null ? ($end ? strlen($text) : null) : $close;
        }
        return 0;
    }

    private function suffixHold(string $text): int
    {
        $length = strlen($text);
        $max = min($length, 80);
        for ($hold = $max; $hold > 0; $hold--) {
            if ($hold < $length && preg_match('/[A-Za-z0-9_]/', $text[$length - $hold - 1])) {
                continue;
            }
            if ($this->couldGrow(substr($text, $length - $hold))) {
                return $hold;
            }
        }
        return 0;
    }

    private function couldGrow(string $text): bool
    {
        if ($text === '') {
            return false;
        }
        if ($this->isPrefix($text, 'called a tool') || $this->isPrefix($text, 'the tool already ran')) {
            return true;
        }
        if ($this->nameAt($text, 0) !== null) {
            return $this->echoLength($text, false) === null;
        }
        return $this->isToolPrefix($text);
    }

    private function nameAt(string $text, int $at): ?string
    {
        if ($at < 0 || $at >= strlen($text)) {
            return null;
        }
        if ($at > 0 && preg_match('/[A-Za-z0-9_]/', $text[$at - 1])) {
            return null;
        }
        $rest = substr($text, $at);
        $best = null;
        foreach ($this->toolNames as $name) {
            $n = strlen($name);
            if (strlen($rest) < $n || strncasecmp($rest, $name, $n) !== 0) {
                continue;
            }
            $after = $rest[$n] ?? '';
            if ($after !== '' && preg_match('/[A-Za-z0-9_]/', $after)) {
                continue;
            }
            if ($best === null || $n > strlen($best)) {
                $best = $name;
            }
        }
        return $best;
    }

    private function isToolPrefix(string $text): bool
    {
        if ($text === '' || preg_match('/\A[A-Za-z0-9_]+$/', $text) !== 1) {
            return false;
        }
        foreach ($this->toolNames as $name) {
            if (strlen($text) < strlen($name) && strncasecmp($name, $text, strlen($text)) === 0) {
                return true;
            }
        }
        return false;
    }

    private function isPrefix(string $text, string $phrase): bool
    {
        $n = strlen($text);
        if ($n === 0 || $n > strlen($phrase)) {
            return false;
        }
        return strncasecmp($text, $phrase, $n) === 0;
    }

    private function jsonEnd(string $text, int $at): ?int
    {
        return $this->groupEnd($text, $at, '{', '}');
    }

    private function parenEnd(string $text, int $at): ?int
    {
        return $this->groupEnd($text, $at, '(', ')');
    }

    private function groupEnd(string $text, int $at, string $open, string $close): ?int
    {
        $length = strlen($text);
        $depth = 0;
        $quote = '';
        $escape = false;
        for ($i = $at; $i < $length; $i++) {
            $ch = $text[$i];
            if ($quote !== '') {
                if ($escape) {
                    $escape = false;
                    continue;
                }
                if ($ch === '\\') {
                    $escape = true;
                    continue;
                }
                if ($ch === $quote) {
                    $quote = '';
                }
                continue;
            }
            if ($ch === '"' || $ch === "'") {
                $quote = $ch;
                continue;
            }
            if ($ch === $open) {
                $depth++;
                continue;
            }
            if ($ch === $close) {
                $depth--;
                if ($depth === 0) {
                    return $i + 1;
                }
            }
        }
        return null;
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
        if ($scheduled) {
            $this->llm->stallAfter(LLMClient::STALL_SECONDS);
        }
        $visible = '';
        $retryOpen = 0;
        $sameRefusal = [];
        $roomNoted = false;
        $heldBrief = $this->llm->keepBrief;
        $repeatTries = 0;
        $fallbackTries = 0;
        $finishChecks = 0;
        $planSteers = 0;
        $calledTries = 0;
        $skipCompact = false;
        $savedWritePath = '';
        $savedWriteBody = null;
        $keptThinking = '';
        $carryThought = '';
        $dropCarryThought = false;
        try {
        for ($turn = 0; $turn < $maxTurns; $turn++) {
            if ($shouldStop && $shouldStop()) {
                throw new ChatStopped('Stopped.');
            }
            if ($dropCarryThought) {
                $dropCarryThought = false;
            } else {
                $keptThinking .= $carryThought;
            }
            $carryThought = '';
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
            $echo = new ToolEchoFilter($this->toolNames());
            $leak = new ToolResultLeakFilter();
            $turnText = '';
            $guard = new RepeatLineGuard();
            $thinkingGuard = new RepeatLineGuard();
            $visibleMark = strlen($visible);
            $showLeak = function (string $text) use ($leak, $emit, $agent, &$visible, $visibleMark): void {
                if ($text === '') {
                    return;
                }
                if ($visibleMark > 0 && strlen($visible) === $visibleMark) {
                    $text = $this->spaceAfterSentence($visible, $text);
                }
                $visible .= $text;
                $emit(['type' => 'token', 'agent' => $agent, 'text' => $text]);
            };
            $flushEcho = function () use ($echo, $leak, $showLeak): void {
                $showLeak($leak->push($echo->finish()) . $leak->finish());
            };
            $show = function (string $text) use ($guard, $echo, $leak, $showLeak, &$turnText): void {
                $text = $guard->push($text);
                if ($text === '') {
                    return;
                }
                $turnText .= $text;
                $text = $echo->push($text);
                if ($text === '') {
                    return;
                }
                $showLeak($leak->push($text));
            };
            $toolNoted = false;
            $thinkBuf = '';
            $thinkAt = 0.0;
            $flushThinking = function (bool $force = false) use (&$thinkBuf, &$thinkAt, &$carryThought, &$keptThinking, $emit, $agent): void {
                if ($thinkBuf === '') {
                    return;
                }
                if (!$force && strlen($thinkBuf) < 800 && (microtime(true) - $thinkAt) < 0.35) {
                    return;
                }
                $chunk = $thinkBuf;
                $thinkBuf = '';
                $thinkAt = microtime(true);
                if ($carryThought === '' && $keptThinking !== '' && !preg_match('/\s$/', $keptThinking)) {
                    $chunk = "\n\n" . $chunk;
                }
                $carryThought .= $chunk;
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
                $flushEcho();
                $this->traceFilters($agent, $projectId, $markers, $noise, $guard, $leak);
                $this->db->setAgentStatus($agent, 'idle');
                throw $e;
            } catch (OutputRepeated $e) {
                $finishNoise();
                $flushThinking(true);
                $this->flushRecoveries($emit, $projectId, $agent, $scheduled);
                $repeatGuard = $thinkingGuard->tripped ? $thinkingGuard : $guard;
                $flushEcho();
                $this->traceFilters($agent, $projectId, $markers, $noise, $repeatGuard, $leak);
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
                $flushEcho();
                $this->traceFilters($agent, $projectId, $markers, $noise, $guard, $leak);
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
                $showLeak($leak->finish());
                $this->traceFilters($agent, $projectId, $markers, $noise, $guard, $leak);
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
                $turnText .= $rest;
            }
            $rest = ($rest === '' ? '' : $echo->push($rest)) . $echo->finish();
            $showLeak($leak->push($rest) . $leak->finish());
            if ($turnText !== '') {
                $this->db->logAgent($agent, $projectId, 'thinking', $turnText);
            }
            $this->traceFilters($agent, $projectId, $markers, $noise, $guard, $leak);
            $this->traceOutcome($agent, $projectId, $turnText, $tail, $cutOff);
            if ($guard->tripped) {
                if (!$this->steerAfterRepeat($repeatTries, $messages, $guard->singleCopy($turnText), $guard->repeated, $visible, $emit, $agent, $skipCompact)) {
                    return $this->finishVisible($visible);
                }
                continue;
            }
            $repeatTries = 0;
            $wrongTool = !$tail['tools'] && !empty($tail['rejected']);
            if ($wrongTool) {
                $cutOff = false;
            }
            if ($this->mainVoice($agent) && !$tail['tools'] && !$cutOff && !$this->llm->incomplete) {
                $inferred = $this->proseDelegation($turnText);
                if ($inferred !== null) {
                    $tail['tools'] = [$inferred];
                    $this->db->loop($agent, 'steer', 'prose delegate · ' . (string)($inferred['args']['agent'] ?? ''), $projectId);
                }
            }
            $openStep = !$tail['tools'] && !$cutOff && !$wrongTool && !$this->llm->incomplete && $this->replyLeavesWorkOpen($turnText);
            $proseTools = !$tail['tools'] && !$cutOff && ($wrongTool || $openStep || (!$this->llm->incomplete && $this->announcedToolsWithoutCall($turnText)));
            $emptyFences = !$tail['tools'] && !$cutOff && !$this->llm->incomplete && $this->emptyPayloadFences($turnText);
            if (!$tail['tools'] && ($cutOff || $this->llm->incomplete || $proseTools || $emptyFences)) {
                $ranOut = $this->llm->ranOut;
                if ($retryOpen >= 5 && !$ranOut) {
                    $narrating = ($openStep || $proseTools) && !$emptyFences && !$cutOff;
                    if ($narrating) {
                        $this->db->loop($agent, 'stop', 'narration · retry limit', $projectId);
                        $this->db->setAgentStatus($agent, 'completed');
                        $emit(['type' => 'status', 'agent' => $agent, 'status' => 'completed']);
                        return trim($this->narrationResult());
                    }
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
                } elseif ($wrongTool) {
                    $why = 'wrong tool';
                } elseif ($openStep && !$this->announcedToolsWithoutCall($turnText)) {
                    $why = 'unfinished step';
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
                $replayBody = trim($replay . ($cutOff ? $tail['text'] : ''));
                if ($wrongTool) {
                    $replayBody = trim(strip_tool_markers($replayBody));
                    if ($replayBody === '') {
                        $replayBody = 'The previous reply was not a tool call.';
                    }
                }
                $messages[] = ['role' => 'assistant', 'content' => $this->modelSaw(strip_chat_tags($replayBody))];
                if ($cutOff) {
                    $prompt = 'Your last tool call was cut off before it finished. Repeat that same tool call and finish it before doing anything else.';
                } elseif ($emptyFences) {
                    $prompt = 'That reply was an empty code fence, so nothing was called or saved. ```markdown is not a tool call. Do not output ``` at all. Do not ask a question. Emit one tool call and stop:' . "\n" . '<<tool>>' . "\n" . '{"name":"tool_name","args":{}}' . "\n" . '<<end>>' . "\n" . 'A skill is write_skill with name, description, and source. Pass overwrite true to change an existing custom skill. source is the SKILL.md instructions. A project file is filesystem with action write. A wiki file is wiki_memory with action write. Send the full content.';
                } elseif ($proseTools) {
                    $prompt = 'That was not a tool call. Do not write : tool_name or name a tool in prose. Do not open a markdown code fence. Emit exactly:' . "\n" . '<<tool>>' . "\n" . '{"name":"tool_name","args":{}}' . "\n" . '<<end>>';
                    if ($openStep && !$this->announcedToolsWithoutCall($turnText)) {
                        $prompt = 'You described the next step instead of doing it. Do not only say you will check, add, or save. Emit the tool call now:' . "\n" . '<<tool>>' . "\n" . '{"name":"tool_name","args":{}}' . "\n" . '<<end>>';
                    }
                    if ($wrongTool) {
                        $prompt = 'That was not a tool call. Do not write <|tool_code_begin|>, <invoke>, or a line that only says Tool call:. Emit exactly:' . "\n" . '<<tool>>' . "\n" . '{"name":"tool_name","args":{}}' . "\n" . '<<end>>';
                    }
                    if (preg_match('/\bdelegat/i', $turnText)) {
                        $prompt = $agent === 'scheduler'
                            ? 'That delegation was not run. Emit one delegate tool call for the open step and stop:' . "\n" . '<<tool>>' . "\n" . '{"name":"delegate","args":{"agent":"researcher","task_id":0}}' . "\n" . '<<end>>' . "\n" . 'Use the agent on that step and the task id from this scheduled task. Do not write a new instruction. Do not call research_loop. Do not create a task. Do not rewrite the steps.'
                            : 'That delegation was not run. Do not describe it. Create the task with tasklist action create, list the tasks and read the new id. If the list says scheduled, do not delegate. The scheduler starts it. Do not invent an id. A number from an older message is not a task.';
                    } elseif (($xmlTool = $this->fakeXmlToolName($turnText)) !== null) {
                        $prompt = 'XML tags are not a tool call. Do not write <' . $xmlTool . '>. Emit exactly:' . "\n" . '<<tool>>' . "\n" . '{"name":"' . $xmlTool . '","args":{}}' . "\n" . '<<end>>' . "\n" . 'Fill args with the real JSON for that tool, then stop.';
                    } elseif ($this->claimedSkillWithoutCall($turnText)) {
                        $prompt = 'That did not register a skill. Do not write it into the project and do not open a code fence. Call write_skill now with name, description, and source, then stop. Pass overwrite true to change an existing custom skill.';
                    } elseif ($this->claimedFileWriteWithoutCall($turnText)) {
                        $prompt = $this->claimedWriteRetryPrompt($turnText);
                    }
                } elseif ($ranOut) {
                    $prompt = $this->mainVoice($agent)
                        ? 'That reply used the whole output limit while thinking and wrote nothing. Keep this reply short. Do not restate the plan. Split the remaining work into smaller tasks and delegate the next one. One tool call, then stop.'
                        : 'That reply used the whole output limit while thinking and wrote nothing. Keep this reply short. Do one small step and call the tool. Then stop.';
                    $this->llm->keepBrief = true;
                    if (!$roomNoted) {
                        $roomNoted = true;
                        $this->llm->brief = true;
                        $note = 'The reply ran out of room while thinking. Continuing with a shorter step.';
                        $this->postNotice($emit, $projectId, $agent, $note, $scheduled);
                    }
                } else {
                    $prompt = 'The reply stopped before the work finished. Continue that same task. If a tool still needs to run, call it now.';
                }
                $messages[] = ['role' => 'user', 'content' => $prompt];
                $this->llm->incomplete = false;
                $this->llm->ranOut = false;
                if ($why === 'prose tool' || $why === 'unfinished step') {
                    $this->rewindVisible($visible, $visibleMark, $emit, $agent);
                    if ($carryThought !== '') {
                        $emit(['type' => 'thinking_trim', 'agent' => $agent, 'text' => $carryThought]);
                    }
                    $dropCarryThought = true;
                }
                continue;
            }
            $retryOpen = 0;
            if (!$tail['tools']) {
                if ($this->onlyCalledATool($turnText)) {
                    $spoken = $this->delegationResultToSpeak($messages);
                    if ($spoken !== '') {
                        if ($calledTries >= 1) {
                            $this->db->loop($agent, 'stop', 'called-a-tool · spoke result', $projectId);
                            $this->db->setAgentStatus($agent, 'completed');
                            $emit(['type' => 'status', 'agent' => $agent, 'status' => 'completed']);
                            return $spoken;
                        }
                        $calledTries++;
                        $skipCompact = true;
                        $visible = $this->withoutCalledATool($visible);
                        $this->db->loop($agent, 'steer', 'called-a-tool · speak result', $projectId);
                        $messages[] = ['role' => 'assistant', 'content' => 'The last tool call failed.'];
                        $messages[] = ['role' => 'user', 'content' => "Tell the user this result and stop. Do not call a tool.\n" . $spoken];
                        continue;
                    }
                    $failed = $this->lastToolFailed($messages);
                    if ($failed && $calledTries >= 1) {
                        $this->db->loop($agent, 'stop', 'called-a-tool · failed tool', $projectId);
                        $this->db->setAgentStatus($agent, 'completed');
                        $emit(['type' => 'status', 'agent' => $agent, 'status' => 'completed']);
                        return 'Stopped. The last tool call failed, so it was not run again.';
                    }
                    if ($calledTries >= 4) {
                        $this->db->loop($agent, 'stop', 'called-a-tool · retry limit', $projectId);
                        $this->db->setAgentStatus($agent, 'completed');
                        $emit(['type' => 'status', 'agent' => $agent, 'status' => 'completed']);
                        return trim($this->narrationResult());
                    }
                    $calledTries++;
                    $skipCompact = true;
                    $visible = $this->withoutCalledATool($visible);
                    if ($failed) {
                        $this->db->loop($agent, 'steer', 'called-a-tool · failed · 1/1', $projectId);
                        $messages[] = ['role' => 'assistant', 'content' => 'The last tool call failed.'];
                        $messages[] = ['role' => 'user', 'content' => 'The last tool failed. Do not say that it ran. Emit one corrected tool call now:' . "\n" . '<<tool>>' . "\n" . '{"name":"tool_name","args":{}}' . "\n" . '<<end>>'];
                    } else {
                        $this->db->loop($agent, 'steer', 'called-a-tool · ' . $calledTries . '/4', $projectId);
                        $messages[] = ['role' => 'assistant', 'content' => 'The tool already ran.'];
                        $messages[] = ['role' => 'user', 'content' => 'Do not write "Called a tool." or "The tool already ran." or paste the tool call. The tool result is above. Continue the task. If another tool is needed, call it now.'];
                    }
                    continue;
                }
                if ($planSteers < 1 && $this->missingPlanReply($agent, $projectId, $messages, $turnText)) {
                    $planSteers++;
                    $skipCompact = true;
                    $this->rewindVisible($visible, $visibleMark, $emit, $agent);
                    $this->db->loop($agent, 'steer', 'plan not in wiki · 1/1', $projectId);
                    $replay = trim($turnText);
                    if ($replay === '') {
                        $replay = 'The plan was only described in chat.';
                    }
                    $messages[] = ['role' => 'assistant', 'content' => $this->modelSaw(strip_chat_tags($replay))];
                    $messages[] = ['role' => 'user', 'content' => $this->planSteer()];
                    continue;
                }
                if ($this->mainVoice($agent) && $this->finishCheckSaysContinue($messages, $turnText, $scheduled, $shouldStop, $agent)) {
                    if ($finishChecks >= 2) {
                        $this->db->loop($agent, 'steer', 'finish-check · still going', $projectId);
                        $finishChecks = 1;
                    }
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
                    $messages[] = ['role' => 'assistant', 'content' => $this->modelSaw(strip_chat_tags($replay))];
                    $messages[] = ['role' => 'user', 'content' => $this->finishSteer($scheduled)];
                    continue;
                }
                $this->db->loop($agent, 'stop', 'completed', $projectId);
                $this->db->setAgentStatus($agent, 'completed');
                $emit(['type' => 'status', 'agent' => $agent, 'status' => 'completed']);
                return $this->finishVisible($visible);
            }
            $finishChecks = 0;
            $calledTries = 0;
            $called = trim(strip_chat_tags(strip_tool_markers($raw !== '' ? $raw : $turnText)));
            if ($called === '' || $this->onlyCalledATool($called)) {
                $called = $this->calledTools($tail['tools']);
            }
            $messages[] = ['role' => 'assistant', 'content' => $this->modelSaw($called)];
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
                $writeBody = $this->repeatWriteBody($toolName, $toolArgs);
                if ($writeBody !== null && $writePath === $savedWritePath && $writeBody === $savedWriteBody) {
                    $this->db->loop($agent, 'stop', 'write-streak · ' . $writePath, $projectId);
                    $this->db->setAgentStatus($agent, 'completed');
                    $emit(['type' => 'status', 'agent' => $agent, 'status' => 'completed']);
                    return 'Saved ' . $writePath . '.';
                }
                if ($this->mainVoice($agent) && ($tool['name'] ?? '') === 'delegate') {
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
                } catch (ChatStopped $e) {
                    if ($agent !== 'orchestrator' || !$this->db->parallelChatRunning((int)$projectId)) {
                        $this->db->setAgentStatus($agent, 'idle');
                        $this->db->logAgent($agent, $projectId, 'stop', 'Stopped by user.');
                    }
                    throw $e;
                } catch (Throwable $e) {
                    $result = agent_error($agent, $e->getMessage());
                    $this->db->logAgent($agent, $projectId, 'error', $result);
                }
                if ($writeBody !== null && $writePath !== '' && $this->writeSucceeded($result)) {
                    $savedWritePath = $writePath;
                    $savedWriteBody = $writeBody;
                    $savedThisTurn = $writePath;
                }
                $result = clip_text($result);
                $this->db->loop($agent, 'tool', $this->toolResultTrace($toolName, $result), $projectId);
                $this->db->logAgent($agent, $projectId, 'output', $result);
                $emit(['type' => 'tool_result', 'agent' => $agent, 'name' => $tool['name'], 'text' => $result]);
                if ($toolName === 'delegate' && str_starts_with($result, 'Delegation rejected') && $this->singleNarrationLine($visible, $visibleMark)) {
                    $this->rewindVisible($visible, $visibleMark, $emit, $agent);
                }
                if ($toolName === 'web_search' && str_starts_with($result, 'The search provider rejected this request.')) {
                    $this->db->loop($agent, 'stop', 'search provider rejected', $projectId);
                    $this->db->setAgentStatus($agent, 'completed');
                    $emit(['type' => 'status', 'agent' => $agent, 'status' => 'completed']);
                    return $this->finishVisible($result);
                }
                $messages[] = ['role' => 'user', 'content' => "TOOL RESULT {$tool['name']}:\n{$result}"];
                $refused = $this->repeatedRefusal($result, $sameRefusal);
                if ($refused !== null) {
                    $this->db->loop($agent, 'stop', 'same refusal · ' . $refused, $projectId);
                    $this->db->setAgentStatus($agent, 'completed');
                    $emit(['type' => 'status', 'agent' => $agent, 'status' => 'completed']);
                    return $this->finishVisible($result);
                }
                if ($this->kanbanFollowsDelegate($agent, (string)($tool['name'] ?? ''), $result)) {
                    $this->db->loop($agent, 'steer', 'tasklist after delegate', $projectId);
                    $messages[] = ['role' => 'user', 'content' => 'The delegated job just finished. List the task list and read the result. If the id you passed is not in that list, the task was never created and nothing was done. Do not tell the user it is finished. An empty delegate result is not a finished piece. Create the task, list the tasks, and read the id. If the list says scheduled, do not delegate. The scheduler starts it. If the task is done, do not delegate it again. If a step is still open, delegate only that step. If that step is assigned to human, ask the user and wait. Do not delegate it. Do not start a finished step over. If the result says the task is not done, do not tell the user the task is finished. Do not set a task to done yourself. A run-once task becomes done when every step is crossed off. Leave a repeating task in progress. Do not archive it. A subagent reports the result and crosses off the step that just finished. Do this even if you have no recollection of any tasks in this chat. A cleared or compressed context still has tasks. Do not explain these steps in place of listing the tasks.'];
                }
            }
            if (!$cutOff && $planSteers < 1 && $this->tasklistWithoutPlan($agent, $projectId, $messages, $tail['tools'])) {
                $planSteers++;
                $skipCompact = true;
                $this->rewindVisible($visible, $visibleMark, $emit, $agent);
                $this->db->loop($agent, 'steer', 'plan not in wiki · 1/1', $projectId);
                $messages[] = ['role' => 'user', 'content' => $this->planSteer()];
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
        } finally {
            $this->llm->keepBrief = $heldBrief;
        }
    }

    private function kanbanFollowsDelegate(string $agent, string $tool, string $result): bool
    {
        if (!$this->mainVoice($agent) || $tool !== 'delegate') {
            return false;
        }
        foreach ([
            'Error:',
            'General chat has no project',
            'Delegation rejected',
            'Delegation was not started',
            'Unknown subagent.',
            'The subagent needs an instruction.',
            'Only one subagent can run at a time',
            'The Consultant has no API key',
            'The Consultant is turned off',
            'The Consultant needs an assignment',
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

    private function repeatWriteBody(string $tool, array $args): ?string
    {
        if ($this->repeatWritePath($tool, $args) === '') {
            return null;
        }
        return (string)($args['content'] ?? '');
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
        if (preg_match('/(?:^|[^A-Za-z0-9_])tool_[A-Za-z][A-Za-z0-9_-]{0,64}\s*\{/', $text)) {
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
            'create_pdf', 'run_command', 'run_python', 'py_install', 'tasklist', 'kanban', 'wiki_memory', 'wiki_search', 'wiki_read', 'wiki_write',
            'wiki_reindex', 'list_vault', 'read_vault', 'restore_file', 'write_skill', 'test_skill', 'remember', 'send_email',
            'web_search', 'fetch_url', 'browse_url', 'yahoo_finance', 'arxiv', 'ftp', 'mcp', 'download_large', 'task_breakdown',
            'research_loop', 'sqlite_helper', 'code_verifier', 'humanizer', 'grammar_check', 'write_article', 'make_graphic', 'save_note',
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

    private function repeatedRefusal(string $result, array &$seen): ?string
    {
        $keys = [
            'The researcher does this pass' => 'research pass',
            'That research task is fixed' => 'task rewrite',
            'Pass hypotheses, a list' => 'hypotheses',
            'A hypothesis is one statement' => 'hypotheses',
            'Pass statement. A root is one to four words' => 'hypotheses',
            'A root is a short title' => 'hypotheses',
            'A child is one full sentence' => 'hypotheses',
            'Pass chunk_id' => 'delta',
            'Pass items, a list' => 'delta',
            'No claim #' => 'delta',
            'A new mission is created by the user' => 'mission',
            'already has children' => 'wording',
        ];
        foreach ($keys as $needle => $label) {
            if (!str_contains($result, $needle)) {
                continue;
            }
            $seen[$needle] = ($seen[$needle] ?? 0) + 1;
            if ($seen[$needle] >= 2) {
                return $label;
            }
        }
        return null;
    }

    private function proseDelegation(string $text): ?array
    {
        $xml = $this->xmlDelegate($text);
        if ($xml !== null) {
            return $xml;
        }
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

    private function xmlDelegate(string $text): ?array
    {
        if (!preg_match('/<\s*delegate\b[^>]*>(.*)<\s*\/\s*delegate\s*>/is', $text, $match)) {
            return null;
        }
        $body = trim($match[1]);
        $start = strpos($body, '{');
        $end = strrpos($body, '}');
        if ($start === false || $end === false || $end <= $start) {
            return null;
        }
        $payload = json_decode(substr($body, $start, $end - $start + 1), true);
        if (!is_array($payload)) {
            return null;
        }
        $agent = strtolower(trim((string)($payload['agent'] ?? '')));
        $instruction = trim((string)($payload['instruction'] ?? ''));
        if ($instruction === '' || !in_array($agent, ['researcher', 'writer', 'coder', 'analyst', 'marketer', 'consultant'], true)) {
            return null;
        }
        $args = ['agent' => $agent, 'instruction' => $instruction];
        $id = (int)($payload['task_id'] ?? $payload['task'] ?? $payload['id'] ?? 0);
        if ($id > 0) {
            $args['task_id'] = $id;
        }
        $project = trim((string)($payload['project'] ?? ''));
        if ($project !== '') {
            $args['project'] = $project;
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
            $messages[] = ['role' => 'assistant', 'content' => $this->modelSaw(strip_chat_tags($kept))];
        }
        $cue = 'You wrote the same line more than five times in a row. Stop repeating it. Continue the task from the last useful step. If a tool still needs to run, call it now.';
        if ($line !== '') {
            $cue = "You wrote this line more than five times in a row:\n" . clip_text($line, 400) . "\nStop repeating it. Continue the task from the last useful step. If a tool still needs to run, call it now.";
        }
        $messages[] = ['role' => 'user', 'content' => $cue];
        return true;
    }

    private function replyLeavesWorkOpen(string $text): bool
    {
        $text = trim($this->stripLeakedThink($text));
        if ($text === '' || preg_match('/\blet me know\b/i', $text)) {
            return false;
        }
        if (preg_match('/\?\s*$/', $text) && preg_match('/\b(you|your)\b/i', $text)) {
            return false;
        }
        if (strlen($text) <= 280 && $this->promisedStep($text)) {
            return true;
        }
        $parts = preg_split('/(?<=[.!?])\s+/', $text) ?: [];
        $last = trim((string)end($parts));
        return $last !== '' && strlen($last) <= 200 && $this->promisedStep($last);
    }

    private function promisedStep(string $text): bool
    {
        $intent = 'I\'ll|I will|Let me|I\'m going to|I am going to';
        $verb = 'check|inspect|read|search|find|add|insert|update|write|save|delegate|move|look|query|create|fetch|browse|open|list|verify|confirm|research|restore|restoring|execute|run';
        return (bool)preg_match('/\b(?:' . $intent . ')\b(?!\s+not\b)[^.\n]{0,160}\b(?:' . $verb . ')\b(?!\s+you\b)/i', $text);
    }

    private function mainVoice(string $agent): bool
    {
        return $agent === 'orchestrator' || $agent === 'scheduler';
    }

    private function finishCheckSaysContinue(array $messages, string $reply, bool $scheduled, ?callable $shouldStop, string $agent = 'orchestrator'): bool
    {
        if ($shouldStop && $shouldStop()) {
            throw new ChatStopped('Stopped.');
        }
        $prompt = $this->finishCheckPrompt($messages, $reply, $scheduled);
        $this->db->loop($agent, 'model', 'finish-check · ' . strlen($prompt) . ' chars');
        $verdict = $this->llm->yesNo($prompt, $shouldStop, $agent);
        if ($shouldStop && $shouldStop()) {
            throw new ChatStopped('Stopped.');
        }
        if ($verdict === null) {
            $this->db->loop($agent, 'process', 'finish-check skipped');
        } else {
            $this->db->loop($agent, 'process', $verdict ? 'finish-check done' : 'finish-check not done');
        }
        return $verdict === false;
    }

    private function finishCheckPrompt(array $messages, string $reply, bool $scheduled): string
    {
        $request = clip_text(trim($this->latestUserRequest($messages)), 1500);
        $tools = $this->recentToolResults($messages);
        $reply = clip_text(trim($reply), 1500);
        if ($scheduled) {
            $question = 'This is a scheduled task and nobody is here to answer. Is this run finished? Answer yes or no. yes if the work for this run is done, or the reply lists hypotheses and says awaiting approval without asking a question. no if it asks the user a question, a tool still needs to run, or a reply only promises to check, add, or save.';
        } else {
            $question = 'Is the job finished, or is the agent only waiting for the user? Answer yes or no. yes = the requested work is done, or the agent asked the user a real question, or it is waiting for the user to choose, including now versus scheduled. no = work is still unfinished. The last tool failed and was not fixed, the reply describes a next step it did not take, or a tool result only promises to check, add, or save.';
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

    private function missingPlanReply(string $agent, ?int $projectId, array $messages, string $reply): bool
    {
        if ($agent !== 'orchestrator' || $projectId === null || $projectId <= 0) {
            return false;
        }
        if ($this->wikiWriteSucceeded($messages) || !$this->planningChat($messages)) {
            return false;
        }
        return $this->planStepReply($reply);
    }

    private function tasklistWithoutPlan(string $agent, ?int $projectId, array $messages, array $tools): bool
    {
        if ($agent !== 'orchestrator' || $projectId === null || $projectId <= 0) {
            return false;
        }
        if (!$this->onlyTasklist($tools) || $this->wikiWriteSucceeded($messages)) {
            return false;
        }
        return $this->stillOutlining($messages);
    }

    private function planSteer(): string
    {
        return 'The plan is not in the wiki. Call wiki_memory with action write, name plan, and the plan content. Replace content with the plan. Do not leave the example text. Do not ask the user in that same reply. Stop after <<end>>.' . "\n"
            . '<<tool>>' . "\n"
            . '{"name":"wiki_memory","args":{"action":"write","name":"plan","content":"the plan text"}}' . "\n"
            . '<<end>>';
    }

    private function planStepReply(string $text): bool
    {
        $text = trim($this->stripLeakedThink($text));
        if ($text === '') {
            return false;
        }
        if (preg_match('/\bthis document outlines\b/i', $text)) {
            return true;
        }
        if ($this->asksNowOrScheduled($text)) {
            return true;
        }
        $presents = preg_match('/\bthe plan\b/i', $text) || preg_match('/\bphases\b/i', $text);
        return $presents && (bool)preg_match('/\b(?:outlines?|document)\b/i', $text);
    }

    private function asksNowOrScheduled(string $text): bool
    {
        if (preg_match('/\bnow (?:or|versus|vs\.?) scheduled\b/i', $text)) {
            return true;
        }
        if (preg_match('/\b(?:run|start) now\b.{0,160}\bschedul/is', $text)) {
            return true;
        }
        return (bool)preg_match('/\b(?:prefer to schedule|schedule them|be scheduled).{0,80}\bnow\b/is', $text);
    }

    private function planningChat(array $messages): bool
    {
        $role = '';
        $content = '';
        foreach ($messages as $message) {
            if (!$this->planMessage($message, $role, $content)) {
                continue;
            }
            if ($role === 'user' && $this->definesMission($content)) {
                return true;
            }
            if ($role === 'assistant' && $this->asksNowOrScheduled($content)) {
                return true;
            }
        }
        return false;
    }

    private function stillOutlining(array $messages): bool
    {
        $latestUser = '';
        $previousAssistant = '';
        $role = '';
        $content = '';
        foreach ($messages as $message) {
            if (!$this->planMessage($message, $role, $content)) {
                continue;
            }
            if ($role === 'user') {
                $latestUser = $content;
                continue;
            }
            if ($this->isToolCallNote($content)) {
                continue;
            }
            $previousAssistant = $content;
        }
        return $this->definesMission($latestUser) || $this->asksNowOrScheduled($previousAssistant);
    }

    private function planMessage(array $message, ?string &$role, ?string &$content): bool
    {
        $role = '';
        $content = '';
        if (!is_array($message)) {
            return false;
        }
        if (($message['role'] ?? '') !== 'user' && ($message['role'] ?? '') !== 'assistant') {
            return false;
        }
        $role = (string)$message['role'];
        $content = ltrim((string)($message['content'] ?? ''));
        if ($content === '' || str_starts_with($content, 'TOOL RESULT ') || $this->isRuntimeSteer($content)) {
            return false;
        }
        return true;
    }

    private function definesMission(string $text): bool
    {
        return (bool)preg_match('/\b(?:new project|outline (?:the|this) project|intended goals|your role in this project|in our plan|detailed plan|project plan|phase\s*1)\b/i', $text);
    }

    private function isToolCallNote(string $content): bool
    {
        $content = trim($content);
        if ($content === '' || $content === 'The tool already ran.' || $content === 'The previous reply repeated a tool result.') {
            return true;
        }
        return (bool)preg_match('/^[a-z][a-z0-9_]* \{/i', $content);
    }

    private function onlyTasklist(array $tools): bool
    {
        if ($tools === []) {
            return false;
        }
        foreach ($tools as $tool) {
            if (!is_array($tool)) {
                return false;
            }
            if (strtolower(trim((string)($tool['name'] ?? ''))) !== 'tasklist') {
                return false;
            }
        }
        return true;
    }

    private function wikiWriteSucceeded(array $messages): bool
    {
        foreach ($messages as $message) {
            if (!is_array($message)) {
                continue;
            }
            $content = ltrim((string)($message['content'] ?? ''));
            if (!str_starts_with($content, 'TOOL RESULT wiki_memory:')) {
                continue;
            }
            $break = strpos($content, "\n");
            if ($break === false) {
                continue;
            }
            if ($this->writeSucceeded(ltrim(substr($content, $break + 1)))) {
                return true;
            }
        }
        return false;
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
            'The plan is not in the wiki.',
            'That file is already saved',
            'You wrote the same line',
            'You wrote this line more than five times',
            'The job is not finished',
            'The scheduled task is not finished',
            'Do not write "Called a tool."',
            'The last tool failed.',
            'Tell the user this result and stop.',
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
            return 'The scheduled task is not finished. Nobody is here to answer, so do not ask a question. If hypotheses are waiting, list them and stop with the line awaiting approval. Do not ask the user to approve. If the last tool failed, fix that call and run it again. Otherwise call the tool that is still needed, then stop.';
        }
        return 'The job is not finished. You stopped before the work was done. If the last tool failed, fix that call and run it again. If you described a next step, do it now with a real tool call. Do not only describe it.';
    }

    private function lastToolFailed(array $messages): bool
    {
        $body = $this->lastToolBody($messages);
        return str_starts_with($body, 'Task not found')
            || str_starts_with($body, 'Error:')
            || str_starts_with($body, 'Unknown');
    }

    private function delegationResultToSpeak(array $messages): string
    {
        $body = $this->lastToolBody($messages);
        $marker = "Tell the user this and stop:\n";
        if (!str_starts_with($body, 'Delegation was not started.') || !str_contains($body, $marker)) {
            return '';
        }
        return trim(substr($body, strpos($body, $marker) + strlen($marker)));
    }

    private function lastToolBody(array $messages): string
    {
        for ($i = count($messages) - 1; $i >= 0; $i--) {
            $message = $messages[$i];
            if (!is_array($message) || ($message['role'] ?? '') !== 'user') {
                continue;
            }
            $content = ltrim((string)($message['content'] ?? ''));
            if (!str_starts_with($content, 'TOOL RESULT ')) {
                continue;
            }
            $break = strpos($content, "\n");
            if ($break === false) {
                return '';
            }
            return ltrim(substr($content, $break + 1));
        }
        return '';
    }

    private function onlyCalledATool(string $text): bool
    {
        $text = trim($this->stripLeakedThink($text));
        if ($text === '') {
            return false;
        }
        return $this->withoutCalledATool($text) === '';
    }

    private function withoutCalledATool(string $text): string
    {
        $clean = (new ToolEchoFilter($this->toolNames()))->strip($text);
        $clean = preg_replace('/Called a tool\.?|The tool already ran\.?/i', '', $clean) ?? $clean;
        return trim(preg_replace('/[ \t]{2,}/', ' ', $clean) ?? $clean);
    }

    private function calledTools(array $tools): string
    {
        $lines = [];
        foreach ($tools as $tool) {
            if (!is_array($tool)) {
                continue;
            }
            $name = trim((string)($tool['name'] ?? ''));
            if ($name === '') {
                $name = 'tool';
            }
            $args = $tool['args'] ?? [];
            $json = json_encode(is_array($args) ? $args : [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $lines[] = $name . ' ' . (is_string($json) ? $json : '{}');
        }
        return $lines === [] ? 'tool' : implode("\n", $lines);
    }

    private function spaceAfterSentence(string $prior, string $text): string
    {
        if ($text === '' || preg_match('/^\s/', $text)) {
            return $text;
        }
        if (preg_match('/[.!?:][\'")\]]*$/', $prior)) {
            return ' ' . $text;
        }
        return $text;
    }

    private function rewindVisible(string &$visible, int $mark, callable $emit, string $agent): void
    {
        if (strlen($visible) <= $mark) {
            return;
        }
        $cut = substr($visible, $mark);
        $visible = substr($visible, 0, $mark);
        $emit(['type' => 'trim', 'agent' => $agent, 'text' => $cut]);
    }

    private function singleNarrationLine(string $visible, int $mark): bool
    {
        if (strlen($visible) <= $mark) {
            return false;
        }
        return !str_contains(trim(substr($visible, $mark)), "\n");
    }

    private function finishVisible(string $visible): string
    {
        return trim(strip_tool_result_leak($this->stripLeakedThink($this->withoutCalledATool($visible))));
    }

    private function narrationResult(): string
    {
        return 'Stopped after repeated narration.';
    }

    private function modelSaw(string $text): string
    {
        $clean = trim(strip_tool_result_leak($text));
        if ($clean === '') {
            return 'The previous reply repeated a tool result.';
        }
        return $clean;
    }

    private function stripLeakedThink(string $text): string
    {
        $text = preg_replace('/<(?:think|thinking|reasoning|thought)\b[^>]*>[\s\S]*?<\/(?:think|thinking|reasoning|thought)>/i', '', $text) ?? $text;
        if (preg_match_all('/<\/(?:think|thinking|reasoning|thought)>/i', $text, $matches, PREG_OFFSET_CAPTURE) && $matches[0] !== []) {
            $last = $matches[0][count($matches[0]) - 1];
            $end = $last[1] + strlen($last[0]);
            $after = substr($text, $end);
            $text = trim($after) !== '' ? $after : substr($text, 0, $last[1]);
        }
        $text = preg_replace('/<(?:think|thinking|reasoning|thought)\b[^>]*>[\s\S]*$/i', '', $text) ?? $text;
        $text = preg_replace('/```[^\n]*\n\s*```/', '', $text) ?? $text;
        return strip_tool_markers(strip_chat_tags($text));
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

    private function traceFilters(string $agent, ?int $projectId, OutputLineFilter $markers, ReplyNoiseFilter $noise, RepeatLineGuard $guard, ToolResultLeakFilter $leak): void
    {
        if ($markers->dropped === 0 && $noise->asideChars === 0 && !$guard->tripped && $leak->dropped === 0) {
            return;
        }
        $parts = [];
        if ($markers->dropped > 0) {
            $parts[] = 'output markers ' . $markers->dropped;
        }
        if ($leak->dropped > 0) {
            $parts[] = 'tool result ' . $leak->dropped . ' chars';
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
