<?php

class Recovery
{
    public function __construct(private Database $db)
    {
    }

    public static function reset(Database $db): void
    {
        $db->setSetting('recovery_num_ctx', '0');
        $db->setSetting('recovery_num_predict', '0');
        $db->setSetting('recovery_hint', '0');
    }

    public function releaseMemory(): int
    {
        return $this->unloadModels();
    }

    public function repair(string $error): array
    {
        $error = trim($error);
        if (!$this->isModelFailure($error)) {
            return ['retry' => false, 'note' => ''];
        }
        $notes = [];
        $retry = false;
        if ($this->isUnreachable($error) && $this->isLocalOllama() && $this->startOllama()) {
            $notes[] = 'Ollama was not running. Started it.';
            $retry = true;
        }
        if (!$this->isLength($error) && ($this->isOom($error) || ($this->isModelFailure($error) && $this->memoryTight()))) {
            $freed = $this->unloadModels();
            if ($freed > 0) {
                $notes[] = 'Freed memory by unloading ' . $freed . ' model' . ($freed === 1 ? '' : 's') . '.';
            } else {
                $notes[] = 'Checked memory.';
            }
            $this->shrinkContext();
            $notes[] = 'Lowered the context window so the model needs less memory.';
            $retry = true;
        }
        if ($this->isLength($error)) {
            $this->moreRoom();
            $this->db->setSetting('recovery_hint', '1');
            $notes[] = 'The reply ran out of room while thinking. Trying again with more room.';
            $retry = true;
        }
        if (!$retry && ($this->isMissingBody($error) || $this->isUnreachable($error))) {
            $notes[] = 'The model request did not go through. Sending it again.';
            $retry = true;
        }
        if (!$retry) {
            return ['retry' => false, 'note' => ''];
        }
        return ['retry' => true, 'note' => implode(' ', $notes)];
    }

    public function isModelFailure(string $error): bool
    {
        return (bool)preg_match('/model .+ failed|did not reply|cannot reach ollama|missing request body|out of memory|requires more system memory|\boom\b|http \d{3}/i', $error);
    }

    private function isOom(string $error): bool
    {
        return (bool)preg_match('/out of memory|cuda|\\boom\\b|requires more system memory|model requires more/i', $error);
    }

    private function isLength(string $error): bool
    {
        return (bool)preg_match('/output limit|thinking only|empty answer/i', $error);
    }

    private function isMissingBody(string $error): bool
    {
        return str_contains(strtolower($error), 'missing request body');
    }

    private function isUnreachable(string $error): bool
    {
        return (bool)preg_match('/cannot reach ollama|connection refused|could not resolve|timed out/i', $error);
    }

    private function isLocalOllama(): bool
    {
        $base = rtrim((string)$this->db->getSetting('ollama_url', app_config()['ollama_url']), '/');
        $host = (string)parse_url($base, PHP_URL_HOST);
        return in_array($host, ['127.0.0.1', 'localhost', '::1'], true);
    }

    private function ollamaBase(): string
    {
        return rtrim((string)$this->db->getSetting('ollama_url', app_config()['ollama_url']), '/');
    }

    private function memoryTight(): bool
    {
        $snap = (new HardwareMonitor($this->db))->snapshot();
        $available = (int)($snap['system_memory']['available_mb'] ?? 0);
        if ($available > 0 && $available < 1500) {
            return true;
        }
        return false;
    }

    private function shrinkContext(): void
    {
        $mode = (string)$this->db->getSetting('model_mode', 'cpu_failsafe');
        $current = (int)$this->db->getSetting('recovery_num_ctx', '0');
        if ($current < 1024) {
            $current = (new HardwareMonitor($this->db))->recommendContext($mode);
        }
        $next = max(1024, intdiv($current, 2));
        $this->db->setSetting('recovery_num_ctx', (string)$next);
        $this->db->setSetting('recovery_num_predict', (string)min(4096, $next));
    }

    private function moreRoom(): void
    {
        $current = (int)$this->db->getSetting('recovery_num_predict', '0');
        if ($current < 49152) {
            $current = 49152;
        }
        $this->db->setSetting('recovery_num_predict', (string)min(131072, $current + 49152));
    }

    private function startOllama(): bool
    {
        if (!Process::commandExists('ollama')) {
            return false;
        }
        $log = app_config()['data_dir'] . '/ollama.log';
        Process::run(['/bin/sh', '-c', 'nohup ollama serve >> ' . escapeshellarg($log) . ' 2>&1 &'], null, null, 5);
        $base = $this->ollamaBase();
        for ($i = 0; $i < 16; $i++) {
            if ($this->ping($base . '/api/tags')) {
                return true;
            }
            usleep(500000);
        }
        return false;
    }

    private function unloadModels(): int
    {
        $base = $this->ollamaBase();
        $raw = $this->http('GET', $base . '/api/ps', null);
        $json = json_decode($raw, true);
        $models = is_array($json['models'] ?? null) ? $json['models'] : [];
        $count = 0;
        foreach ($models as $model) {
            $name = trim((string)($model['name'] ?? $model['model'] ?? ''));
            if ($name === '' || !preg_match('/^[A-Za-z0-9_.:\/-]+$/', $name)) {
                continue;
            }
            if (Process::commandExists('ollama')) {
                Process::run(['ollama', 'stop', $name], null, null, 20);
            }
            $this->http('POST', $base . '/api/generate', json_encode(['model' => $name, 'keep_alive' => 0]));
            $count++;
        }
        return $count;
    }

    private function ping(string $url): bool
    {
        $ch = curl_init($url);
        if ($ch === false) {
            return false;
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 2,
            CURLOPT_CONNECTTIMEOUT => 2,
        ]);
        curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        unset($ch);
        return $code >= 200 && $code < 500;
    }

    private function http(string $method, string $url, ?string $body): string
    {
        $ch = curl_init($url);
        if ($ch === false) {
            return '';
        }
        $headers = ['Content-Type: application/json', 'Expect:'];
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_CONNECTTIMEOUT => 2,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $raw = curl_exec($ch);
        unset($ch);
        return is_string($raw) ? $raw : '';
    }
}
