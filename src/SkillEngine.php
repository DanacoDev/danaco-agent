<?php

class SkillEngine
{
    public function __construct(private Database $db, private string $dataDir)
    {
    }

    public function sync(): array
    {
        $dir = $this->dataDir . '/skills';
        $seen = [];
        foreach (scandir($dir) ?: [] as $name) {
            if (!preg_match('/\.(php|py)$/', $name)) {
                continue;
            }
            $path = $dir . '/' . $name;
            if (!is_file($path)) {
                continue;
            }
            $header = $this->parseHeader((string)file_get_contents($path));
            $skillName = $header['name'] !== '' ? $header['name'] : pathinfo($name, PATHINFO_FILENAME);
            $description = $header['description'] !== '' ? $header['description'] : 'No description.';
            $existing = $this->db->skillByName($skillName);
            $createdBy = $existing ? (string)$existing['created_by'] : 'system';
            $this->db->saveSkill($skillName, $description, $name, $existing ? (bool)$existing['is_active'] : true, $createdBy);
            $seen[$skillName] = true;
        }
        foreach ($this->db->skills() as $skill) {
            if (!isset($seen[$skill['name']])) {
                $this->db->deleteSkill($skill['id']);
            }
        }
        return $this->withSource($this->db->skills());
    }

    public function summariesForPrompt(): string
    {
        $this->sync();
        $lines = [];
        foreach ($this->db->skills() as $skill) {
            if (!(int)$skill['is_active']) {
                continue;
            }
            $lines[] = '- ' . $skill['name'] . ': ' . $this->oneLine($skill['description']);
        }
        return $lines ? implode("\n", $lines) : '- none';
    }

    public function activeHandlers(string $projectFolder): array
    {
        $handlers = [];
        foreach ($this->db->skills() as $skill) {
            if (!(int)$skill['is_active']) {
                continue;
            }
            $handlers[$skill['name']] = function (array $args) use ($skill, $projectFolder): string {
                $result = $this->run($skill['name'], $args, $projectFolder);
                return "exit {$result['exit_code']}\n{$result['stdout']}" . ($result['stderr'] !== '' ? "\n{$result['stderr']}" : '');
            };
        }
        return $handlers;
    }

    public function run(string $name, array $args, string $projectFolder, bool $force = false): array
    {
        $skill = $this->db->skillByName($name);
        if (!$skill || (!$force && !(int)$skill['is_active'])) {
            throw new RuntimeException('Skill is not active.');
        }
        $file = $this->scriptFile($skill['script_path']);
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        $bin = $ext === 'py' ? 'python3' : (PHP_BINARY ?: 'php');
        $cwd = Projects::root($projectFolder);
        if (!is_dir($cwd)) {
            mkdir($cwd, 0775, true);
        }
        $env = $this->envWithKeys();
        $result = Process::run([$bin, $file], $cwd, json_encode($args, JSON_UNESCAPED_UNICODE), 20, $env);
        return [
            'exit_code' => $result['code'],
            'stdout' => clip_text($result['stdout']),
            'stderr' => clip_text($result['stderr']),
        ];
    }

    public function saveSource(string $filename, string $source, string $name, string $description, bool $active, string $createdBy = 'user'): array
    {
        if (!preg_match('/^[a-z0-9][a-z0-9_-]{0,40}\.(php|py)$/', $filename)) {
            throw new InvalidArgumentException('Skill filename must be a lowercase .php or .py file.');
        }
        if (!preg_match('/^[a-z][a-z0-9_-]{0,40}$/', $name)) {
            throw new InvalidArgumentException('Skill name must be lowercase letters, numbers, dashes, or underscores.');
        }
        if (strlen($source) > 200000) {
            throw new InvalidArgumentException('Skill source is too large.');
        }
        $description = trim($description) !== '' ? trim($description) : 'No description.';
        $this->assertEditable($name, $filename);
        if (!preg_match('/---\s*\n.*?name:.*?description:.*?\n(?:#\s*)?---/s', $source)) {
            $header = "---\nname: {$name}\ndescription: {$description}\n---\n";
            if (str_ends_with($filename, '.php')) {
                $source = "<?php\n/*\n{$header}*/\n" . ltrim(preg_replace('/^<\?php\s*/', '', $source) ?? $source);
            } else {
                $comment = implode("\n", array_map(static fn(string $line): string => '# ' . $line, explode("\n", trim($header))));
                $source = $comment . "\n" . $source;
            }
        }
        $path = $this->dataDir . '/skills/' . $filename;
        $hadFile = is_file($path);
        $previous = $hadFile ? file_get_contents($path) : null;
        if ($hadFile && $previous === false) {
            throw new RuntimeException('Could not read the existing skill before replacing it.');
        }
        if (file_put_contents($path, $source) === false) {
            throw new RuntimeException('Could not save the skill.');
        }
        try {
            $this->assertParses($path, $filename, $source);
        } catch (RuntimeException $e) {
            $this->restoreSkillFile($path, $hadFile ? $previous : null);
            throw $e;
        }
        return $this->db->saveSkill($name, $this->oneLine($description), $filename, $active, $createdBy);
    }

    public function deleteOwned(int $id): void
    {
        $skill = $this->db->skill($id);
        if (!$skill) {
            throw new RuntimeException('Skill not found.');
        }
        if ((string)($skill['created_by'] ?? '') === 'system') {
            throw new RuntimeException('Built-in skills can only be turned on or off.');
        }
        try {
            $file = $this->scriptFile((string)$skill['script_path']);
            if (is_file($file)) {
                unlink($file);
            }
        } catch (Throwable $e) {
        }
        $this->db->deleteSkill($id);
    }

    public function readSource(array $skill): string
    {
        $file = $this->scriptFile($skill['script_path']);
        return (string)file_get_contents($file);
    }

    public function parseHeader(string $source): array
    {
        $name = '';
        $description = '';
        if (preg_match('/(?:#\s*)?---\s*\n(.*?)\n(?:#\s*)?---/s', $source, $m)) {
            $block = preg_replace('/^#\s?/m', '', $m[1]) ?? $m[1];
            if (preg_match('/^name:\s*(.+)$/m', $block, $mm)) {
                $name = trim($mm[1]);
            }
            if (preg_match('/^description:\s*(.+)$/m', $block, $mm)) {
                $description = trim($mm[1]);
            }
        }
        return ['name' => $name, 'description' => $description];
    }

    private function withSource(array $skills): array
    {
        foreach ($skills as &$skill) {
            try {
                $skill['source'] = $this->readSource($skill);
            } catch (Throwable $e) {
                $skill['source'] = '';
            }
        }
        return $skills;
    }

    private function assertParses(string $path, string $filename, string $source): void
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $hint = '';
        if ($ext === 'php' && str_ends_with(rtrim($source), '?')) {
            $hint = ' The file ends with a stray ?. A PHP skill has no closing tag.';
        }
        if ($ext === 'php') {
            $bin = PHP_BINARY !== '' ? PHP_BINARY : 'php';
            $result = Process::run([$bin, '-l', $path], null, null, 15);
        } elseif ($ext === 'py') {
            if (!Process::commandExists('python3')) {
                throw new RuntimeException('Skill was not saved. python3 is not available to check a .py skill.');
            }
            $dir = sys_get_temp_dir() . '/skill-check-' . bin2hex(random_bytes(4));
            if (!mkdir($dir, 0700, true) && !is_dir($dir)) {
                throw new RuntimeException('Could not check the skill.');
            }
            $copy = $dir . '/' . $filename;
            if (file_put_contents($copy, $source) === false) {
                $this->removeTree($dir);
                throw new RuntimeException('Could not check the skill.');
            }
            try {
                $result = Process::run(['python3', '-m', 'py_compile', $copy], $dir, null, 15);
            } finally {
                $this->removeTree($dir);
            }
            $output = trim($result['stdout'] . "\n" . $result['stderr']);
            if ($result['code'] !== 0) {
                throw new RuntimeException($this->syntaxFailure($filename, $output, ''));
            }
            return;
        } else {
            return;
        }
        $stdout = trim($result['stdout']);
        $stderr = trim($result['stderr']);
        $stdout = str_replace($path, $filename, $stdout);
        $stderr = str_replace($path, $filename, $stderr);
        if ($result['code'] === 0 && str_contains($stdout . "\n" . $stderr, 'No syntax errors detected')) {
            return;
        }
        $output = $stderr !== '' ? $stderr : $stdout;
        throw new RuntimeException($this->syntaxFailure($filename, $output, $hint));
    }

    private function syntaxFailure(string $filename, string $output, string $hint): string
    {
        $output = trim(preg_replace('/\s+/', ' ', $output) ?? $output);
        if (strlen($output) > 400) {
            $output = substr($output, 0, 397) . '...';
        }
        if ($output === '') {
            $output = 'The file does not parse.';
        }
        return 'Skill was not saved. Syntax check failed for ' . $filename . ': ' . $output . $hint . ' Send the complete source again in one write_skill call.';
    }

    private function restoreSkillFile(string $path, ?string $previous): void
    {
        if ($previous === null) {
            if (is_file($path)) {
                unlink($path);
            }
            return;
        }
        file_put_contents($path, $previous);
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $path = $dir . '/' . $name;
            if (is_dir($path)) {
                $this->removeTree($path);
            } elseif (is_file($path)) {
                unlink($path);
            }
        }
        rmdir($dir);
    }

    private function assertEditable(string $name, string $filename): void
    {
        $existing = $this->db->skillByName($name);
        if ($existing && (string)($existing['created_by'] ?? '') === 'system') {
            throw new RuntimeException('Built-in skills can only be turned on or off.');
        }
        foreach ($this->db->skills() as $skill) {
            if ((string)($skill['created_by'] ?? '') === 'system' && basename((string)$skill['script_path']) === $filename) {
                throw new RuntimeException('Built-in skills can only be turned on or off.');
            }
        }
    }

    private function scriptFile(string $scriptPath): string
    {
        $base = basename(str_replace('\\', '/', $scriptPath));
        $file = $this->dataDir . '/skills/' . $base;
        $realDir = realpath($this->dataDir . '/skills');
        $real = realpath($file);
        if ($realDir === false || $real === false || !str_starts_with($real, $realDir . DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('Skill path is not allowed.');
        }
        return $real;
    }

    private function oneLine(string $description): string
    {
        $line = trim(preg_replace('/\s+/', ' ', $description) ?? $description);
        if (strlen($line) > 160) {
            return substr($line, 0, 157) . '...';
        }
        return $line;
    }

    private function envWithKeys(): array
    {
        $env = [];
        foreach (array_merge($_ENV, $_SERVER) as $key => $value) {
            if (is_string($key) && is_string($value) && preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key)) {
                $env[$key] = $value;
            }
        }
        $cloud = (string)$this->db->getSetting('cloud_api_key', '');
        if ($cloud !== '') {
            $env['CLOUD_API_KEY'] = $cloud;
        }
        $extraName = (string)$this->db->getSetting('extra_api_key_name', '');
        $extraValue = (string)$this->db->getSetting('extra_api_key_value', '');
        if ($extraName !== '' && preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $extraName) && $extraValue !== '') {
            $env[$extraName] = $extraValue;
        }
        if (!isset($env['PATH'])) {
            $env['PATH'] = getenv('PATH') ?: '/usr/bin:/bin';
        }
        return $env;
    }
}
