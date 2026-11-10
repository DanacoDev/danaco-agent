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

    public bool $ranOut = false;

    public string $doneReason = '';

    public bool $brief = false;

    public bool $usingFailsafe = false;

    private int $attemptTry = 1;

    private float $gpuRestoreNotBefore = 0;

    private bool $gpuMissNoted = false;

    private int $placementTries = 0;

    private float $placementNext = 0;

    private array $fitCache = [];

    private array $ctxCache = [];

    private ?string $gpuSkip = null;

    private bool $omitThink = false;

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

    public function releaseLocalModel(): void
    {
        if ($this->onCpu() || (string)$this->db->getSetting('model_mode', '') !== 'local') {
            return;
        }
        $model = (string)$this->db->getSetting('local_model', app_config()['local_model']);
        $this->stopModel($model);
    }

    public function releaseIfIdle(): void
    {
        if ($this->db->anyRunningJob()) {
            return;
        }
        $this->releaseLocalModel();
        if ((string)$this->db->getSetting('model_mode', '') === 'local' && !$this->onCpu()) {
            $this->db->loop('orchestrator', 'process', 'Context released');
        }
    }

    public function releaseOthers(string $keep): bool
    {
        if (!$this->usableModel($keep)) {
            return false;
        }
        $holding = $this->gpuHolders($keep);
        if ($holding === null || $holding === []) {
            return true;
        }
        foreach ($holding as $name) {
            $this->stopModel($name);
        }
        $deadline = microtime(true) + 60;
        while (microtime(true) < $deadline) {
            $holding = $this->gpuHolders($keep);
            if ($holding === []) {
                return true;
            }
            usleep(400000);
        }
        return $this->gpuHolders($keep) === [];
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

    public function wholeModelOnGpu(string $model): bool
    {
        return $this->gpuOptions($model, $this->hardware->effectiveContext('local')) !== [];
    }

    public function gpuOptions(string $model, int $wanted): array
    {
        $fitted = $this->fittedContext($model, $wanted);
        if ($fitted === null) {
            return [];
        }
        return ['num_gpu' => 999, 'num_ctx' => $fitted];
    }

    private function fittedContext(string $model, int $wanted): ?int
    {
        $key = strtolower(trim($model));
        $this->gpuSkip = null;
        if ($key === '' || $wanted < 512) {
            $this->gpuSkip = 'size';
            return null;
        }
        $cacheKey = $key . ':' . $wanted;
        if (array_key_exists($cacheKey, $this->ctxCache)) {
            $cached = $this->ctxCache[$cacheKey];
            if (!is_int($cached)) {
                $this->gpuSkip = is_string($cached) ? $cached : 'size';
                return null;
            }
            return $cached;
        }
        $vram = $this->hardware->gpuMemory();
        $totalMb = is_array($vram) ? (int)($vram['total_mb'] ?? 0) : 0;
        $bytes = $this->modelBytes($model);
        if ($bytes <= 0) {
            $row = $this->modelRow($model);
            $bytes = is_array($row) ? (int)($row['size'] ?? 0) : 0;
        }
        if ($totalMb <= 0 || $bytes <= 0) {
            $this->gpuSkip = $totalMb <= 0 ? 'no vram' : 'size';
            return null;
        }
        $modelMb = (int)ceil($bytes / 1048576);
        $budgetMb = (int)floor($totalMb * 0.9) - $modelMb - 768;
        if ($budgetMb < 512) {
            $this->ctxCache[$cacheKey] = 'larger';
            $this->gpuSkip = 'larger';
            return null;
        }
        $maxCtx = (int)(floor(($budgetMb / 154) * 1024 / 1024) * 1024);
        if ($maxCtx < 2048) {
            $this->ctxCache[$cacheKey] = 'context';
            $this->gpuSkip = 'context';
            return null;
        }
        if ($this->sameModel((string)$this->db->getSetting('gpu_split_model', ''), $model)) {
            $row = $this->modelRow($model);
            $loaded = is_array($row) ? (int)($row['size'] ?? 0) : 0;
            $vramBytes = is_array($row) ? (int)($row['size_vram'] ?? 0) : 0;
            if ($loaded <= 0 || $vramBytes < (int)($loaded * 0.9)) {
                $this->ctxCache[$cacheKey] = 'split';
                $this->gpuSkip = 'split';
                return null;
            }
            $this->db->setSetting('gpu_split_model', '');
        }
        $ctx = min($wanted, $maxCtx);
        $this->ctxCache[$cacheKey] = $ctx;
        return $ctx;
    }

    public function keepSplit(string $model): void
    {
        $key = strtolower(trim($model));
        if ($key === '') {
            return;
        }
        $this->fitCache[$key] = false;
        foreach (array_keys($this->ctxCache) as $cacheKey) {
            if (str_starts_with((string)$cacheKey, $key . ':')) {
                $this->ctxCache[$cacheKey] = 'split';
            }
        }
        if ($this->usableModel($model)) {
            $this->db->setSetting('gpu_split_model', $model);
        }
    }

    public function gpuOverflow(string $text): bool
    {
        return (bool)preg_match('/cudaMalloc|unable to allocate CUDA|projector CPU offload/i', $text);
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
        $who = $agent !== '' ? $agent : 'orchestrator';
        for ($try = 1; $try <= 3; $try++) {
            if ($shouldStop && $shouldStop()) {
                throw new ChatStopped('Stopped.');
            }
            $this->attemptTry = $try;
            try {
                return $this->attempt($messages, $onToken, $shouldStop, $onThinking, $cloud, $agent, $this->usingFailsafe);
            } catch (ChatStopped $e) {
                throw $e;
            } catch (OutputRepeated $e) {
                throw $e;
            } catch (LlmException $e) {
                $last = $e;
                $this->db->loop($who, 'retry', 'try ' . $try . '/3 · ' . $e->getMessage());
                if ($this->isFullGpu($e->getMessage())) {
                    $this->keepSplit((string)$this->db->getSetting('local_model', app_config()['local_model']));
                }
                if ($try >= 3) {
                    break;
                }
                $again = $this->stoppedWhileThinking($e->getMessage());
                $note = $this->recover($e->getMessage());
                if ($note === null) {
                    $this->db->loop($who, 'retry', 'try ' . $try . '/3 · no recovery');
                    break;
                }
                if ($note !== '') {
                    $this->recoveries[] = $note;
                    $this->db->loop($who, 'retry', 'recovery · ' . $note);
                }
                if ($again) {
                    $this->db->loop($who, 'retry', 'pause before retry');
                    $this->pauseForRetry($shouldStop);
                }
            }
        }
        if ($last && $this->shouldStepDown($last->getMessage(), $cloud)) {
            $this->usingFailsafe = true;
            $this->db->setSetting('cpu_fallback', '1');
            $this->recoveries[] = 'The model connection was lost. Continuing with the CPU failsafe.';
            $this->db->loop($who, 'retry', 'CPU failsafe · ' . $last->getMessage());
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

    public function seeImage(string $question, string $bytes, ?callable $shouldStop = null): string
    {
        if ($question === '') {
            $question = 'Describe what is visible. Transcribe the text. Note the layout, and point out anything that looks broken, overlapping, cut off, or unfinished.';
        }
        $question = clip_text($question, 1000);
        if ($bytes === '' || strlen($bytes) > 4 * 1024 * 1024) {
            return 'That image is empty or larger than 4 MB, so it cannot be looked at.';
        }
        $mime = $this->imageMime($bytes);
        if ($mime === '') {
            return 'That file is not a png, jpg, gif, or webp.';
        }
        try {
            if ($shouldStop && $shouldStop()) {
                return 'Stopped.';
            }
        } catch (Throwable $e) {
            return 'Stopped.';
        }
        $failsafe = $this->usingFailsafe;
        $mode = $failsafe ? 'cpu_failsafe' : (string)$this->db->getSetting('model_mode', 'cpu_failsafe');
        if ($failsafe || $mode === 'cpu_failsafe') {
            return 'The CPU failsafe model cannot see images. read_image needs the GPU model, which can look at png, jpg, gif, and webp.';
        }
        $b64 = base64_encode($bytes);
        if ($mode === 'cloud') {
            return $this->seeImageCloud($question, $mime, $b64, $shouldStop);
        }
        return $this->seeImageLocal($question, $b64, $mode, $shouldStop);
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

    private function traceModel(string $agent, array $messages, string $model, int $numCtx, bool $brief, string $ask): void
    {
        $chars = 0;
        $last = '';
        foreach ($messages as $message) {
            if (!is_array($message)) {
                continue;
            }
            $content = (string)($message['content'] ?? '');
            $chars += strlen($content);
            if (($message['role'] ?? '') === 'user' && trim($content) !== '') {
                $last = $content;
            }
        }
        $last = preg_replace('/\s+/', ' ', trim($last)) ?? '';
        if (strlen($last) > 48) {
            $last = substr($last, 0, 47) . '…';
        }
        $line = 'ask ' . $ask . ' · ' . $model . ' · ' . $this->loadedSummary() . ' · ctx ' . $numCtx . ' · ' . count($messages) . ' msgs · ' . $chars . ' chars · brief ' . ($brief ? 'on' : 'off');
        if ($this->attemptTry > 1) {
            $line = 'try ' . $this->attemptTry . '/3 · ' . $line;
        }
        if ($last !== '') {
            $line .= ' · last: ' . $last;
        }
        $who = $agent !== '' ? $agent : 'orchestrator';
        $this->db->loop($who, 'model', $line);
        $this->noteMachine($who);
    }

    private function noteMachine(string $agent): void
    {
        try {
            $line = $this->hardware->machineLine();
        } catch (Throwable $e) {
            return;
        }
        if ($line === '') {
            return;
        }
        $this->db->loop($agent !== '' ? $agent : 'orchestrator', 'machine', $line);
    }

    private function attempt(array $messages, callable $onToken, ?callable $shouldStop, ?callable $onThinking = null, bool $cloud = false, string $agent = '', bool $failsafe = false): string
    {
        $this->incomplete = false;
        $this->ranOut = false;
        $this->doneReason = '';
        $brief = $this->brief;
        $this->brief = false;
        $mode = $failsafe ? 'cpu_failsafe' : (string)$this->db->getSetting('model_mode', 'cpu_failsafe');
        $numCtx = $this->hardware->effectiveContext($mode);
        if (!$brief && $this->db->getSetting('recovery_hint', '0') === '1') {
            $hint = $agent === 'orchestrator'
                ? 'The reply ran out of room while thinking. Keep this reply short. Do not restate the plan. Split the remaining work into smaller tasks and delegate the next one. One tool call, then stop.'
                : 'The reply ran out of room while thinking. Keep this reply short. Do one small step and call the tool. Then stop.';
            $messages[] = ['role' => 'user', 'content' => $hint];
            $this->db->setSetting('recovery_hint', '0');
            $brief = true;
        }
        if (!$failsafe && ($cloud || $mode === 'cloud')) {
            $cloudModel = (string)$this->db->getSetting('cloud_model', app_config()['cloud_model']);
            $this->traceModel($agent, $messages, $cloudModel, $numCtx, $brief, 'cloud');
            return $this->streamOpenAi($messages, $onToken, $shouldStop, $onThinking, $brief);
        }
        $model = $mode === 'cpu_failsafe'
            ? (string)$this->db->getSetting('cpu_failsafe_model', app_config()['cpu_failsafe_model'])
            : (string)$this->db->getSetting('local_model', app_config()['local_model']);
        if ($mode !== 'cpu_failsafe' && $this->placement($model) === 'cpu') {
            $this->markCpu(true);
        }
        $base = rtrim((string)$this->db->getSetting('ollama_url', app_config()['ollama_url']), '/');
        $predict = 65536;
        if ($brief) {
            $predict = 4096;
        } else {
            $predictOverride = (int)$this->db->getSetting('recovery_num_predict', '0');
            if ($predictOverride >= 256) {
                $predict = $predictOverride;
            }
        }
        $options = [
            'num_ctx' => $numCtx,
            'num_predict' => $predict,
            'use_mmap' => true,
            'use_mlock' => false,
        ];
        $ask = 'auto';
        if ($mode === 'cpu_failsafe') {
            $ask = 'cpu';
        } elseif ($mode === 'local') {
            $gpu = $this->gpuOptions($model, $numCtx);
            if ($gpu !== []) {
                $options['num_gpu'] = 999;
                $options['num_ctx'] = $gpu['num_ctx'];
                $numCtx = $gpu['num_ctx'];
                $ask = 'gpu';
            } elseif ($this->gpuSkip) {
                $ask = 'auto · ' . $this->gpuSkip;
            }
        }
        $body = [
            'model' => $model,
            'messages' => $messages,
            'stream' => true,
            'keep_alive' => '1h',
            'options' => $options,
        ];
        if ($brief && !$this->omitThink) {
            $body['think'] = false;
        }
        $payload = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        if (!is_string($payload) || $payload === '') {
            throw new LlmException('The model request could not be encoded.', false);
        }
        $this->traceModel($agent, $messages, $model, $numCtx, $brief, $ask);
        try {
            return $this->curlStream($base . '/api/chat', $payload, [], $onToken, true, $base, $model, $shouldStop, $onThinking);
        } catch (LlmException $e) {
            if (array_key_exists('think', $body) && $body['think'] === false && str_contains(strtolower($e->getMessage()), 'think')) {
                $this->omitThink = true;
                $this->brief = true;
                return $this->attempt($messages, $onToken, $shouldStop, $onThinking, $cloud, $agent, $failsafe);
            }
            throw $e;
        }
    }

    private function streamOpenAi(array $messages, callable $onToken, ?callable $shouldStop, ?callable $onThinking = null, bool $brief = false): string
    {
        $key = trim((string)$this->db->getSetting('cloud_api_key', ''));
        $base = rtrim((string)$this->db->getSetting('cloud_base_url', app_config()['cloud_base_url']), '/');
        $model = (string)$this->db->getSetting('cloud_model', app_config()['cloud_model']);
        if ($key === '') {
            throw new LlmException('Cloud model mode needs an API key in Setup.');
        }
        $request = [
            'model' => $model,
            'messages' => $messages,
            'stream' => true,
        ];
        if ($brief) {
            $request['max_tokens'] = 4096;
        }
        $payload = json_encode($request, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
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
                    if (isset($json['done_reason']) && is_string($json['done_reason']) && $json['done_reason'] !== '') {
                        $doneReason = $json['done_reason'];
                    }
                    $finish = $json['choices'][0]['finish_reason'] ?? null;
                    if (is_string($finish) && $finish !== '') {
                        $doneReason = $finish;
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
        $this->doneReason = $doneReason;
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
            $detail = $hint . ($err !== '' ? ' ' . $err : '');
            if ($this->diedWhileThinking($thinking, $full, $detail)) {
                throw new LlmException('Model ' . $model . ' failed: The reply stopped while thinking. ' . $detail, true);
            }
            throw new LlmException($detail, false);
        }
        if ($code >= 400 || $errorBody !== '') {
            if (!$ollama && $this->isRejectedKey($errorBody, $code)) {
                throw new LlmException('Wrong API key or endpoint.', false);
            }
            $detail = trim($errorBody) !== '' ? clip_text($errorBody, 500) : 'HTTP ' . $code;
            $retryable = $code >= 500 || $this->isOom($errorBody);
            if ($this->diedWhileThinking($thinking, $full, $detail)) {
                throw new LlmException('Model ' . $model . ' failed: The reply stopped while thinking. ' . $detail, true);
            }
            throw new LlmException('Model ' . $model . ' failed: ' . $detail, $retryable);
        }
        if (trim($full) === '') {
            if ($doneReason === 'length') {
                $this->incomplete = true;
                $this->ranOut = true;
                return '';
            }
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

    private function diedWhileThinking(string $thinking, string $full, string $detail): bool
    {
        if (trim($full) !== '') {
            return false;
        }
        return trim($thinking) !== '' || $this->stoppedWhileThinking($detail);
    }

    private function stoppedWhileThinking(string $error): bool
    {
        return (bool)preg_match('/stopped while thinking|error in input stream/i', $error);
    }

    private function pauseForRetry(?callable $shouldStop): void
    {
        $until = microtime(true) + 2;
        while (microtime(true) < $until) {
            if ($shouldStop && $shouldStop()) {
                throw new ChatStopped('Stopped.');
            }
            usleep(200000);
        }
    }

    private function isOom(string $text): bool
    {
        return (bool)preg_match('/out of memory|cuda|\\boom\\b|requires more system memory|model requires more/i', $text);
    }

    private function isFullGpu(string $text): bool
    {
        return $this->gpuOverflow($text);
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

    private function loadedSummary(): string
    {
        $json = $this->ollamaCall('GET', '/api/ps', null, 2);
        if ($json === null) {
            return 'loaded unknown';
        }
        $parts = [];
        foreach ($json['models'] ?? [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $name = trim((string)($row['name'] ?? $row['model'] ?? ''));
            if ($name === '') {
                continue;
            }
            $size = (int)($row['size'] ?? 0);
            $vram = (int)($row['size_vram'] ?? 0);
            $parts[] = $name . ' ' . $this->placeWord($vram, $size) . ' ' . $this->memoryPair($vram, $size);
        }
        return $parts ? 'loaded ' . implode(', ', $parts) : 'loaded none';
    }

    private function placeWord(int $vram, int $size): string
    {
        if ($vram <= 0) {
            return 'cpu';
        }
        if ($size > 0 && $vram < (int)($size * 0.9)) {
            return 'split';
        }
        return 'gpu';
    }

    private function memoryPair(int $vram, int $size): string
    {
        if ($size <= 0) {
            return $this->gb($vram);
        }
        return $this->gb($vram) . '/' . $this->gb($size);
    }

    private function gb(int $bytes): string
    {
        if ($bytes <= 0) {
            return '0GB';
        }
        return number_format($bytes / 1073741824, 1, '.', '') . 'GB';
    }

    private function loadOntoGpu(string $model): bool
    {
        if ($this->placement($model) === 'cpu') {
            $this->stopModel($model);
        }
        $options = ['num_predict' => 1];
        $gpu = $this->gpuOptions($model, $this->hardware->effectiveContext('local'));
        if ($gpu !== []) {
            $options = array_merge($options, $gpu);
        }
        $payload = json_encode([
            'model' => $model,
            'prompt' => 'hi',
            'stream' => false,
            'keep_alive' => '1h',
            'options' => $options,
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
        $payload = json_encode(['model' => $model, 'keep_alive' => 0]);
        if (is_string($payload)) {
            $this->ollamaCall('POST', '/api/generate', $payload, 30);
        }
        $this->ollamaStop($model);
    }

    private function gpuHolders(string $keep): ?array
    {
        $json = $this->ollamaCall('GET', '/api/ps', null, 4);
        if ($json === null) {
            return null;
        }
        $names = [];
        foreach ($json['models'] ?? [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $name = trim((string)($row['name'] ?? $row['model'] ?? ''));
            if (!$this->usableModel($name) || $this->sameModel($name, $keep)) {
                continue;
            }
            if ((int)($row['size_vram'] ?? 0) <= 0) {
                continue;
            }
            $names[] = $name;
        }
        return $names;
    }

    private function ollamaStop(string $model): void
    {
        $ollama = $this->ollamaBin();
        if ($ollama === '') {
            return;
        }
        Process::run([$ollama, 'stop', $model], null, null, 30);
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

    private function modelBytes(string $model): int
    {
        if (!$this->usableModel($model)) {
            return 0;
        }
        $payload = json_encode(['name' => $model]);
        if (!is_string($payload)) {
            return 0;
        }
        $json = $this->ollamaCall('POST', '/api/show', $payload, 8);
        if (!is_array($json)) {
            return 0;
        }
        return (int)($json['size'] ?? 0);
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
        $wanted = $this->hardware->effectiveContext($mode);
        $options = [
            'num_ctx' => $wanted,
            'num_predict' => 8,
            'use_mmap' => true,
            'use_mlock' => false,
        ];
        $ask = 'auto';
        if ($mode === 'cpu_failsafe') {
            $ask = 'cpu';
        } elseif ($mode === 'local') {
            $gpu = $this->gpuOptions($model, $wanted);
            if ($gpu !== []) {
                $options['num_gpu'] = 999;
                $options['num_ctx'] = $gpu['num_ctx'];
                $ask = 'gpu';
            } elseif ($this->gpuSkip) {
                $ask = 'auto · ' . $this->gpuSkip;
            }
        }
        $this->db->loop('orchestrator', 'model', 'yesno · ask ' . $ask . ' · ' . $model . ' · ' . $this->loadedSummary());
        $this->noteMachine('orchestrator');
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
        $this->db->loop('orchestrator', 'model', 'yesno · ask cloud · ' . $model . ' · ' . $this->loadedSummary());
        $this->noteMachine('orchestrator');
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

    private function seeImageLocal(string $question, string $b64, string $mode, ?callable $shouldStop): string
    {
        $model = (string)$this->db->getSetting('local_model', app_config()['local_model']);
        $base = rtrim((string)$this->db->getSetting('ollama_url', app_config()['ollama_url']), '/');
        $wanted = $this->hardware->effectiveContext($mode);
        $options = [
            'num_ctx' => $wanted,
            'num_predict' => 900,
            'use_mmap' => true,
            'use_mlock' => false,
        ];
        if ($mode === 'local') {
            $gpu = $this->gpuOptions($model, $wanted);
            if ($gpu !== []) {
                $options['num_gpu'] = 999;
                $options['num_ctx'] = $gpu['num_ctx'];
            }
        }
        $body = [
            'model' => $model,
            'messages' => [[
                'role' => 'user',
                'content' => $question,
                'images' => [$b64],
            ]],
            'stream' => false,
            'think' => false,
            'keep_alive' => '1h',
            'options' => $options,
        ];
        $response = $this->postImage($base . '/api/chat', $body, [], $shouldStop);
        if (is_array($response) && $response['code'] >= 400 && str_contains(strtolower($response['body']), 'think')) {
            unset($body['think']);
            $response = $this->postImage($base . '/api/chat', $body, [], $shouldStop);
        }
        return $this->imageAnswer($response, false);
    }

    private function seeImageCloud(string $question, string $mime, string $b64, ?callable $shouldStop): string
    {
        $key = trim((string)$this->db->getSetting('cloud_api_key', ''));
        $base = rtrim((string)$this->db->getSetting('cloud_base_url', app_config()['cloud_base_url']), '/');
        $model = (string)$this->db->getSetting('cloud_model', app_config()['cloud_model']);
        if ($key === '') {
            return 'Cloud model mode needs an API key in Setup.';
        }
        $response = $this->postImage($base . '/chat/completions', [
            'model' => $model,
            'messages' => [[
                'role' => 'user',
                'content' => [
                    ['type' => 'text', 'text' => $question],
                    ['type' => 'image_url', 'image_url' => ['url' => 'data:' . $mime . ';base64,' . $b64]],
                ],
            ]],
            'stream' => false,
            'max_tokens' => 900,
        ], ['Authorization: Bearer ' . $key], $shouldStop);
        return $this->imageAnswer($response, true);
    }

    private function postImage(string $url, array $body, array $headers, ?callable $shouldStop): ?array
    {
        $payload = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        if (!is_string($payload) || $payload === '') {
            return ['code' => 0, 'body' => ''];
        }
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
            CURLOPT_TIMEOUT => 120,
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
        if ($errno !== 0 || !is_string($raw)) {
            return null;
        }
        return ['code' => $code, 'body' => $raw];
    }

    private function imageAnswer(?array $response, bool $cloud): string
    {
        if ($response === null) {
            return 'The model did not answer after looking at the image.';
        }
        $json = json_decode($response['body'], true);
        if ($response['code'] >= 400 || $response['code'] === 0) {
            $error = '';
            if (is_array($json)) {
                $err = $json['error'] ?? '';
                if (is_array($err)) {
                    $error = trim((string)($err['message'] ?? ''));
                } else {
                    $error = trim((string)$err);
                }
            }
            if ($error === '') {
                $error = 'The model could not look at that image.';
            }
            if (!$cloud && preg_match('/image|vision|multimodal/i', $error)) {
                return 'This model cannot see images. read_image works with the GPU model when that model can see, such as qwen3.6. ' . $error;
            }
            return $error;
        }
        if (!is_array($json)) {
            return 'The model did not answer after looking at the image.';
        }
        if ($cloud) {
            $content = $this->visibleText((string)($json['choices'][0]['message']['content'] ?? ''));
        } else {
            $message = is_array($json['message'] ?? null) ? $json['message'] : [];
            $content = $this->visibleText((string)($message['content'] ?? ''));
        }
        if ($content === '') {
            return 'The model looked at the image but returned nothing.';
        }
        return clip_text($content, 4000);
    }

    private function imageMime(string $bytes): string
    {
        if (str_starts_with($bytes, "\x89PNG\r\n\x1a\n")) {
            return 'image/png';
        }
        if (str_starts_with($bytes, "\xFF\xD8\xFF")) {
            return 'image/jpeg';
        }
        if (str_starts_with($bytes, 'GIF87a') || str_starts_with($bytes, 'GIF89a')) {
            return 'image/gif';
        }
        if (strlen($bytes) >= 12 && str_starts_with($bytes, 'RIFF') && substr($bytes, 8, 4) === 'WEBP') {
            return 'image/webp';
        }
        return '';
    }

    private function visibleText(string $text): string
    {
        $text = preg_replace('/<(?:think|thinking|reasoning|thought)\b[^>]*>[\s\S]*?<\/(?:think|thinking|reasoning|thought)>/i', '', $text) ?? $text;
        $text = preg_replace('/<(?:think|thinking|reasoning|thought)\b[^>]*>[\s\S]*$/i', '', $text) ?? $text;
        return trim($text);
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
