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

    public function __construct(private Database $db, private HardwareMonitor $hardware)
    {
    }

    public function stream(array $messages, callable $onToken, ?callable $shouldStop = null, ?callable $onThinking = null, bool $cloud = false, string $agent = ''): string
    {
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
            $this->recoveries[] = 'The model connection was lost. Continuing with the CPU failsafe.';
            throw new ContextFallback($last->getMessage());
        }
        throw $last ?? new LlmException('The model failed.', false);
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
                ? 'The reply ran out of room. Try again by splitting the task up and delegating each piece to one subagent. Send that delegate call now.'
                : 'Continue from the work so far and write the answer now. Keep any thinking short.';
            $messages[] = ['role' => 'user', 'content' => $hint];
            $this->db->setSetting('recovery_hint', '0');
        }
        if (!$failsafe && ($cloud || $mode === 'cloud')) {
            return $this->streamOpenAi($messages, $onToken, $shouldStop, $onThinking);
        }
        $model = $mode === 'cpu_failsafe'
            ? (string)$this->db->getSetting('cpu_failsafe_model', app_config()['cpu_failsafe_model'])
            : (string)$this->db->getSetting('local_model', app_config()['local_model']);
        $base = rtrim((string)$this->db->getSetting('ollama_url', app_config()['ollama_url']), '/');
        $predict = 49152;
        $predictOverride = (int)$this->db->getSetting('recovery_num_predict', '0');
        if ($predictOverride >= 256) {
            $predict = $predictOverride;
        }
        $payload = json_encode([
            'model' => $model,
            'messages' => $messages,
            'stream' => true,
            'keep_alive' => '1h',
            'options' => [
                'num_ctx' => $numCtx,
                'num_predict' => $predict,
                'use_mmap' => true,
                'use_mlock' => false,
            ],
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
            CURLOPT_PROGRESSFUNCTION => function ($resource, $downloadTotal, $downloaded, $uploadTotal, $uploaded) use ($shouldStop): int {
                try {
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
}
