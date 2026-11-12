<?php

class HardwareMonitor
{
    public function __construct(private Database $db)
    {
    }

    private ?array $shown = null;

    private bool $shownLoaded = false;

    public function snapshot(): array
    {
        $vram = $this->nvidiaSmi();
        $system = $this->systemMemory();
        $mode = (string)$this->db->getSetting('model_mode', 'cpu_failsafe');
        return [
            'gpu' => $vram,
            'system_memory' => $system,
            'ollama' => $this->ollamaShow(),
            'recommended_num_ctx' => $this->recommendContext($mode),
            'usage' => $this->resourceUsage($system),
        ];
    }

    public function resourceUsage(?array $system = null): array
    {
        $system = $system ?? $this->systemMemory();
        $total = (int)($system['total_mb'] ?? 0);
        $available = (int)($system['available_mb'] ?? 0);
        $ram = null;
        if ($total > 0) {
            $ram = round(max(0, min(100, (($total - $available) / $total) * 100)), 1);
        }
        $vram = $this->vramUsage();
        $disks = $this->diskUsages();
        $disk = $disks[0] ?? null;
        return [
            'cpu' => $this->cpuPercent(),
            'ram' => $ram,
            'vram' => $vram === null ? null : round(max(0, min(100, ($vram['used_mb'] / $vram['total_mb']) * 100)), 1),
            'disk' => ($disk === null || (int)$disk['total_mb'] <= 0) ? null : round(max(0, min(100, ($disk['used_mb'] / $disk['total_mb']) * 100)), 1),
            'details' => [
                'ram' => $total > 0 ? ['used_mb' => max(0, $total - $available), 'total_mb' => $total] : null,
                'vram' => $vram,
                'disk' => $disk,
                'disks' => $disks,
            ],
        ];
    }

    public function machineLine(): string
    {
        $parts = [];
        $memory = $this->systemMemory();
        $total = (int)($memory['total_mb'] ?? 0);
        $available = (int)($memory['available_mb'] ?? 0);
        if ($total > 0) {
            $parts[] = 'RAM ' . $this->gbFromMb(max(0, $total - $available)) . '/' . $this->gbFromMb($total);
        }
        $vram = $this->vramUsage();
        if (is_array($vram) && (int)($vram['total_mb'] ?? 0) > 0) {
            $parts[] = 'VRAM ' . $this->gbFromMb((int)$vram['used_mb']) . '/' . $this->gbFromMb((int)$vram['total_mb']);
        }
        $busy = $this->busyProcesses();
        if ($busy !== '') {
            $parts[] = $busy;
        }
        return implode(' · ', $parts);
    }

    public function gpuMemory(): ?array
    {
        return $this->vramUsage();
    }

    public function recommendContext(string $mode): int
    {
        if ($mode === 'cloud') {
            return $this->cloudContext();
        }
        if ($mode === 'cpu_failsafe') {
            return 8192;
        }
        $reported = $this->modelContextLength();
        if ($reported >= 512) {
            return min(32768, $reported);
        }
        return $this->ramContext();
    }

    public function effectiveContext(string $mode): int
    {
        $total = $this->recommendContext($mode);
        $override = (int)$this->db->getSetting('recovery_num_ctx', '0');
        if ($override >= 1024) {
            $total = min($total, $override);
        }
        return max(512, $total);
    }

    private function cloudContext(): int
    {
        $model = strtolower(trim((string)$this->db->getSetting('cloud_model', app_config()['cloud_model'])));
        $slash = strrpos($model, '/');
        if ($slash !== false) {
            $model = substr($model, $slash + 1);
        }
        if (preg_match('/(?:^|[^a-z0-9])(\d+(?:\.\d+)?)m(?:[^a-z]|$)/', $model, $match)) {
            return max(512, (int)round((float)$match[1] * 1048576));
        }
        if (preg_match('/(?:^|[^a-z0-9])(\d+)k(?:[^a-z]|$)/', $model, $match)) {
            return max(512, ((int)$match[1]) * 1024);
        }
        $windows = [
            'gpt-4.1' => 1047576,
            'gpt-4o' => 128000,
            'chatgpt-4o' => 128000,
            'gpt-4-turbo' => 128000,
            'gpt-4' => 8192,
            'gpt-3.5' => 16385,
            'o1-mini' => 128000,
            'o1-preview' => 128000,
            'o1' => 200000,
            'o3' => 200000,
            'o4' => 200000,
            'claude' => 200000,
            'gemini' => 1048576,
            'deepseek' => 65536,
        ];
        $best = 0;
        $length = 0;
        foreach ($windows as $name => $window) {
            if ($model !== '' && str_starts_with($model, $name) && strlen($name) > $best) {
                $best = strlen($name);
                $length = $window;
            }
        }
        if ($length >= 512) {
            return $length;
        }
        return 128000;
    }

    private function ramContext(): int
    {
        $system = $this->systemMemory();
        $ram = (int)($system['available_mb'] ?? 0);
        if ($ram <= 0) {
            $ram = (int)($system['total_mb'] ?? 0);
        }
        // Free VRAM looks empty once qwen3.6 is loaded. The extra context is the
        // KV cache, and Ollama keeps that in system RAM when it does not fit on the GPU.
        // About 0.15 GB of RAM per 1024 tokens, with room left for the system.
        if ($ram >= 6144) {
            return 32768;
        }
        if ($ram >= 3072) {
            return 16384;
        }
        return 8192;
    }

    private function modelContextLength(): int
    {
        $shown = $this->ollamaShow();
        return (int)($shown['context_length'] ?? 0);
    }

    private function nvidiaSmi(): ?array
    {
        if (!Process::commandExists('nvidia-smi')) {
            return null;
        }
        $result = Process::run(['nvidia-smi', '--query-gpu=memory.total,memory.free', '--format=csv,noheader,nounits'], null, null, 8);
        if ($result['code'] !== 0) {
            return null;
        }
        $line = trim(strtok($result['stdout'], "\n") ?: '');
        if (!preg_match('/(\d+)\s*,\s*(\d+)/', $line, $m)) {
            return null;
        }
        return ['total_mb' => (int)$m[1], 'free_mb' => (int)$m[2], 'source' => 'nvidia-smi'];
    }

    private function ollamaShow(): ?array
    {
        if ($this->shownLoaded) {
            return $this->shown;
        }
        $this->shownLoaded = true;
        $base = rtrim((string)$this->db->getSetting('ollama_url', app_config()['ollama_url']), '/');
        $model = (string)$this->db->getSetting('local_model', app_config()['local_model']);
        $ch = curl_init($base . '/api/show');
        if ($ch === false) {
            return null;
        }
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode(['name' => $model]),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 4,
            CURLOPT_CONNECTTIMEOUT => 2,
        ]);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        unset($ch);
        if (!is_string($body) || $code >= 400) {
            $this->shown = null;
            return null;
        }
        $json = json_decode($body, true);
        if (!is_array($json)) {
            $this->shown = null;
            return null;
        }
        $this->shown = [
            'model' => $model,
            'size_bytes' => isset($json['size']) ? (int)$json['size'] : null,
            'parameter_size' => $json['details']['parameter_size'] ?? null,
            'family' => $json['details']['family'] ?? null,
            'context_length' => $this->contextLength($json),
        ];
        return $this->shown;
    }

    private function contextLength(array $json): int
    {
        $length = 0;
        $info = $json['model_info'] ?? null;
        if (is_array($info)) {
            foreach ($info as $key => $value) {
                if (is_string($key) && str_ends_with($key, '.context_length') && is_numeric($value)) {
                    $length = max($length, (int)$value);
                }
            }
        }
        if ($length <= 0 && is_string($json['parameters'] ?? null) && preg_match('/(?:^|\n)\s*num_ctx\s+(\d+)/', $json['parameters'], $match)) {
            $length = (int)$match[1];
        }
        return $length;
    }

    private function systemMemory(): array
    {
        if (is_readable('/proc/meminfo')) {
            $text = (string)file_get_contents('/proc/meminfo');
            preg_match('/MemTotal:\s+(\d+)/', $text, $total);
            preg_match('/MemAvailable:\s+(\d+)/', $text, $available);
            return [
                'total_mb' => (int)round(((int)($total[1] ?? 0)) / 1024),
                'available_mb' => (int)round(((int)($available[1] ?? 0)) / 1024),
                'source' => 'proc',
            ];
        }
        $total = Process::run(['sysctl', '-n', 'hw.memsize'], null, null, 5);
        $totalMb = 0;
        if ($total['code'] === 0) {
            $totalMb = (int)round(((int)trim($total['stdout'])) / 1048576);
        }
        $availableMb = $this->macAvailableMb();
        if ($availableMb === null) {
            $availableMb = $totalMb;
        }
        return ['total_mb' => $totalMb, 'available_mb' => $availableMb, 'source' => PHP_OS_FAMILY === 'Darwin' ? 'sysctl' : 'unknown'];
    }

    private function macAvailableMb(): ?int
    {
        if (PHP_OS_FAMILY !== 'Darwin') {
            return null;
        }
        $result = Process::run(['vm_stat'], null, null, 5);
        if ($result['code'] !== 0) {
            return null;
        }
        $page = 4096;
        if (preg_match('/page size of (\d+) bytes/', $result['stdout'], $m)) {
            $page = (int)$m[1];
        }
        $pages = 0;
        foreach (['Pages free', 'Pages inactive', 'Pages speculative'] as $label) {
            if (preg_match('/' . preg_quote($label, '/') . ':\s+(\d+)/', $result['stdout'], $m)) {
                $pages += (int)$m[1];
            }
        }
        if ($pages === 0) {
            return null;
        }
        return (int)round(($pages * $page) / 1048576);
    }

    private function cpuPercent(): ?float
    {
        if (PHP_OS_FAMILY === 'Darwin') {
            $result = Process::run(['top', '-l', '1', '-n', '0', '-s', '0'], null, null, 4);
            if ($result['code'] === 0 && preg_match('/([\d.]+)%\s+idle/', $result['stdout'], $match)) {
                return round(max(0, min(100, 100 - (float)$match[1])), 1);
            }
            return null;
        }
        if (!is_readable('/proc/stat')) {
            return null;
        }
        $read = static function (): ?array {
            $line = (string)strtok((string)file_get_contents('/proc/stat'), "\n");
            if (!preg_match('/^cpu\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)/', $line, $match)) {
                return null;
            }
            $total = 0;
            for ($i = 1; $i <= 7; $i++) {
                $total += (int)$match[$i];
            }
            return ['idle' => (int)$match[4] + (int)$match[5], 'total' => $total];
        };
        $first = $read();
        usleep(200000);
        $second = $read();
        if ($first === null || $second === null || $second['total'] <= $first['total']) {
            return null;
        }
        $idle = $second['idle'] - $first['idle'];
        $total = $second['total'] - $first['total'];
        return round(max(0, min(100, (1 - ($idle / $total)) * 100)), 1);
    }

    private function vramPercent(int $totalMb): ?float
    {
        $nvidia = $this->nvidiaVramPercent();
        if ($nvidia !== null) {
            return $nvidia;
        }
        $drm = $this->drmVramPercent();
        if ($drm !== null) {
            return $drm;
        }
        $rocm = $this->rocmVramPercent();
        if ($rocm !== null) {
            return $rocm;
        }
        if (PHP_OS_FAMILY === 'Darwin') {
            return $this->macVramPercent($totalMb);
        }
        return null;
    }

    private function nvidiaVramPercent(): ?float
    {
        if ($this->toolPath('nvidia-smi') === null) {
            return null;
        }
        $result = Process::run(['nvidia-smi', '--query-gpu=memory.used,memory.total', '--format=csv,noheader,nounits'], null, null, 5);
        if ($result['code'] !== 0) {
            return null;
        }
        $used = 0.0;
        $total = 0.0;
        foreach (preg_split("/\r\n|\n|\r/", trim($result['stdout'])) ?: [] as $line) {
            if (!preg_match('/^\s*([0-9.]+)\s*,\s*([0-9.]+)/', (string)$line, $match)) {
                continue;
            }
            $gpuTotal = (float)$match[2];
            if ($gpuTotal <= 0) {
                continue;
            }
            $used += (float)$match[1];
            $total += $gpuTotal;
        }
        if ($total <= 0) {
            return null;
        }
        return round(max(0, min(100, ($used / $total) * 100)), 1);
    }

    private function drmVramPercent(): ?float
    {
        $cards = glob('/sys/class/drm/card[0-9]*', GLOB_ONLYDIR) ?: [];
        $used = 0.0;
        $total = 0.0;
        $seen = [];
        foreach ($cards as $card) {
            if (!preg_match('#/card\d+$#', $card)) {
                continue;
            }
            $device = $card . '/device';
            $key = realpath($device) ?: $device;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $pair = $this->drmMemPair($device, 'vram') ?? $this->drmMemPair($device, 'gtt');
            if ($pair === null) {
                continue;
            }
            $used += $pair[0];
            $total += $pair[1];
        }
        if ($total <= 0) {
            return null;
        }
        return round(max(0, min(100, ($used / $total) * 100)), 1);
    }

    private function drmMemPair(string $device, string $kind): ?array
    {
        $usedFile = $device . '/mem_info_' . $kind . '_used';
        $totalFile = $device . '/mem_info_' . $kind . '_total';
        if (!is_readable($usedFile) || !is_readable($totalFile)) {
            return null;
        }
        $total = (float)trim((string)file_get_contents($totalFile));
        if ($total <= 0) {
            return null;
        }
        return [(float)trim((string)file_get_contents($usedFile)), $total];
    }

    private function rocmVramPercent(): ?float
    {
        if ($this->toolPath('rocm-smi') === null) {
            return null;
        }
        $result = Process::run(['rocm-smi', '--showmeminfo', 'vram'], null, null, 5);
        if ($result['code'] !== 0) {
            return null;
        }
        if (!preg_match_all('/VRAM Total Used Memory \(B\):\s*(\d+)/', $result['stdout'], $used)
            || !preg_match_all('/VRAM Total Memory \(B\):\s*(\d+)/', $result['stdout'], $total)) {
            return null;
        }
        $usedBytes = 0.0;
        $totalBytes = 0.0;
        foreach ($used[1] as $bytes) {
            $usedBytes += (float)$bytes;
        }
        foreach ($total[1] as $bytes) {
            $totalBytes += (float)$bytes;
        }
        if ($totalBytes <= 0) {
            return null;
        }
        return round(max(0, min(100, ($usedBytes / $totalBytes) * 100)), 1);
    }

    private function toolPath(string $name): ?string
    {
        if (!preg_match('/^[A-Za-z0-9._+-]+$/', $name)) {
            return null;
        }
        $path = getenv('PATH');
        if (!is_string($path) || $path === '') {
            $path = '/usr/bin:/usr/local/bin:/opt/homebrew/bin';
        }
        foreach (explode(PATH_SEPARATOR, $path) as $dir) {
            $file = rtrim($dir, '/') . '/' . $name;
            if ($file !== '/' . $name && is_executable($file)) {
                return $file;
            }
        }
        return null;
    }

    private function macVramPercent(int $totalMb): ?float
    {
        $result = Process::run(['ioreg', '-r', '-d', '1', '-w', '0', '-c', 'IOAccelerator'], null, null, 4);
        if ($result['code'] !== 0) {
            return null;
        }
        if (preg_match_all('/"vramUsedBytes"\s*=\s*(\d+)/', $result['stdout'], $used)
            && preg_match_all('/"vramFreeBytes"\s*=\s*(\d+)/', $result['stdout'], $free)
            && count($used[1]) === count($free[1]) && count($used[1]) > 0) {
            $usedBytes = 0;
            $totalBytes = 0;
            foreach ($used[1] as $i => $bytes) {
                $usedBytes += (int)$bytes;
                $totalBytes += (int)$bytes + (int)$free[1][$i];
            }
            if ($totalBytes > 0) {
                return round(max(0, min(100, ($usedBytes / $totalBytes) * 100)), 1);
            }
        }
        // Apple GPUs share system memory. This counter is the part the GPU is using.
        if ($totalMb <= 0 || !preg_match('/"In use system memory"\s*=\s*(\d+)/', $result['stdout'], $used)) {
            return null;
        }
        $usedMb = ((int)$used[1]) / 1048576;
        return round(max(0, min(100, ($usedMb / $totalMb) * 100)), 1);
    }

    private function diskPercent(): ?float
    {
        $result = Process::run(['df', '-P', dirname(__DIR__)], null, null, 5);
        if ($result['code'] !== 0) {
            return null;
        }
        $lines = preg_split("/\r\n|\n|\r/", trim($result['stdout'])) ?: [];
        $line = (string)end($lines);
        if (!preg_match('/\s(\d+)\s+(\d+)\s+\d+\s+\d+%\s+\S+$/', $line, $match)) {
            return null;
        }
        $blocks = (int)$match[1];
        if ($blocks <= 0) {
            return null;
        }
        return round(max(0, min(100, ((int)$match[2] / $blocks) * 100)), 1);
    }

    private function vramUsage(): ?array
    {
        $nvidia = $this->nvidiaSmi();
        if ($nvidia !== null && (int)$nvidia['total_mb'] > 0) {
            return [
                'used_mb' => max(0, (int)$nvidia['total_mb'] - (int)$nvidia['free_mb']),
                'total_mb' => (int)$nvidia['total_mb'],
            ];
        }
        if (PHP_OS_FAMILY !== 'Darwin') {
            return null;
        }
        $result = Process::run(['ioreg', '-r', '-d', '1', '-w', '0', '-c', 'IOAccelerator'], null, null, 4);
        if ($result['code'] !== 0) {
            return null;
        }
        if (preg_match_all('/"vramUsedBytes"\s*=\s*(\d+)/', $result['stdout'], $used)
            && preg_match_all('/"vramFreeBytes"\s*=\s*(\d+)/', $result['stdout'], $free)
            && count($used[1]) === count($free[1]) && count($used[1]) > 0) {
            $usedBytes = 0;
            $totalBytes = 0;
            foreach ($used[1] as $i => $bytes) {
                $usedBytes += (int)$bytes;
                $totalBytes += (int)$bytes + (int)$free[1][$i];
            }
            if ($totalBytes > 0) {
                return [
                    'used_mb' => (int)round($usedBytes / 1048576),
                    'total_mb' => (int)round($totalBytes / 1048576),
                ];
            }
        }
        if (preg_match('/"In use system memory"\s*=\s*(\d+)/', $result['stdout'], $used)) {
            $system = $this->systemMemory();
            $totalMb = (int)($system['total_mb'] ?? 0);
            if ($totalMb > 0) {
                return ['used_mb' => (int)round(((int)$used[1]) / 1048576), 'total_mb' => $totalMb];
            }
        }
        return null;
    }

    private function diskUsages(): array
    {
        $result = Process::run(['df', '-P'], null, null, 5);
        if ($result['code'] !== 0) {
            return [];
        }
        return $this->drivesFromDf($result['stdout'], $this->filesystemOf(dirname(__DIR__)));
    }

    private function filesystemOf(string $path): ?string
    {
        $result = Process::run(['df', '-P', $path], null, null, 5);
        if ($result['code'] !== 0) {
            return null;
        }
        $lines = preg_split("/\r\n|\n|\r/", trim($result['stdout'])) ?: [];
        $line = trim((string)end($lines));
        if (!preg_match('/^(\S+)\s+\d+\s+\d+\s+\d+\s+\d+%\s+/', $line, $match)) {
            return null;
        }
        return $match[1];
    }

    private function drivesFromDf(string $stdout, ?string $homeFilesystem): array
    {
        $rows = [];
        foreach (preg_split("/\r\n|\n|\r/", trim($stdout)) ?: [] as $line) {
            $line = trim((string)$line);
            if (!preg_match('/^(\S+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)%\s+(.*)$/', $line, $match)) {
                continue;
            }
            $filesystem = $match[1];
            $mount = rtrim($match[6]);
            $totalBlocks = (int)$match[2];
            if ($totalBlocks <= 0 || !$this->isDiskFilesystem($filesystem, $mount)) {
                continue;
            }
            $rows[] = [
                'fs' => $filesystem,
                'mount' => $mount,
                'total_blocks' => $totalBlocks,
                'used_blocks' => (int)$match[3],
                'group' => $this->diskGroup($filesystem),
            ];
        }
        $homeGroup = $homeFilesystem !== null ? $this->diskGroup($homeFilesystem) : '';
        $groups = [];
        foreach ($rows as $row) {
            $groups[$row['group']][] = $row;
        }
        $drives = [];
        foreach ($groups as $group => $members) {
            $pick = $this->pickDriveVolume($members);
            if ($pick === null) {
                continue;
            }
            $pick['home'] = $group === $homeGroup;
            $drives[] = $pick;
        }
        usort($drives, function (array $a, array $b): int {
            if ($a['home'] !== $b['home']) {
                return $a['home'] ? -1 : 1;
            }
            if ($a['total_blocks'] !== $b['total_blocks']) {
                return $b['total_blocks'] <=> $a['total_blocks'];
            }
            return strcmp($a['mount'], $b['mount']);
        });
        $out = [];
        foreach (array_slice($drives, 0, 3) as $row) {
            $out[] = [
                'used_mb' => (int)round(($row['used_blocks'] * 512) / 1048576),
                'total_mb' => (int)round(($row['total_blocks'] * 512) / 1048576),
            ];
        }
        return $out;
    }

    private function isDiskFilesystem(string $filesystem, string $mount): bool
    {
        if ($mount === '' || $mount === '/dev' || str_starts_with($mount, '/dev/')) {
            return false;
        }
        if (str_starts_with($mount, '/System/Volumes/') && $mount !== '/System/Volumes/Data') {
            return false;
        }
        if (!str_starts_with($filesystem, '/dev/')) {
            return false;
        }
        return !preg_match('#^/dev/(loop|ram|fd|sr)\d*#', $filesystem);
    }

    private function diskGroup(string $filesystem): string
    {
        if (preg_match('#^/dev/disk(\d+)#', $filesystem, $match)) {
            return 'disk' . $match[1];
        }
        if (preg_match('#^/dev/(nvme\d+n\d+)#', $filesystem, $match)) {
            return $match[1];
        }
        if (preg_match('#^/dev/(mmcblk\d+)#', $filesystem, $match)) {
            return $match[1];
        }
        if (preg_match('#^/dev/((?:sd|hd|vd|xvd)[a-z]+)#', $filesystem, $match)) {
            return $match[1];
        }
        return $filesystem;
    }

    private function pickDriveVolume(array $members): ?array
    {
        $minBlocks = (int)((2 * 1024 * 1024 * 1024) / 512);
        $kept = [];
        foreach ($members as $row) {
            if ($row['total_blocks'] >= $minBlocks) {
                $kept[] = $row;
            }
        }
        if ($kept === []) {
            return null;
        }
        foreach ($kept as $row) {
            if ($row['mount'] === '/System/Volumes/Data') {
                return $row;
            }
        }
        usort($kept, function (array $a, array $b): int {
            return $b['used_blocks'] <=> $a['used_blocks'];
        });
        return $kept[0];
    }

    private function gbFromMb(int $mb): string
    {
        if ($mb >= 1024) {
            return number_format($mb / 1024, 1, '.', '') . 'GB';
        }
        return $mb . 'MB';
    }

    private function busyProcesses(): string
    {
        $command = PHP_OS_FAMILY === 'Darwin'
            ? ['ps', '-axr', '-o', 'pcpu=,rss=,comm=']
            : ['ps', '-axo', 'pcpu=,rss=,comm=', '--sort=-pcpu'];
        $result = Process::run($command, null, null, 3);
        if ($result['code'] !== 0) {
            return '';
        }
        $shown = [];
        foreach (preg_split("/\r\n|\n|\r/", trim($result['stdout'])) ?: [] as $row) {
            $row = trim((string)$row);
            if (!preg_match('/^([\d.]+)\s+(\d+)\s+(\S.*)$/', $row, $match)) {
                continue;
            }
            $cpu = (float)$match[1];
            $name = trim($match[3]);
            $name = basename(str_replace('\\', '/', $name));
            if (mb_strlen($name) > 22) {
                $name = rtrim(mb_substr($name, 0, 21)) . '…';
            }
            if ($cpu < 1 || $name === '' || $name === 'ps') {
                continue;
            }
            $shown[] = $name . ' ' . round($cpu) . '% ' . $this->kbLabel((int)$match[2]);
            if (count($shown) >= 3) {
                break;
            }
        }
        return implode(', ', $shown);
    }

    private function kbLabel(int $kb): string
    {
        if ($kb >= 1048576) {
            return number_format($kb / 1048576, 1, '.', '') . 'GB';
        }
        if ($kb >= 1024) {
            return (string)max(1, (int)round($kb / 1024)) . 'MB';
        }
        return $kb . 'KB';
    }
}
