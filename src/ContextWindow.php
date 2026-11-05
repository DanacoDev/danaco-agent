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
        $budget = (int)max(256, (int)floor($total * 0.75));
        $raw = self::tokens($system) + 8;
        foreach ($history as $message) {
            $raw += self::tokens((string)($message['content'] ?? '')) + 4;
        }
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
        $budget = (int)max(256, (int)floor($total * 0.75));
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
}
