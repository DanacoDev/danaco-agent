<?php

class ChatTurn
{
    public string $streamed = '';
    public array $detail = ['thinking' => [], 'tools' => []];

    public function run(int $projectId, callable $emit, ?callable $shouldStop = null): string
    {
        $this->streamed = '';
        $this->detail = ['thinking' => [], 'tools' => []];
        $wrapped = function (array $event) use ($emit): void {
            $type = (string)($event['type'] ?? '');
            $agent = (string)($event['agent'] ?? '');
            if ($agent !== '' && $agent !== 'orchestrator' && in_array($type, ['token', 'thinking', 'tool_call', 'tool_result', 'activity'], true)) {
                return;
            }
            if ($type === 'token') {
                $this->streamed .= (string)($event['text'] ?? '');
            } elseif ($type === 'thinking') {
                $this->appendThinking((string)($event['agent'] ?? ''), (string)($event['text'] ?? ''));
            } elseif ($type === 'tool_call') {
                $this->detail['tools'][] = [
                    'agent' => (string)($event['agent'] ?? ''),
                    'name' => (string)($event['name'] ?? ''),
                    'args' => is_array($event['args'] ?? null) ? $event['args'] : [],
                    'result' => '',
                ];
            } elseif ($type === 'tool_result') {
                $name = (string)($event['name'] ?? '');
                for ($i = count($this->detail['tools']) - 1; $i >= 0; $i--) {
                    if ($this->detail['tools'][$i]['name'] === $name && $this->detail['tools'][$i]['result'] === '') {
                        $this->detail['tools'][$i]['result'] = (string)($event['text'] ?? '');
                        break;
                    }
                }
            }
            $emit($event);
        };
        $returned = app_services()['orchestrator']->handle($projectId, $wrapped, $shouldStop);
        $answer = trim($this->streamed);
        if ($answer === '') {
            $answer = trim($returned);
        }
        return $answer;
    }

    public static function encodeDetail(array $detail): ?string
    {
        $thinking = [];
        foreach ($detail['thinking'] ?? [] as $block) {
            if (!is_array($block)) {
                continue;
            }
            $text = trim((string)($block['text'] ?? ''));
            if ($text === '') {
                continue;
            }
            $thinking[] = [
                'agent' => (string)($block['agent'] ?? ''),
                'text' => self::clipThinking($text),
            ];
        }
        $tools = [];
        foreach ($detail['tools'] ?? [] as $tool) {
            if (!is_array($tool)) {
                continue;
            }
            $args = is_array($tool['args'] ?? null) ? $tool['args'] : [];
            $encoded = json_encode($args, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (is_string($encoded) && strlen($encoded) > 12000) {
                $args = ['note' => clip_text($encoded)];
            }
            $tools[] = [
                'agent' => (string)($tool['agent'] ?? ''),
                'name' => (string)($tool['name'] ?? ''),
                'args' => $args,
                'result' => clip_text((string)($tool['result'] ?? '')),
            ];
        }
        if (!$thinking && !$tools) {
            return null;
        }
        $json = json_encode(['thinking' => $thinking, 'tools' => $tools], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return is_string($json) ? $json : null;
    }

    public static function clipThinking(string $text): string
    {
        $max = 12000;
        if (strlen($text) <= $max) {
            return $text;
        }
        $note = "Earlier reasoning was set aside.\n\n";
        return $note . substr($text, -($max - strlen($note)));
    }

    private function appendThinking(string $agent, string $text): void
    {
        if ($text === '') {
            return;
        }
        $last = count($this->detail['thinking']) - 1;
        if ($last >= 0 && $this->detail['thinking'][$last]['agent'] === $agent) {
            $this->detail['thinking'][$last]['text'] .= $text;
        } else {
            $this->detail['thinking'][] = ['agent' => $agent, 'text' => $text];
            $last = count($this->detail['thinking']) - 1;
        }
        $combined = $this->detail['thinking'][$last]['text'];
        if (strlen($combined) > 16000) {
            $this->detail['thinking'][$last]['text'] = self::clipThinking($combined);
        }
    }
}
