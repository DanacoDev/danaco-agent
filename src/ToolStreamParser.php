<?php

class ToolStreamParser
{
    private const OPENERS = ['<<tool>>', '<tool>>', '<<tool>', '<tool>', '[[tool]]', '[tool]]', '[[tool]', '[tool]'];
    private const CLOSERS = ['<<end>>', '<end>>', '<<end>', '<end>', '[[end]]', '[end]]', '[[end]', '[end]', '[[/tool]]', '[/tool]]', '[[/tool]', '[/tool]', '<</tool>>', '</tool>>', '<</tool>', '</tool>'];

    private string $pending = '';
    private bool $inTool = false;
    private string $toolBuf = '';
    private array $tools = [];

    public function push(string $token): string
    {
        $this->pending .= $token;
        return $this->drain(true);
    }

    public function isWritingTool(): bool
    {
        return $this->inTool;
    }

    public function finish(): array
    {
        $emitted = $this->drain(false);
        $open = $this->inTool;
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
                $open = $this->findMarker($this->pending, self::OPENERS, $holdLonger);
                if ($open === null) {
                    if (!$holdLonger) {
                        break;
                    }
                    $hold = $this->suffixHold($this->pending, self::OPENERS);
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
            if (is_array($decoded) && isset($decoded['name'])) {
                $args = $decoded['args'] ?? $decoded['arguments'] ?? [];
                $this->tools[] = [
                    'name' => (string)$decoded['name'],
                    'args' => is_array($args) ? $args : [],
                ];
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
}
