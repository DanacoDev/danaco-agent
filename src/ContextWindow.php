<?php

class ContextWindow
{
    public const KEEP = 5;

    public const NOTICE = 'Older messages have been compressed';

    public static function tokens(string $text): int
    {
        if ($text === '') {
            return 0;
        }
        $chars = function_exists('mb_strlen') ? mb_strlen($text) : strlen($text);
        return (int)ceil($chars / 4);
    }

    public static function split(string $system, array $history, int $total, bool $force = false): array
    {
        $total = max(512, $total);
        $budget = (int)max(256, (int)floor($total * 0.80));
        $raw = self::tokens($system) + 8 + self::messageTokens($history);
        $count = count($history);
        $keepCount = min(self::KEEP, $count);
        $older = array_slice($history, 0, $count - $keepCount);
        $recent = $keepCount > 0 ? array_slice($history, -$keepCount) : [];
        $compress = $older !== [] && ($force || $raw > $budget);
        return [
            'kept' => $compress ? $recent : $history,
            'dropped' => $compress ? $older : [],
            'used' => $raw,
            'total' => $total,
            'compressed' => $compress,
        ];
    }

    public static function fit(string $system, array $history, int $total): array
    {
        $total = max(512, $total);
        $budget = (int)max(256, (int)floor($total * 0.80));
        $raw = self::tokens($system) + 8 + self::messageTokens($history);
        if ($raw <= $budget || $history === []) {
            return [
                'kept' => $history,
                'dropped' => [],
                'used' => $raw,
                'total' => $total,
                'compressed' => false,
            ];
        }
        $kept = $history;
        $dropped = [];
        while (count($kept) > self::KEEP) {
            $dropped[] = array_shift($kept);
        }
        $reserve = 1600;
        while (count($kept) > 1 && self::tokens($system) + 8 + self::messageTokens($kept) + $reserve > $budget) {
            $dropped[] = array_shift($kept);
        }
        return [
            'kept' => $kept,
            'dropped' => $dropped,
            'used' => $raw,
            'total' => $total,
            'compressed' => $dropped !== [],
        ];
    }

    private static function messageTokens(array $messages): int
    {
        $used = 0;
        foreach ($messages as $message) {
            $used += self::tokens((string)($message['content'] ?? '')) + 4;
        }
        return $used;
    }

    public static function apply(string $system, string $summary, array $kept, int $total, int $raw): array
    {
        $summary = trim($summary);
        $messages = [['role' => 'system', 'content' => $system]];
        if ($summary !== '') {
            $messages[0]['content'] .= "\n\nEarlier messages were summarized below. Treat that summary as the record of them. Do not invent details that are not in the summary or the later messages. The same summary is saved in the wiki.";
            $messages[] = [
                'role' => 'user',
                'content' => "Summary of the earlier conversation:\n\n" . $summary,
            ];
        }
        foreach ($kept as $message) {
            $messages[] = [
                'role' => (string)($message['role'] ?? 'user'),
                'content' => (string)($message['content'] ?? ''),
            ];
        }
        $used = $raw;
        if ($summary !== '') {
            $used = 0;
            foreach ($messages as $message) {
                $used += self::tokens((string)$message['content']) + 4;
            }
        }
        return [
            'messages' => $messages,
            'used' => $used,
            'total' => max(512, $total),
            'compressed' => $summary !== '',
        ];
    }

    public static function measure(string $system, array $messages): int
    {
        return self::tokens($system) + 8 + self::messageTokens($messages);
    }

    public static function stripLookups(array $messages): array
    {
        $pending = [];
        foreach ($messages as $i => $message) {
            if (!is_array($message)) {
                continue;
            }
            $role = (string)($message['role'] ?? '');
            $content = (string)($message['content'] ?? '');
            if ($role === 'assistant') {
                foreach (self::toolCalls($content) as $call) {
                    $pending[] = $call;
                }
                continue;
            }
            if ($role !== 'user' || !str_starts_with(ltrim($content), 'TOOL RESULT ')) {
                continue;
            }
            $name = self::toolResultName($content);
            $args = [];
            $found = false;
            foreach ($pending as $k => $call) {
                if (($call['name'] ?? '') !== $name) {
                    continue;
                }
                $args = is_array($call['args'] ?? null) ? $call['args'] : [];
                $found = true;
                unset($pending[$k]);
                $pending = array_values($pending);
                break;
            }
            if (!self::isLookup($name, $args, $found, $content)) {
                continue;
            }
            $messages[$i]['content'] = 'TOOL RESULT ' . $name . ': listing omitted';
        }
        return $messages;
    }

    public static function isSteer(string $content): bool
    {
        $content = ltrim($content);
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

    public static function summarySource(array $messages): array
    {
        $out = [];
        foreach ($messages as $message) {
            if (!is_array($message)) {
                continue;
            }
            $content = trim((string)($message['content'] ?? ''));
            if ($content === '' || self::isSteer($content)) {
                continue;
            }
            if (preg_match('/^TOOL RESULT \S+: listing omitted$/', $content)) {
                continue;
            }
            $out[] = $message;
        }
        return $out;
    }

    public static function isShort(array $messages): bool
    {
        return self::messageTokens($messages) < 500;
    }

    public static function tailStart(array $messages): int
    {
        $lastAssistant = null;
        $lastUser = null;
        $count = count($messages);
        for ($i = $count - 1; $i >= 0; $i--) {
            if (!is_array($messages[$i])) {
                continue;
            }
            $role = (string)($messages[$i]['role'] ?? '');
            $content = ltrim((string)($messages[$i]['content'] ?? ''));
            if ($lastAssistant === null && $role === 'assistant') {
                $lastAssistant = $i;
            }
            if ($lastUser === null && $role === 'user' && !str_starts_with($content, 'TOOL RESULT ') && !self::isSteer($content)) {
                $lastUser = $i;
            }
            if ($lastAssistant !== null && $lastUser !== null) {
                break;
            }
        }
        $start = $count;
        if ($lastAssistant !== null) {
            $start = $lastAssistant;
        }
        if ($lastUser !== null && $lastUser < $start) {
            $start = $lastUser;
        }
        return $start;
    }

    private static function toolCalls(string $content): array
    {
        $calls = [];
        if (!preg_match_all('/\{\s*"name"\s*:\s*"([^"]+)"\s*,\s*"args"\s*:\s*(\{(?:[^{}]|\{[^{}]*\})*\})/s', $content, $matches, PREG_SET_ORDER)) {
            return $calls;
        }
        foreach ($matches as $match) {
            $args = json_decode($match[2], true);
            $calls[] = [
                'name' => $match[1],
                'args' => is_array($args) ? $args : [],
            ];
        }
        return $calls;
    }

    private static function toolResultName(string $content): string
    {
        $content = ltrim($content);
        $rest = substr($content, strlen('TOOL RESULT '));
        $colon = strpos($rest, ':');
        if ($colon === false) {
            return '';
        }
        return trim(substr($rest, 0, $colon));
    }

    private static function isLookup(string $name, array $args, bool $found, string $content): bool
    {
        $colon = strpos($content, ':');
        $body = $colon === false ? '' : trim(substr($content, $colon + 1));
        if ($body === '' || $body === 'listing omitted' || strlen($body) <= 80) {
            return false;
        }
        if (in_array($name, ['list_files', 'list_tasks', 'list_vault', 'wiki_search'], true)) {
            return true;
        }
        $action = strtolower(trim((string)($args['action'] ?? '')));
        if ($name === 'kanban') {
            if (in_array($action, ['create', 'move', 'update', 'delete'], true)) {
                return false;
            }
            if ($action === 'list') {
                return true;
            }
            return $found;
        }
        if ($name === 'filesystem' || $name === 'wiki_memory') {
            return $action === 'list';
        }
        return false;
    }
}
