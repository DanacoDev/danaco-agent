<?php

class ToolResultLeakFilter
{
    public int $dropped = 0;

    private string $pending = '';

    private bool $dropping = false;

    private bool $midLine = false;

    private bool $breakAfter = false;

    private bool $lineStart = true;

    public function push(string $token): string
    {
        if ($token === '') {
            return '';
        }
        $this->pending .= $token;
        return $this->drain();
    }

    public function finish(): string
    {
        $out = $this->drain();
        $rest = $this->pending;
        $this->pending = '';
        if ($this->dropping) {
            $this->dropped += strlen($rest);
            $this->dropping = false;
            $this->midLine = false;
            $this->lineStart = true;
            return $this->closeBreak($out);
        }
        if ($rest === '') {
            return $this->closeBreak($out);
        }
        $at = $this->headerAt($rest);
        if ($at !== null) {
            $this->dropped += strlen($rest) - $at;
            $this->lineStart = true;
            return $this->closeBreak($out . $this->keepPrefix(substr($rest, 0, $at)));
        }
        $this->lineStart = false;
        return $out . $this->lead($rest);
    }

    private function drain(): string
    {
        $out = '';
        while ($this->pending !== '') {
            $break = strpos($this->pending, "\n");
            if ($break === false) {
                if ($this->dropping) {
                    break;
                }
                $at = $this->headerAt($this->pending);
                if ($at !== null) {
                    $prefix = substr($this->pending, 0, $at);
                    if (trim($prefix) === '' && !$this->lineStart) {
                        $this->breakAfter = true;
                    }
                    $out .= $this->keepPrefix($prefix);
                    $this->dropped += strlen($this->pending) - $at;
                    $this->pending = '';
                    $this->dropping = true;
                    $this->midLine = true;
                    $this->lineStart = false;
                    break;
                }
                $holdAt = $this->incompleteHeaderAt($this->pending);
                if ($holdAt !== null) {
                    if ($holdAt > 0) {
                        $out .= substr($this->pending, 0, $holdAt);
                        $this->pending = substr($this->pending, $holdAt);
                        $this->lineStart = false;
                    }
                    break;
                }
                $out .= $this->lead($this->pending);
                $this->pending = '';
                $this->lineStart = false;
                break;
            }
            $line = substr($this->pending, 0, $break);
            $this->pending = substr($this->pending, $break + 1);
            $out .= $this->take($line);
        }
        return $out;
    }

    private function take(string $line): string
    {
        if ($this->dropping) {
            $this->dropped += strlen($line) + 1;
            if ($this->midLine) {
                $this->midLine = false;
                $this->lineStart = true;
                return '';
            }
            if (trim($line) === '') {
                $this->dropping = false;
            }
            $this->lineStart = true;
            return '';
        }
        $at = $this->headerAt($line);
        if ($at !== null) {
            $prefix = substr($line, 0, $at);
            if (trim($prefix) === '' && !$this->lineStart) {
                $this->breakAfter = true;
            }
            $this->dropped += strlen($line) - $at + 1;
            $this->dropping = true;
            $this->midLine = false;
            $this->lineStart = true;
            return $this->keepPrefix($prefix);
        }
        $this->lineStart = true;
        return $this->lead($line . "\n");
    }

    private function lead(string $text): string
    {
        if (!$this->breakAfter || $text === '') {
            return $text;
        }
        $this->breakAfter = false;
        if (str_starts_with($text, "\n")) {
            return $text;
        }
        return "\n" . $text;
    }

    private function closeBreak(string $text): string
    {
        if (!$this->breakAfter) {
            return $text;
        }
        $this->breakAfter = false;
        if ($text === '' || str_ends_with($text, "\n")) {
            return $text;
        }
        return $text . "\n";
    }

    private function keepPrefix(string $prefix): string
    {
        if (trim($prefix) === '') {
            return '';
        }
        if (!str_ends_with($prefix, "\n")) {
            return $prefix . "\n";
        }
        return $prefix;
    }

    private function headerAt(string $text): ?int
    {
        if (preg_match('/TOOL RESULT \S+:/i', $text, $match, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }
        return (int)$match[0][1];
    }

    private function incompleteHeaderAt(string $text): ?int
    {
        $length = strlen($text);
        $max = min($length, 160);
        for ($n = $max; $n >= 1; $n--) {
            if ($this->isHeaderPrefix(substr($text, $length - $n))) {
                return $length - $n;
            }
        }
        return null;
    }

    private function isHeaderPrefix(string $text): bool
    {
        if (preg_match('/^TOOL RESULT \S+:/i', $text) === 1) {
            return false;
        }
        if (preg_match('/^TOOL RESULT \S+$/i', $text) === 1) {
            return true;
        }
        $header = 'TOOL RESULT ';
        $n = strlen($text);
        return $n <= strlen($header) && strncasecmp($text, $header, $n) === 0;
    }
}
