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
        if ($this->isFullGpu($error)) {
            $notes[] = 'The whole model does not fit on the GPU. Loading what fits and leaving the rest in RAM.';
            $retry = true;
        } elseif (!$this->isLength($error) && ($this->isOom($error) || ($this->isModelFailure($error) && $this->memoryTight()))) {
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
        if ($this->stoppedWhileThinking($error)) {
            $notes[] = 'The reply stopped while thinking. Pausing, then trying again.';
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
        return (bool)preg_match('/model .+ failed|did not reply|cannot reach ollama|missing request body|out of memory|requires more system memory|\boom\b|http \d{3}|error in input stream|stopped while thinking/i', $error);
    }

    private function isOom(string $error): bool
    {
        return (bool)preg_match('/out of memory|cuda|\\boom\\b|requires more system memory|model requires more/i', $error);
    }

    private function isFullGpu(string $error): bool
    {
        return (bool)preg_match('/cudaMalloc|unable to allocate CUDA|projector CPU offload/i', $error);
    }

    private function isLength(string $error): bool
    {
        return (bool)preg_match('/output limit|thinking only|empty answer/i', $error);
    }

    private function stoppedWhileThinking(string $error): bool
    {
        return (bool)preg_match('/stopped while thinking|error in input stream/i', $error);
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
        $names = $this->loadedModels($base);
        if (!$names) {
            return 0;
        }
        $ollama = $this->ollamaBin();
        foreach ($names as $name) {
            $chat = json_encode(['model' => $name, 'messages' => [], 'keep_alive' => 0, 'stream' => false]);
            $generate = json_encode(['model' => $name, 'prompt' => '', 'keep_alive' => 0, 'stream' => false]);
            if (is_string($chat)) {
                $this->http('POST', $base . '/api/chat', $chat, 20);
            }
            if (is_string($generate)) {
                $this->http('POST', $base . '/api/generate', $generate, 20);
            }
            if ($ollama !== '') {
                Process::run([$ollama, 'stop', $name], null, null, 30);
            }
        }
        for ($i = 0; $i < 8; $i++) {
            if (!$this->loadedModels($base)) {
                break;
            }
            usleep(300000);
        }
        return count($names);
    }

    private function loadedModels(string $base): array
    {
        $raw = $this->http('GET', $base . '/api/ps', null);
        $json = json_decode($raw, true);
        $models = is_array($json['models'] ?? null) ? $json['models'] : [];
        $names = [];
        foreach ($models as $model) {
            if (!is_array($model)) {
                continue;
            }
            $name = trim((string)($model['name'] ?? $model['model'] ?? ''));
            if ($name === '' || !preg_match('/^[A-Za-z0-9_.:\/-]+$/', $name)) {
                continue;
            }
            $names[] = $name;
        }
        return $names;
    }

    private function ollamaBin(): string
    {
        foreach (['/opt/homebrew/bin/ollama', '/usr/local/bin/ollama', '/usr/bin/ollama'] as $path) {
            if (is_executable($path)) {
                return $path;
            }
        }
        if (!Process::commandExists('ollama')) {
            return '';
        }
        $found = Process::run(['/bin/sh', '-c', 'command -v ollama'], null, null, 5);
        $path = trim($found['stdout']);
        return $path !== '' && is_executable($path) ? $path : '';
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

    private function http(string $method, string $url, ?string $body, int $timeout = 8): string
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
            CURLOPT_TIMEOUT => $timeout,
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
