<?php

class ToolStreamParser
{
    private const OPENERS = ['<<tool>>', '<tool>>', '<<tool>', '<tool>', '[[tool]]', '[tool]]', '[[tool]', '[tool]'];
    private const CLOSERS = ['<<end>>', '<end>>', '<<end>', '<end>', '[[end]]', '[end]]', '[[end]', '[end]', '[[/tool]]', '[/tool]]', '[[/tool]', '[/tool]', '<</tool>>', '</tool>>', '<</tool>', '</tool>'];

    private string $pending = '';
    private bool $inTool = false;
    private bool $holdingTool = false;
    private bool $closerHold = false;
    private string $toolBuf = '';
    private array $tools = [];

    public function __construct(private array $toolNames = [])
    {
    }

    public function push(string $token): string
    {
        $this->pending .= $token;
        return $this->drain(true);
    }

    public function isWritingTool(): bool
    {
        return $this->inTool || $this->holdingTool;
    }

    public function finish(): array
    {
        $emitted = $this->drain(false);
        $open = $this->inTool || $this->holdingTool;
        if ($this->inTool) {
            $text = $emitted . '<<tool>>' . $this->toolBuf . $this->pending;
        } else {
            $text = $emitted . $this->pending;
        }
        $tools = $this->tools;
        $this->pending = '';
        $this->toolBuf = '';
        $this->inTool = false;
        $this->tools = [];
        return ['text' => $text, 'tools' => $tools, 'open' => $open];
    }

    private function drain(bool $holdLonger): string
    {
        $emitted = '';
        while ($this->pending !== '' || $this->inTool) {
            if (!$this->inTool) {
                if ($this->closerHold) {
                    $closer = $this->drainCloser($holdLonger);
                    if ($closer === 'hold') {
                        break;
                    }
                    if ($closer === 'ate') {
                        continue;
                    }
                }
                $open = $this->findMarker($this->pending, self::OPENERS, $holdLonger);
                $braceAt = strpos($this->pending, '{');
                if ($braceAt !== false && ($open === null || $braceAt < $open['at'])) {
                    $kind = $this->toolObjectKind(substr($this->pending, $braceAt));
                    if ($kind === 'maybe') {
                        if ($holdLonger) {
                            $this->holdToolStart($emitted, $braceAt, false);
                            break;
                        }
                    } elseif ($kind === 'object') {
                        $end = $this->scanJsonObject($this->pending, $braceAt);
                        if ($end === null) {
                            if ($holdLonger) {
                                $this->holdToolStart($emitted, $braceAt, true);
                            } else {
                                $this->holdingTool = true;
                            }
                            break;
                        }
                        $decoded = json_decode(substr($this->pending, $braceAt, $end - $braceAt), true);
                        $call = is_array($decoded) ? $this->bareTool($decoded) : null;
                        if ($call !== null) {
                            $before = substr($this->pending, 0, $braceAt);
                            $start = $this->fenceStart($before);
                            if (trim(substr($before, 0, $start)) === '') {
                                $start = 0;
                            }
                            $emitted .= substr($this->pending, 0, $start);
                            $this->tools[] = $call;
                            $this->pending = substr($this->pending, $end);
                            $this->holdingTool = false;
                            $this->closerHold = true;
                            continue;
                        }
                        $this->holdingTool = false;
                        $emitted .= substr($this->pending, 0, $end);
                        $this->pending = substr($this->pending, $end);
                        continue;
                    }
                }
                if ($open === null) {
                    if (!$holdLonger) {
                        break;
                    }
                    $hold = max($this->suffixHold($this->pending, self::OPENERS), $this->fencePrefixHold($this->pending));
                    $cut = strlen($this->pending) - $hold;
                    if ($cut <= 0) {
                        break;
                    }
                    $emitted .= substr($this->pending, 0, $cut);
                    $this->pending = substr($this->pending, $cut);
                    break;
                }
                $emitted .= substr($this->pending, 0, $open['at']);
                $this->pending = substr($this->pending, $open['at'] + strlen($open['marker']));
                $this->inTool = true;
                $this->toolBuf = '';
                continue;
            }
            $this->toolBuf .= $this->pending;
            $this->pending = '';
            $close = $this->findMarker($this->toolBuf, self::CLOSERS, $holdLonger);
            if ($close === null) {
                break;
            }
            $json = trim(substr($this->toolBuf, 0, $close['at']));
            $rest = substr($this->toolBuf, $close['at'] + strlen($close['marker']));
            $decoded = json_decode($json, true);
            if (is_array($decoded)) {
                $name = $decoded['name'] ?? $decoded['tool'] ?? null;
                if (is_string($name) && trim($name) !== '') {
                    $this->tools[] = [
                        'name' => trim($name),
                        'args' => $this->toolArgs($decoded),
                    ];
                }
            }
            $this->inTool = false;
            $this->toolBuf = '';
            $this->pending = $rest;
        }
        return $emitted;
    }

    private function findMarker(string $text, array $markers, bool $holdLonger = true): ?array
    {
        $bestAt = null;
        $best = '';
        foreach ($markers as $marker) {
            $at = strpos($text, $marker);
            if ($at === false) {
                continue;
            }
            if ($bestAt === null || $at < $bestAt || ($at === $bestAt && strlen($marker) > strlen($best))) {
                $bestAt = $at;
                $best = $marker;
            }
        }
        if ($bestAt === null) {
            return null;
        }
        if (!$holdLonger) {
            return ['at' => $bestAt, 'marker' => $best];
        }
        $end = $bestAt + strlen($best);
        foreach ($markers as $marker) {
            if (strlen($marker) <= strlen($best)) {
                continue;
            }
            $minStart = max(0, $end - (strlen($marker) - 1));
            for ($start = $minStart; $start <= $bestAt; $start++) {
                $slice = substr($text, $start);
                if (strlen($slice) < strlen($marker) && substr($marker, 0, strlen($slice)) === $slice) {
                    return null;
                }
            }
        }
        return ['at' => $bestAt, 'marker' => $best];
    }

    private function suffixHold(string $text, array $markers): int
    {
        $hold = 0;
        $length = strlen($text);
        foreach ($markers as $marker) {
            $max = strlen($marker) - 1;
            for ($i = 1; $i <= $max && $i <= $length; $i++) {
                if (substr($text, -$i) === substr($marker, 0, $i)) {
                    $hold = max($hold, $i);
                }
            }
        }
        return $hold;
    }

    private function drainCloser(bool $holdLonger): string
    {
        if ($this->pending === '' || preg_match('/^\s*$/', $this->pending)) {
            if ($holdLonger) {
                return 'hold';
            }
            $this->pending = '';
            $this->closerHold = false;
            return 'ate';
        }
        if (!preg_match('/^(\s*)(\S[\s\S]*)$/', $this->pending, $match)) {
            return 'hold';
        }
        $rest = $match[2];
        $best = '';
        foreach (self::CLOSERS as $closer) {
            if (strncmp($rest, $closer, strlen($closer)) === 0 && strlen($closer) > strlen($best)) {
                $best = $closer;
            }
        }
        if ($best !== '') {
            if ($holdLonger) {
                foreach (self::CLOSERS as $closer) {
                    if (strlen($closer) > strlen($best) && strncmp($closer, $rest, strlen($rest)) === 0) {
                        return 'hold';
                    }
                }
            }
            $this->pending = substr($rest, strlen($best));
            $this->closerHold = false;
            if (preg_match('/^\s*(`{3,})/', $this->pending, $fence)) {
                $this->pending = substr($this->pending, strlen($fence[0]));
            }
            return 'ate';
        }
        if (preg_match('/^(`{3,})/', $rest, $fence)) {
            $this->pending = substr($rest, strlen($fence[1]));
            $this->closerHold = false;
            return 'ate';
        }
        if ($holdLonger && $this->closerPrefix($rest)) {
            return 'hold';
        }
        $this->closerHold = false;
        return 'release';
    }

    private function closerPrefix(string $rest): bool
    {
        foreach (self::CLOSERS as $closer) {
            if (strncmp($closer, $rest, strlen($rest)) === 0) {
                return true;
            }
        }
        return strlen($rest) < 3 && strncmp('```', $rest, strlen($rest)) === 0;
    }

    private function toolObjectKind(string $slice): string
    {
        $keys = 'tool|name|args|arguments|parameters';
        if (preg_match('/^\{\s*"(?:' . $keys . ')"\s*:/s', $slice)) {
            return 'object';
        }
        if ($slice === '' || $slice[0] !== '{') {
            return 'no';
        }
        if (preg_match('/^\{(\s*)("?)(.*)$/s', $slice, $match) !== 1) {
            return 'no';
        }
        if ($match[2] === '') {
            return $match[3] === '' ? 'maybe' : 'no';
        }
        if (preg_match('/^([A-Za-z_]*)("?)(.*)$/s', $match[3], $key) !== 1) {
            return 'no';
        }
        if ($key[2] !== '"') {
            foreach (['tool', 'name', 'args', 'arguments', 'parameters'] as $candidate) {
                if (str_starts_with($candidate, $key[1])) {
                    return 'maybe';
                }
            }
            return 'no';
        }
        if (!in_array($key[1], ['tool', 'name', 'args', 'arguments', 'parameters'], true)) {
            return 'no';
        }
        if (preg_match('/^\s*:/s', $key[3]) || preg_match('/^\s*$/s', $key[3])) {
            return preg_match('/^\s*:/s', $key[3]) ? 'object' : 'maybe';
        }
        return 'no';
    }

    private function scanJsonObject(string $text, int $at): ?int
    {
        $length = strlen($text);
        $depth = 0;
        $inString = false;
        $escape = false;
        for ($i = $at; $i < $length; $i++) {
            $ch = $text[$i];
            if ($inString) {
                if ($escape) {
                    $escape = false;
                    continue;
                }
                if ($ch === '\\') {
                    $escape = true;
                    continue;
                }
                if ($ch === '"') {
                    $inString = false;
                }
                continue;
            }
            if ($ch === '"') {
                $inString = true;
                continue;
            }
            if ($ch === '{') {
                $depth++;
                continue;
            }
            if ($ch === '}') {
                $depth--;
                if ($depth === 0) {
                    return $i + 1;
                }
            }
        }
        return null;
    }

    private function bareTool(array $decoded): ?array
    {
        $fromTool = isset($decoded['tool']) && is_string($decoded['tool']);
        $raw = $fromTool ? trim($decoded['tool']) : '';
        if ($raw === '' && isset($decoded['name']) && is_string($decoded['name'])) {
            $raw = trim($decoded['name']);
        }
        if ($raw === '' || preg_match('/^[A-Za-z][A-Za-z0-9_-]{0,64}$/', $raw) !== 1) {
            return null;
        }
        $name = null;
        foreach ($this->toolNames as $known) {
            if (strcasecmp((string)$known, $raw) === 0) {
                $name = (string)$known;
                break;
            }
        }
        if ($name === null) {
            if (!$fromTool) {
                return null;
            }
            $name = strtolower($raw);
        }
        return ['name' => $name, 'args' => $this->toolArgs($decoded)];
    }

    private function toolArgs(array $decoded): array
    {
        $args = $decoded['args'] ?? $decoded['arguments'] ?? $decoded['parameters'] ?? [];
        if (is_string($args)) {
            $parsed = json_decode($args, true);
            $args = is_array($parsed) ? $parsed : [];
        }
        return is_array($args) ? $args : [];
    }

    private function holdToolStart(string &$emitted, int $braceAt, bool $confirmed): void
    {
        $before = substr($this->pending, 0, $braceAt);
        $start = $this->fenceStart($before);
        if (trim(substr($before, 0, $start)) === '') {
            $start = 0;
        }
        $emitted .= substr($this->pending, 0, $start);
        $this->pending = substr($this->pending, $start);
        $this->holdingTool = $confirmed;
    }

    private function fenceStart(string $before): int
    {
        if (preg_match('/(?:^|\n)(`{3,})(?:json|JSON)?[ \t]*\r?\n[ \t]*$/', $before, $match, PREG_OFFSET_CAPTURE)) {
            return $match[1][1];
        }
        return strlen($before);
    }

    private function fencePrefixHold(string $text): int
    {
        if (preg_match('/(?:^|\n)(`{1,3})(j|js|jso|json|JSON)?$/', $text, $match, PREG_OFFSET_CAPTURE)) {
            return strlen($text) - $match[0][1];
        }
        if (preg_match('/(?:^|\n)(`{3,})(?:json|JSON)?[ \t]*\r?\n$/', $text, $match, PREG_OFFSET_CAPTURE)) {
            return strlen($text) - $match[0][1];
        }
        return 0;
    }
}
