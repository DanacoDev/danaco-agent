<?php

class ToolResultLeakFilter
{
    public int $dropped = 0;

    private string $pending = '';

    private bool $dropping = false;

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
        if ($rest === '') {
            return $out;
        }
        if ($this->dropping || ($this->lineStart && $this->isHeader($rest))) {
            $this->dropped += strlen($rest);
            $this->dropping = false;
            $this->lineStart = true;
            return $out;
        }
        $this->lineStart = false;
        return $out . $rest;
    }

    private function drain(): string
    {
        $out = '';
        while ($this->pending !== '') {
            $break = strpos($this->pending, "\n");
            if ($break === false) {
                if ($this->dropping || ($this->lineStart && $this->holds($this->pending))) {
                    break;
                }
                $out .= $this->pending;
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
            if (trim($line) === '') {
                $this->dropping = false;
            }
            $this->lineStart = true;
            return '';
        }
        if ($this->lineStart && $this->isHeader($line)) {
            $this->dropped += strlen($line) + 1;
            $this->dropping = true;
            $this->lineStart = true;
            return '';
        }
        $this->lineStart = true;
        return $line . "\n";
    }

    private function holds(string $text): bool
    {
        return $this->isHeader($text) || $this->isPrefix($text);
    }

    private function isHeader(string $line): bool
    {
        return preg_match('/^TOOL RESULT \S+:/i', ltrim($line, " \t")) === 1;
    }

    private function isPrefix(string $text): bool
    {
        $trim = ltrim($text, " \t");
        if ($trim === '') {
            return true;
        }
        if (preg_match('/^TOOL RESULT(?:\s+\S*)?$/i', $trim) === 1) {
            return true;
        }
        $header = 'TOOL RESULT ';
        $n = strlen($trim);
        return $n < strlen($header) && strncasecmp($trim, $header, $n) === 0;
    }
}
