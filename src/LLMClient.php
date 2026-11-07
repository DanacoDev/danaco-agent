<?php

class LlmException extends RuntimeException
{
    public function __construct(string $message, public bool $retryable = false)
    {
        parent::__construct($message);
    }
}

class ContextFallback extends RuntimeException
{
}

class OutputRepeated extends RuntimeException
{
}

class LLMClient
{
    public array $recoveries = [];

    public bool $incomplete = false;

    public bool $usingFailsafe = false;

    private float $gpuRestoreNotBefore = 0;

    private bool $gpuMissNoted = false;

    private int $placementTries = 0;

    private float $placementNext = 0;

    public function __construct(private Database $db, private HardwareMonitor $hardware)
    {
    }

    public ?\Closure $onCpuChange = null;

    public function onCpu(): bool
    {
        if ($this->usingFailsafe) {
            return true;
        }
        if ((string)$this->db->getSetting('model_mode', 'cpu_failsafe') === 'cpu_failsafe') {
            return true;
        }
        return $this->db->getSetting('cpu_fallback', '0') === '1';
    }

    public function placement(string $model): string
    {
        $row = $this->modelRow($model);
        if ($row === null) {
            return 'absent';
        }
        return (int)($row['size_vram'] ?? 0) > 0 ? 'gpu' : 'cpu';
    }

    public function releaseModel(string $model): void
    {
        $this->stopModel($model);
    }

    public function releaseOthers(string $keep): void
    {
        if (!$this->usableModel($keep)) {
            return;
        }
        foreach ($this->runningModels() as $row) {
            if ($this->sameModel($row['name'], $keep)) {
                continue;
            }
            $this->stopModel($row['name']);
        }
    }

    public function runningModels(): array
    {
        $json = $this->ollamaCall('GET', '/api/ps', null, 4);
        if ($json === null) {
            return [];
        }
        $rows = [];
        foreach ($json['models'] ?? [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $name = trim((string)($row['name'] ?? $row['model'] ?? ''));
            if (!$this->usableModel($name)) {
                continue;
            }
            $rows[] = [
                'name' => $name,
                'place' => (int)($row['size_vram'] ?? 0) > 0 ? 'gpu' : 'cpu',
            ];
        }
        return $rows;
    }

    public function modelMatches(string $running, string $wanted): bool
    {
        return $this->sameModel($running, $wanted);
    }

    public function restoreGpu(?callable $onNote = null): void
    {
        $mode = (string)$this->db->getSetting('model_mode', 'cpu_failsafe');
        if ($mode !== 'local') {
            return;
        }
        $model = trim((string)$this->db->getSetting('local_model', app_config()['local_model']));
        if (!$this->usableModel($model)) {
            return;
        }
        $place = $this->placement($model);
        if ($place === 'gpu') {
            if ($this->usingFailsafe || $this->db->getSetting('cpu_fallback', '0') === '1') {
                $this->leaveFailsafe();
                $this->sayGpu($onNote, 'The GPU model is loaded. Continuing with it.');
            }
            return;
        }
        if ($place !== 'cpu' && !$this->usingFailsafe && $this->db->getSetting('cpu_fallback', '0') !== '1') {
            return;
        }
        if ($place === 'cpu') {
            $this->markCpu(true);
        }
        if (microtime(true) < $this->gpuRestoreNotBefore) {
            return;
        }
        $this->gpuRestoreNotBefore = microtime(true) + 180;
        $this->sayGpu($onNote, 'Loading the GPU model again.');
        if (!$this->loadOntoGpu($model)) {
            $this->markCpu(true);
            if ($this->usingFailsafe) {
                $failsafeModel = trim((string)$this->db->getSetting('cpu_failsafe_model', app_config()['cpu_failsafe_model']));
                if (!$this->sameModel($failsafeModel, $model)) {
                    $this->stopModel($model);
                }
            }
            if (!$this->gpuMissNoted) {
                $this->gpuMissNoted = true;
                $this->sayGpu($onNote, $this->usingFailsafe
                    ? 'The GPU model is still on the CPU. Continuing with the CPU failsafe.'
                    : 'The local model is running on the CPU.');
            }
            return;
        }
        $this->leaveFailsafe();
        $this->gpuMissNoted = false;
        $failsafe = trim((string)$this->db->getSetting('cpu_failsafe_model', app_config()['cpu_failsafe_model']));
        if (!$this->sameModel($failsafe, $model)) {
            $this->stopModel($failsafe);
        }
        $this->sayGpu($onNote, 'The GPU model is loaded. Continuing with it.');
    }

    public function stream(array $messages, callable $onToken, ?callable $shouldStop = null, ?callable $onThinking = null, bool $cloud = false, string $agent = ''): string
    {
        if (!$this->usingFailsafe && (string)$this->db->getSetting('model_mode', '') === 'local') {
            $this->releaseOthers((string)$this->db->getSetting('local_model', app_config()['local_model']));
        }
        $this->restoreGpu();
        $last = null;
        for ($try = 1; $try <= 3; $try++) {
            if ($shouldStop && $shouldStop()) {
                throw new ChatStopped('Stopped.');
            }
            try {
                return $this->attempt($messages, $onToken, $shouldStop, $onThinking, $cloud, $agent, $this->usingFailsafe);
            } catch (ChatStopped $e) {
                throw $e;
            } catch (OutputRepeated $e) {
                throw $e;
            } catch (LlmException $e) {
                $last = $e;
                if ($try >= 3) {
                    break;
                }
                $note = $this->recover($e->getMessage());
                if ($note === null) {
                    break;
                }
                if ($note !== '') {
                    $this->recoveries[] = $note;
                }
            }
        }
        if ($last && $this->shouldStepDown($last->getMessage(), $cloud)) {
            $this->usingFailsafe = true;
            $this->db->setSetting('cpu_fallback', '1');
            $this->recoveries[] = 'The model connection was lost. Continuing with the CPU failsafe.';
            throw new ContextFallback($last->getMessage());
        }
        throw $last ?? new LlmException('The model failed.', false);
    }

    public function yesNo(string $prompt, ?callable $shouldStop = null): ?bool
    {
        try {
            if ($shouldStop && $shouldStop()) {
                return null;
            }
        } catch (Throwable $e) {
            return null;
        }
        $text = $this->askYesNo($prompt, $shouldStop);
        if ($text === null) {
            return null;
        }
        return $this->readYesNo($text);
    }

    private function shouldStepDown(string $error, bool $cloud): bool
    {
        if ($this->usingFailsafe) {
            return false;
        }
        $mode = (string)$this->db->getSetting('model_mode', 'cpu_failsafe');
        if ($mode === 'cpu_failsafe') {
            return false;
        }
        if ($cloud && $mode !== 'cloud') {
            return false;
        }
        return $this->connectionLost($error);
    }

    private function connectionLost(string $error): bool
    {
        return (bool)preg_match('/cannot reach ollama|cannot reach the cloud|connection refused|could not resolve|timed out|connection reset|empty reply from server|transfer closed|network is unreachable|failed to connect/i', $error);
    }

    private function recover(string $error): ?string
    {
        $script = dirname(__DIR__) . '/bin/recover.php';
        $php = PHP_BINARY !== '' ? PHP_BINARY : 'php';
        if (!is_file($script)) {
            $result = (new Recovery($this->db))->repair($error);
            return !empty($result['retry']) ? (string)$result['note'] : null;
        }
        $run = Process::run([$php, $script, clip_text($error, 500)], dirname(__DIR__), null, 25);
        if ((int)$run['code'] === 0) {
            return trim($run['stdout']);
        }
        if ((int)$run['code'] === 2) {
            return null;
        }
        $result = (new Recovery($this->db))->repair($error);
        return !empty($result['retry']) ? (string)$result['note'] : null;
    }

    private function attempt(array $messages, callable $onToken, ?callable $shouldStop, ?callable $onThinking = null, bool $cloud = false, string $agent = '', bool $failsafe = false): string
    {
        $this->incomplete = false;
        $mode = $failsafe ? 'cpu_failsafe' : (string)$this->db->getSetting('model_mode', 'cpu_failsafe');
        $numCtx = $this->hardware->effectiveContext($mode);
        if ($this->db->getSetting('recovery_hint', '0') === '1') {
            $hint = $agent === 'orchestrator'
                ? 'The reply ran out of room while thinking, so this pass has more room. Finish the thought, then write the answer or the tool call.'
                : 'The reply ran out of room while thinking, so this pass has more room. Finish the thought, then write the answer.';
            $messages[] = ['role' => 'user', 'content' => $hint];
            $this->db->setSetting('recovery_hint', '0');
        }
        if (!$failsafe && ($cloud || $mode === 'cloud')) {
            return $this->streamOpenAi($messages, $onToken, $shouldStop, $onThinking);
        }
        $model = $mode === 'cpu_failsafe'
            ? (string)$this->db->getSetting('cpu_failsafe_model', app_config()['cpu_failsafe_model'])
            : (string)$this->db->getSetting('local_model', app_config()['local_model']);
        if ($mode !== 'cpu_failsafe' && $this->placement($model) === 'cpu') {
            $this->markCpu(true);
        }
        $base = rtrim((string)$this->db->getSetting('ollama_url', app_config()['ollama_url']), '/');
        $predict = 49152;
        $predictOverride = (int)$this->db->getSetting('recovery_num_predict', '0');
        if ($predictOverride >= 256) {
            $predict = $predictOverride;
        }
        $options = [
            'num_ctx' => $numCtx,
            'num_predict' => $predict,
            'use_mmap' => true,
            'use_mlock' => false,
        ];
        if ($mode === 'local') {
            $options['num_gpu'] = 999;
        }
        $payload = json_encode([
            'model' => $model,
            'messages' => $messages,
            'stream' => true,
            'keep_alive' => '1h',
            'options' => $options,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        if (!is_string($payload) || $payload === '') {
            throw new LlmException('The model request could not be encoded.', false);
        }
        return $this->curlStream($base . '/api/chat', $payload, [], $onToken, true, $base, $model, $shouldStop, $onThinking);
    }

    private function streamOpenAi(array $messages, callable $onToken, ?callable $shouldStop, ?callable $onThinking = null): string
    {
        $key = trim((string)$this->db->getSetting('cloud_api_key', ''));
        $base = rtrim((string)$this->db->getSetting('cloud_base_url', app_config()['cloud_base_url']), '/');
        $model = (string)$this->db->getSetting('cloud_model', app_config()['cloud_model']);
        if ($key === '') {
            throw new LlmException('Cloud model mode needs an API key in Setup.');
        }
        $payload = json_encode([
            'model' => $model,
            'messages' => $messages,
            'stream' => true,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        if (!is_string($payload) || $payload === '') {
            throw new LlmException('The model request could not be encoded.', false);
        }
        return $this->curlStream($base . '/chat/completions', $payload, [
            'Authorization: Bearer ' . $key,
        ], $onToken, false, $base, $model, $shouldStop, $onThinking);
    }

    private function curlStream(string $url, string $payload, array $headers, callable $onToken, bool $ollama, string $base, string $model, ?callable $shouldStop, ?callable $onThinking = null): string
    {
        $buffer = '';
        $full = '';
        $errorBody = '';
        $doneReason = '';
        $thinking = '';
        $toolNames = [];
        $this->placementTries = 0;
        $this->placementNext = 0;
        $ch = curl_init($url);
        if ($ch === false) {
            throw new LlmException('Could not start the model request.');
        }
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => array_merge([
                'Content-Type: application/json',
                'Expect:',
            ], $headers),
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_NOPROGRESS => false,
            CURLOPT_PROGRESSFUNCTION => function ($resource, $downloadTotal, $downloaded, $uploadTotal, $uploaded) use ($shouldStop, $ollama, $model): int {
                try {
                    if ($ollama) {
                        $this->watchPlacement($model);
                    }
                    return ($shouldStop && $shouldStop()) ? 1 : 0;
                } catch (ChatStopped $e) {
                    return 1;
                } catch (Throwable $e) {
                    return 0;
                }
            },
            CURLOPT_WRITEFUNCTION => function ($ch, string $data) use (&$buffer, &$full, &$errorBody, &$doneReason, &$thinking, &$toolNames, $onToken, $onThinking, $ollama, $shouldStop): int {
                try {
                    if ($shouldStop && $shouldStop()) {
                        return 0;
                    }
                } catch (ChatStopped $e) {
                    return 0;
                } catch (Throwable $e) {
                    return strlen($data);
                }
                $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
                if ($code >= 400) {
                    $errorBody .= $data;
                    return strlen($data);
                }
                $buffer .= $data;
                while (($pos = strpos($buffer, "\n")) !== false) {
                    $line = trim(substr($buffer, 0, $pos));
                    $buffer = substr($buffer, $pos + 1);
                    if ($line === '' || $line === 'data: [DONE]') {
                        continue;
                    }
                    if (str_starts_with($line, 'data:')) {
                        $line = trim(substr($line, 5));
                    }
                    $json = json_decode($line, true);
                    if (!is_array($json)) {
                        continue;
                    }
                    if (isset($json['error'])) {
                        $errorBody .= is_string($json['error']) ? $json['error'] : json_encode($json['error']);
                    }
                    if (isset($json['done_reason']) && is_string($json['done_reason'])) {
                        $doneReason = $json['done_reason'];
                    }
                    $message = is_array($json['message'] ?? null) ? $json['message'] : [];
                    $thought = (string)($message['thinking'] ?? $message['reasoning'] ?? '');
                    if ($thought === '' && !$ollama) {
                        $delta = $json['choices'][0]['delta'] ?? null;
                        if (is_array($delta)) {
                            $thought = (string)($delta['reasoning'] ?? $delta['reasoning_content'] ?? '');
                        }
                    }
                    if ($thought !== '') {
                        $thinking .= $thought;
                        if (strlen($thinking) > 4000) {
                            $thinking = substr($thinking, -4000);
                        }
                        if ($onThinking) {
                            $onThinking($thought);
                        }
                    }
                    $calls = $message['tool_calls'] ?? [];
                    if (!is_array($calls)) {
                        $calls = [];
                    }
                    foreach ($calls as $call) {
                        if (!is_array($call)) {
                            continue;
                        }
                        $name = (string)($call['function']['name'] ?? $call['name'] ?? '');
                        if ($name !== '' && !in_array($name, $toolNames, true)) {
                            $toolNames[] = $name;
                        }
                    }
                    $token = $ollama
                        ? (string)($message['content'] ?? '')
                        : (string)($json['choices'][0]['delta']['content'] ?? '');
                    if ($token !== '') {
                        $full .= $token;
                        $onToken($token);
                    }
                }
                return strlen($data);
            },
        ]);
        $ok = curl_exec($ch);
        $errno = curl_errno($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        unset($ch);
        $stopped = false;
        try {
            $stopped = $shouldStop && $shouldStop();
        } catch (ChatStopped $e) {
            $stopped = true;
        }
        $failed = $ok === false || $errno !== 0 || $code >= 400 || $errorBody !== '';
        if ($failed && trim($full) !== '' && !$stopped) {
            $this->incomplete = true;
            return $full;
        }
        if ($stopped) {
            throw new ChatStopped('Stopped.');
        }
        if ($ok === false || $errno !== 0) {
            $hint = $ollama
                ? 'Cannot reach Ollama at ' . $base . '. Start Ollama or change the model mode in Setup.'
                : 'Cannot reach the cloud API at ' . $base . '.';
            throw new LlmException($hint . ($err !== '' ? ' ' . $err : ''), false);
        }
        if ($code >= 400 || $errorBody !== '') {
            if (!$ollama && $this->isRejectedKey($errorBody, $code)) {
                throw new LlmException('Wrong API key or endpoint.', false);
            }
            $detail = trim($errorBody) !== '' ? clip_text($errorBody, 500) : 'HTTP ' . $code;
            $retryable = $code >= 500 || $this->isOom($errorBody);
            throw new LlmException('Model ' . $model . ' failed: ' . $detail, $retryable);
        }
        if (trim($full) === '') {
            throw new LlmException($this->emptyAnswer($model, $doneReason, $thinking, $toolNames), false);
        }
        if ($doneReason === 'length') {
            $this->incomplete = true;
        }
        return $full;
    }

    private function emptyAnswer(string $model, string $doneReason, string $thinking, array $toolNames): string
    {
        $reasons = [];
        if ($toolNames) {
            $reasons[] = 'It tried to call ' . implode(', ', $toolNames) . ', and that call was not run.';
        }
        if (trim($thinking) !== '') {
            $reasons[] = 'It returned thinking only and no answer.';
        }
        if ($doneReason === 'length') {
            $reasons[] = 'It hit the output limit before writing an answer.';
        } elseif ($doneReason !== '' && $doneReason !== 'stop') {
            $reasons[] = 'It stopped because: ' . $doneReason . '.';
        }
        if (!$reasons) {
            $reasons[] = 'It returned an empty answer.';
        }
        return 'Model ' . $model . ' did not reply. ' . implode(' ', $reasons);
    }

    private function isOom(string $text): bool
    {
        return (bool)preg_match('/out of memory|cuda|\\boom\\b|requires more system memory|model requires more/i', $text);
    }

    private function isRejectedKey(string $text, int $code): bool
    {
        if (preg_match('/invalid[_\s-]?api[_\s-]?key|invalid_key|please provide valid api key/i', $text)) {
            return true;
        }
        return $code === 401;
    }

    private function leaveFailsafe(): void
    {
        $this->usingFailsafe = false;
        $this->markCpu(false);
    }

    private function markCpu(bool $cpu): void
    {
        $this->db->setSetting('cpu_fallback', $cpu ? '1' : '0');
        if ($this->onCpuChange) {
            ($this->onCpuChange)($cpu || $this->usingFailsafe);
        }
    }

    private function watchPlacement(string $model): void
    {
        if ($this->placementTries >= 3 || microtime(true) < $this->placementNext) {
            return;
        }
        $this->placementTries++;
        $this->placementNext = microtime(true) + 3;
        $place = $this->placement($model);
        if ($place === 'absent') {
            return;
        }
        $this->placementTries = 3;
        if ($place === 'gpu' && !$this->usingFailsafe) {
            $this->leaveFailsafe();
            return;
        }
        $this->markCpu(true);
    }

    private function sayGpu(?callable $onNote, string $text): void
    {
        if ($onNote) {
            $onNote($text);
            return;
        }
        $this->recoveries[] = $text;
    }

    private function modelOnGpu(string $model): bool
    {
        return $this->placement($model) === 'gpu';
    }

    private function modelRow(string $model): ?array
    {
        $json = $this->ollamaCall('GET', '/api/ps', null, 2);
        if ($json === null) {
            return null;
        }
        foreach ($json['models'] ?? [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $name = (string)($row['name'] ?? $row['model'] ?? '');
            if ($this->sameModel($name, $model)) {
                return $row;
            }
        }
        return null;
    }

    private function loadOntoGpu(string $model): bool
    {
        if ($this->placement($model) === 'cpu') {
            $this->stopModel($model);
        }
        $payload = json_encode([
            'model' => $model,
            'prompt' => 'hi',
            'stream' => false,
            'keep_alive' => '1h',
            'options' => ['num_predict' => 1, 'num_gpu' => 999],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($payload)) {
            return false;
        }
        $this->ollamaCall('POST', '/api/generate', $payload, 300);
        return $this->modelOnGpu($model);
    }

    private function stopModel(string $model): void
    {
        if (!$this->usableModel($model)) {
            return;
        }
        $payload = json_encode(['model' => $model, 'keep_alive' => 0, 'prompt' => '', 'stream' => false]);
        $chat = json_encode(['model' => $model, 'keep_alive' => 0, 'messages' => [], 'stream' => false]);
        if (is_string($chat)) {
            $this->ollamaCall('POST', '/api/chat', $chat, 20);
        }
        if (!is_string($payload)) {
            return;
        }
        $this->ollamaCall('POST', '/api/generate', $payload, 20);
    }

    private function ollamaCall(string $method, string $path, ?string $body, int $timeout): ?array
    {
        $base = rtrim((string)$this->db->getSetting('ollama_url', app_config()['ollama_url']), '/');
        if (!preg_match('#^https?://\S+$#', $base)) {
            return null;
        }
        $ch = curl_init($base . $path);
        if ($ch === false) {
            return null;
        }
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Expect:'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 3,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $raw = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        unset($ch);
        if (!is_string($raw) || $raw === '' || $code >= 400) {
            return null;
        }
        $json = json_decode($raw, true);
        return is_array($json) ? $json : null;
    }

    private function sameModel(string $running, string $wanted): bool
    {
        $running = strtolower(trim($running));
        $wanted = strtolower(trim($wanted));
        if (str_ends_with($running, ':latest')) {
            $running = substr($running, 0, -strlen(':latest'));
        }
        if (str_ends_with($wanted, ':latest')) {
            $wanted = substr($wanted, 0, -strlen(':latest'));
        }
        return $running !== '' && $running === $wanted;
    }

    private function usableModel(string $name): bool
    {
        return $name !== '' && strlen($name) <= 120 && (bool)preg_match('/^[A-Za-z0-9_.:\/-]+$/', $name);
    }

    private function askYesNo(string $prompt, ?callable $shouldStop): ?string
    {
        $failsafe = $this->usingFailsafe;
        $mode = $failsafe ? 'cpu_failsafe' : (string)$this->db->getSetting('model_mode', 'cpu_failsafe');
        $messages = [['role' => 'user', 'content' => $prompt]];
        if (!$failsafe && $mode === 'cloud') {
            return $this->askYesNoCloud($messages, $shouldStop);
        }
        $model = $mode === 'cpu_failsafe'
            ? (string)$this->db->getSetting('cpu_failsafe_model', app_config()['cpu_failsafe_model'])
            : (string)$this->db->getSetting('local_model', app_config()['local_model']);
        $base = rtrim((string)$this->db->getSetting('ollama_url', app_config()['ollama_url']), '/');
        $options = [
            'num_ctx' => $this->hardware->effectiveContext($mode),
            'num_predict' => 8,
            'use_mmap' => true,
            'use_mlock' => false,
        ];
        if ($mode === 'local') {
            $options['num_gpu'] = 999;
        }
        $payload = json_encode([
            'model' => $model,
            'messages' => $messages,
            'stream' => false,
            'think' => false,
            'keep_alive' => '1h',
            'options' => $options,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        if (!is_string($payload) || $payload === '') {
            return null;
        }
        $raw = $this->postJson($base . '/api/chat', $payload, [], $shouldStop);
        if ($raw === null) {
            return null;
        }
        $json = json_decode($raw, true);
        if (!is_array($json)) {
            return null;
        }
        $message = is_array($json['message'] ?? null) ? $json['message'] : [];
        $content = trim((string)($message['content'] ?? ''));
        return $content !== '' ? $content : null;
    }

    private function askYesNoCloud(array $messages, ?callable $shouldStop): ?string
    {
        $key = trim((string)$this->db->getSetting('cloud_api_key', ''));
        $base = rtrim((string)$this->db->getSetting('cloud_base_url', app_config()['cloud_base_url']), '/');
        $model = (string)$this->db->getSetting('cloud_model', app_config()['cloud_model']);
        if ($key === '') {
            return null;
        }
        $payload = json_encode([
            'model' => $model,
            'messages' => $messages,
            'stream' => false,
            'max_tokens' => 8,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        if (!is_string($payload) || $payload === '') {
            return null;
        }
        $raw = $this->postJson($base . '/chat/completions', $payload, [
            'Authorization: Bearer ' . $key,
        ], $shouldStop);
        if ($raw === null) {
            return null;
        }
        $json = json_decode($raw, true);
        if (!is_array($json)) {
            return null;
        }
        $content = trim((string)($json['choices'][0]['message']['content'] ?? ''));
        return $content !== '' ? $content : null;
    }

    private function postJson(string $url, string $payload, array $headers, ?callable $shouldStop): ?string
    {
        $ch = curl_init($url);
        if ($ch === false) {
            return null;
        }
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => array_merge([
                'Content-Type: application/json',
                'Expect:',
            ], $headers),
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 45,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_NOPROGRESS => false,
            CURLOPT_PROGRESSFUNCTION => function ($resource, $downloadTotal, $downloaded, $uploadTotal, $uploaded) use ($shouldStop): int {
                try {
                    return ($shouldStop && $shouldStop()) ? 1 : 0;
                } catch (Throwable $e) {
                    return 1;
                }
            },
        ]);
        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        unset($ch);
        if (!is_string($raw) || $raw === '' || $errno !== 0 || $code >= 400) {
            return null;
        }
        return $raw;
    }

    private function readYesNo(string $text): ?bool
    {
        $text = trim($text);
        $text = preg_replace('/<(?:think|thinking|reasoning|thought)\b[^>]*>[\s\S]*?<\/(?:think|thinking|reasoning|thought)>/i', '', $text) ?? $text;
        $text = strtolower(trim($text));
        $yes = preg_match_all('/\byes\b/', $text);
        $no = preg_match_all('/\bno\b/', $text);
        if ($yes > 0 && $no === 0) {
            return true;
        }
        if ($no > 0 && $yes === 0) {
            return false;
        }
        return null;
    }
}
