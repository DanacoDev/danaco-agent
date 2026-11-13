<?php

class SkillEngine
{
    public const BUILTIN = [
        'filesystem', 'read_image', 'read_pdf', 'screenshot', 'create_pdf',
        'wiki_memory', 'list_vault', 'read_vault', 'restore_file', 'kanban', 'delegate',
        'web_search', 'fetch_url', 'browse_url', 'run_command', 'run_python', 'send_email',
        'ftp', 'mcp', 'remember', 'write_skill', 'test_skill',
    ];

    public const SYSTEM = [
        'filesystem', 'read_image', 'read_pdf', 'screenshot', 'create_pdf',
        'wiki_memory', 'list_vault', 'read_vault', 'restore_file', 'kanban', 'delegate',
        'web_search', 'fetch_url', 'browse_url', 'run_command', 'run_python', 'send_email',
        'ftp', 'mcp', 'remember', 'write_skill', 'test_skill', 'sqlite_helper', 'save_note',
        'code_verifier', 'research_pipeline', 'task_breakdown', 'download_large',
        'write_article', 'humanizer', 'make_graphic',
    ];

    private array $hidden = [];

    private ?array $synced = null;

    private bool $syncing = false;

    public function __construct(private Database $db, private string $dataDir)
    {
    }

    public function sync(): array
    {
        if ($this->synced !== null) {
            return $this->synced;
        }
        if ($this->syncing) {
            return $this->db->skills();
        }
        $this->syncing = true;
        try {
            $seen = [];
            $hidden = [];
            foreach ($this->discover() as $found) {
                $name = $found['name'];
                $existing = $this->db->skillByName($name);
                $createdBy = $existing
                    ? (string)$existing['created_by']
                    : (in_array($name, self::SYSTEM, true) ? 'system' : 'user');
                $active = $existing ? (bool)$existing['is_active'] : true;
                $this->db->saveSkill($name, $found['description'], $found['dir'], $active, $createdBy);
                $seen[$name] = true;
                if (!$found['compatible']) {
                    $hidden[$name] = true;
                }
            }
            foreach ($this->db->skills() as $skill) {
                if (!isset($seen[$skill['name']])) {
                    $this->db->deleteSkill($skill['id']);
                }
            }
            $this->repairCustomPrograms();
            $this->hidden = $hidden;
            $this->synced = $this->withSource($this->db->skills());
            return $this->synced;
        } finally {
            $this->syncing = false;
        }
    }

    public function offered(string $name): bool
    {
        $this->sync();
        $name = $this->storedName($name);
        if ($name === 'search') {
            $name = 'web_search';
        } elseif ($name === 'create_task' || $name === 'list_tasks') {
            $name = 'kanban';
        } elseif (in_array($name, ['wiki_search', 'wiki_read', 'wiki_write', 'wiki_reindex'], true)) {
            $name = 'wiki_memory';
        } elseif (in_array($name, ['list_files', 'read_file', 'write_file', 'delete_file', 'move_file'], true)) {
            $name = 'filesystem';
        }
        $skill = $this->db->skillByName($name);
        if (!$skill) {
            return true;
        }
        if (!(int)$skill['is_active'] || isset($this->hidden[$name])) {
            return false;
        }
        return true;
    }

    public function promptCatalog(array $omit = [], array $brief = []): string
    {
        $this->sync();
        $blocks = [];
        foreach ($this->db->skills() as $skill) {
            $name = (string)$skill['name'];
            if (!(int)$skill['is_active'] || isset($this->hidden[$name]) || in_array($name, $omit, true)) {
                continue;
            }
            if (isset($brief[$name])) {
                $blocks[] = $brief[$name];
                continue;
            }
            if ((string)$skill['created_by'] === 'system') {
                $body = trim($this->body($skill));
                $blocks[] = '- ' . $name . ': ' . $this->oneLine((string)$skill['description']) . ($body !== '' ? "\n" . $body : '');
            } else {
                $blocks[] = '- ' . $name . ': ' . $this->oneLine((string)$skill['description']);
            }
        }
        return $blocks ? implode("\n\n", $blocks) : '- none';
    }

    public function summariesForPrompt(): string
    {
        return $this->promptCatalog();
    }

    public function activeHandlers(string $projectFolder): array
    {
        $this->sync();
        $handlers = [];
        foreach ($this->db->skills() as $skill) {
            $name = (string)$skill['name'];
            if (!(int)$skill['is_active'] || isset($this->hidden[$name]) || in_array($name, self::BUILTIN, true)) {
                continue;
            }
            $handlers[$name] = function (array $args) use ($skill, $projectFolder): string {
                $result = $this->run($skill['name'], $args, $projectFolder);
                return "exit {$result['exit_code']}\n{$result['stdout']}" . ($result['stderr'] !== '' ? "\n{$result['stderr']}" : '');
            };
        }
        return $handlers;
    }

    public function run(string $name, array $args, string $projectFolder, bool $force = false): array
    {
        $this->sync();
        $name = $this->storedName($name);
        $skill = $this->db->skillByName($name);
        if (!$skill || (!$force && !(int)$skill['is_active'])) {
            throw new RuntimeException('Skill is not active.');
        }
        if (isset($this->hidden[$name])) {
            throw new RuntimeException('Skill is not available on this system.');
        }
        $dir = $this->absoluteDir((string)$skill['script_path']);
        $runner = $this->runnerFile($dir);
        if ($runner === null && isset($args['read']) && is_string($args['read']) && trim($args['read']) !== '') {
            try {
                $text = $this->readInside($dir, trim($args['read']));
            } catch (RuntimeException $e) {
                return ['exit_code' => 1, 'stdout' => '', 'stderr' => $e->getMessage()];
            }
            return ['exit_code' => 0, 'stdout' => $text, 'stderr' => ''];
        }
        if ($runner === null) {
            $body = $this->markdownBody($dir);
            $payload = $args;
            unset($payload['read']);
            if ($payload !== []) {
                $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
                $body = rtrim($body) . "\n\nArguments:\n" . $encoded . "\n";
            }
            return ['exit_code' => 0, 'stdout' => $body, 'stderr' => ''];
        }
        $ext = strtolower(pathinfo($runner, PATHINFO_EXTENSION));
        $bin = $ext === 'py' ? 'python3' : (PHP_BINARY ?: 'php');
        $cwd = Projects::root($projectFolder);
        if (!is_dir($cwd)) {
            mkdir($cwd, 0775, true);
        }
        $result = Process::run([$bin, $runner], $cwd, json_encode($args, JSON_UNESCAPED_UNICODE), 20, $this->envWithKeys());
        return [
            'exit_code' => $result['code'],
            'stdout' => clip_text($result['stdout']),
            'stderr' => clip_text($result['stderr']),
        ];
    }

    public function testCall(array $call, string $projectFolder): string
    {
        $name = strtolower(trim((string)($call['name'] ?? $call['skill'] ?? '')));
        $name = $this->storedName($name);
        if (!$this->validLoadedName($name)) {
            return 'Name the skill to test. name is the skill in the Skills library.';
        }
        $skill = $this->db->skillByName($name);
        if (!$skill) {
            $this->sync();
            $skill = $this->db->skillByName($name);
        }
        if (!$skill) {
            return 'No skill named ' . $name . '. Register it with write_skill, then test it again.';
        }
        if ($projectFolder === '') {
            return 'Open a project before testing a skill. The skill runs in that project folder.';
        }
        try {
            $result = $this->run($name, $this->testInput($call), $projectFolder, true);
        } catch (Throwable $e) {
            return 'Skill ' . $name . ' did not run. ' . $e->getMessage();
        }
        $dir = $this->absoluteDir((string)$skill['script_path']);
        if ($this->runnerFile($dir) === null) {
            $stdout = $result['stdout'] !== '' ? $result['stdout'] : '(empty)';
            return 'Tested ' . $name . ".\n" . $stdout;
        }
        $stdout = $result['stdout'] !== '' ? $result['stdout'] : '(empty)';
        $text = 'Tested ' . $name . ".\nexit " . $result['exit_code'] . "\nstdout:\n" . $stdout;
        if ($result['stderr'] !== '') {
            $text .= "\nstderr:\n" . $result['stderr'];
        }
        if (!(int)$skill['is_active']) {
            $text .= "\nThis skill is turned off, so a later call by name will not run it until it is enabled.";
        }
        return $text;
    }

    public function register(array $args, string $createdBy): string
    {
        $name = $this->storedName(trim((string)($args['name'] ?? '')));
        $overwrite = in_array(strtolower(trim((string)($args['overwrite'] ?? ''))), ['1', 'true', 'yes'], true);
        $script = array_key_exists('script', $args) ? (string)$args['script'] : null;
        $lang = strtolower(trim((string)($args['lang'] ?? '')));
        $description = (string)($args['description'] ?? '');
        $source = (string)($args['source'] ?? '');
        $before = $name !== '' ? $this->db->skillByName($name) : null;
        $custom = $before && (string)($before['created_by'] ?? '') !== 'system' && !in_array($name, self::SYSTEM, true);
        if ($custom) {
            $overwrite = true;
            if (trim($description) === '') {
                $description = (string)$before['description'];
            }
            if (trim($source) === '') {
                try {
                    $source = $this->readSource($before);
                } catch (Throwable $e) {
                    $source = '';
                }
            }
            if ($script === null || trim($script) === '') {
                $script = null;
            }
        }
        $embedded = $this->findProgram($this->sourceBody($source)) !== null;
        $sentScript = $script !== null && trim($script) !== '';
        if ($sentScript && $lang === '') {
            $lang = $this->programLang($script);
        }
        $skill = $this->saveSource(
            $name,
            $description,
            $source,
            true,
            $createdBy,
            $overwrite,
            $script,
            $lang
        );
        $verb = $before ? 'Updated' : 'Registered';
        $text = $verb . ' skill ' . $skill['name'] . ' in the Skills library. It is listed on the Skills page and can be called by name, including from a scheduled task.';
        $runner = $this->runnerFile($this->absoluteDir((string)$skill['script_path']));
        if ($runner !== null) {
            $text .= ' Syntax check passed.';
            if ($embedded && !$sentScript) {
                $text .= ' The program was in source, so it was saved as the script. SKILL.md keeps only the instructions.';
            } elseif ($embedded) {
                $text .= ' The program in the instructions was removed. The script you sent is what runs.';
            }
        }
        $text .= ' Call test_skill with name ' . $skill['name'] . ' and input set to the JSON that skill reads.';
        if (!$before) {
            $text .= ' This run cannot call the skill by its own name yet.';
        }
        return $text;
    }

    public function saveSource(string $name, string $description, string $source, bool $active = true, string $createdBy = 'user', bool $overwrite = false, ?string $script = null, string $lang = ''): array
    {
        $name = $this->storedName(trim($name));
        if (!preg_match('/^[a-z0-9]+(?:[_-][a-z0-9]+)*$/', $name) || strlen($name) > 64) {
            throw new InvalidArgumentException('Skill name must be lowercase letters, numbers, underscores, or hyphens.');
        }
        $description = trim($description);
        if ($description === '') {
            throw new InvalidArgumentException('Skill description is required.');
        }
        if (strlen($description) > 1024) {
            throw new InvalidArgumentException('Skill description must be 1024 characters or fewer.');
        }
        if (strlen($source) > 200000) {
            throw new InvalidArgumentException('Skill source is too large.');
        }
        [$source, $script, $lang] = $this->liftProgram($source, $script, $lang);
        if ($script !== null && strlen($script) > 200000) {
            throw new InvalidArgumentException('Skill script is too large.');
        }
        $lang = strtolower(trim($lang));
        if ($script !== null && trim($script) !== '') {
            if ($lang === '') {
                $lang = 'php';
            }
            if (!in_array($lang, ['php', 'py'], true)) {
                throw new InvalidArgumentException('Skill script lang must be php or py.');
            }
        }
        $this->assertEditable($name);
        $existing = $this->db->skillByName($name);
        if ($existing && !$overwrite) {
            throw new RuntimeException($this->existsMessage($existing));
        }
        $markdown = $this->composeMarkdown($name, $description, $source, $existing);
        $relative = $name;
        $dir = $this->absoluteDir($relative);
        $previousRel = $existing ? (string)$existing['script_path'] : '';
        if ($existing && $previousRel !== '' && $previousRel !== $relative) {
            $old = $this->absoluteDir($previousRel);
            if (is_dir($old) && !is_dir($dir)) {
                $this->copyTree($old, $dir);
            }
        }
        $existed = is_dir($dir);
        $snap = $this->snapshotDir($existed ? $dir : null);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Could not save the skill.');
        }
        try {
            if (file_put_contents($dir . '/SKILL.md', $markdown) === false) {
                throw new RuntimeException('Could not save the skill.');
            }
            if ($script !== null) {
                $this->writeRunner($dir, $script, $lang);
            }
            $saved = $this->db->saveSkill($name, $description, $relative, $active, $existing ? (string)$existing['created_by'] : $createdBy);
        } catch (Throwable $e) {
            $this->restoreSnapshot($dir, $snap, $existed);
            if ($previousRel !== '' && $previousRel !== $relative && is_dir($dir)) {
                $this->removeSkillDir($relative);
            }
            throw $e;
        }
        if ($snap !== null && is_dir($snap)) {
            $this->removeTree($snap);
        }
        if ($previousRel !== '' && $previousRel !== $relative) {
            $this->removeSkillDir($previousRel);
        }
        $this->synced = null;
        return $saved;
    }

    public function exportFiles(array $skill): array
    {
        $dir = $this->absoluteDir((string)$skill['script_path']);
        if (!is_dir($dir)) {
            throw new RuntimeException('Could not read custom skill ' . (string)($skill['name'] ?? '') . '.');
        }
        $files = [];
        $this->collectText($dir, '', $files);
        if ($files === []) {
            throw new RuntimeException('Could not read custom skill ' . (string)($skill['name'] ?? '') . '.');
        }
        return $files;
    }

    public function restoreTree(string $name, string $description, array $files, bool $active): array
    {
        $name = trim($name);
        if (!$this->validLoadedName($name) || in_array($name, self::SYSTEM, true)) {
            throw new InvalidArgumentException('The custom skills in that backup could not be read.');
        }
        if (count($files) > 40) {
            throw new InvalidArgumentException('The custom skills in that backup could not be read.');
        }
        $written = [];
        foreach ($files as $file) {
            if (!is_array($file)) {
                throw new InvalidArgumentException('The custom skills in that backup could not be read.');
            }
            $path = str_replace('\\', '/', trim((string)($file['path'] ?? '')));
            $content = (string)($file['content'] ?? '');
            if (!$this->allowedRelative($path) || strlen($content) > 200000 || str_contains($content, "\0")) {
                throw new InvalidArgumentException('The custom skills in that backup could not be read.');
            }
            $written[$path] = $content;
        }
        if (!isset($written['SKILL.md'])) {
            throw new InvalidArgumentException('The custom skills in that backup could not be read.');
        }
        $description = trim($description);
        if ($description === '' || strlen($description) > 1024) {
            throw new InvalidArgumentException('The custom skills in that backup could not be read.');
        }
        $this->assertEditable($name);
        $dir = $this->absoluteDir($name);
        $this->removeSkillDir($name);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Could not restore skill ' . $name . '.');
        }
        foreach ($written as $path => $content) {
            $full = $dir . '/' . $path;
            $parent = dirname($full);
            if (!is_dir($parent) && !mkdir($parent, 0775, true) && !is_dir($parent)) {
                throw new RuntimeException('Could not restore skill ' . $name . '.');
            }
            if (file_put_contents($full, $content) === false) {
                throw new RuntimeException('Could not restore skill ' . $name . '.');
            }
        }
        $saved = $this->db->saveSkill($name, $description, $name, $active, 'user');
        $this->synced = null;
        return $saved;
    }

    public function deleteOwned(int $id): void
    {
        $skill = $this->db->skill($id);
        if (!$skill) {
            throw new RuntimeException('Skill not found.');
        }
        if ((string)($skill['created_by'] ?? '') === 'system' || in_array((string)$skill['name'], self::SYSTEM, true)) {
            throw new RuntimeException('Built-in skills can only be turned on or off.');
        }
        try {
            $this->removeSkillDir((string)$skill['script_path']);
        } catch (Throwable $e) {
        }
        $this->db->deleteSkill($id);
        $this->synced = null;
    }

    public function readSource(array $skill): string
    {
        $dir = $this->absoluteDir((string)$skill['script_path']);
        $file = $dir . '/SKILL.md';
        if (!is_file($file)) {
            throw new RuntimeException('Skill file is missing.');
        }
        return (string)file_get_contents($file);
    }

    public function parseHeader(string $source): array
    {
        $split = $this->splitFrontmatter($source);
        if ($split === null) {
            return ['name' => '', 'description' => ''];
        }
        $name = trim((string)($split['map']['name'] ?? ''));
        $description = trim((string)($split['map']['description'] ?? ''));
        return ['name' => $name, 'description' => $description];
    }

    private function discover(): array
    {
        $root = $this->dataDir . '/skills';
        if (!is_dir($root)) {
            return [];
        }
        $found = [];
        foreach (scandir($root) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..' || str_starts_with($entry, '.')) {
                continue;
            }
            $path = $root . '/' . $entry;
            if (!is_dir($path) || is_link($path)) {
                continue;
            }
            if (is_file($path . '/SKILL.md')) {
                $this->consider($found, $entry, $entry);
                continue;
            }
            foreach (scandir($path) ?: [] as $child) {
                if ($child === '.' || $child === '..' || str_starts_with($child, '.')) {
                    continue;
                }
                $childPath = $path . '/' . $child;
                if (!is_dir($childPath) || is_link($childPath) || !is_file($childPath . '/SKILL.md')) {
                    continue;
                }
                $this->consider($found, $entry . '/' . $child, $child);
            }
        }
        return array_values($found);
    }

    private function consider(array &$found, string $relative, string $leaf): void
    {
        if (isset($found[$leaf]) || !$this->validLoadedName($leaf)) {
            return;
        }
        $file = $this->dataDir . '/skills/' . $relative . '/SKILL.md';
        $raw = file_get_contents($file);
        if ($raw === false) {
            return;
        }
        $split = $this->splitFrontmatter($raw);
        if ($split === null) {
            return;
        }
        $name = trim((string)($split['map']['name'] ?? ''));
        if ($name !== $leaf || !$this->validLoadedName($name)) {
            return;
        }
        $description = trim((string)($split['map']['description'] ?? ''));
        if ($description === '') {
            $description = 'No description.';
        }
        if (strlen($description) > 1024) {
            $description = substr($description, 0, 1024);
        }
        $found[$name] = [
            'name' => $name,
            'description' => $description,
            'dir' => $relative,
            'compatible' => $this->allowsOs($split['map']),
        ];
    }

    private function validLoadedName(string $name): bool
    {
        if (strlen($name) < 1 || strlen($name) > 64 || str_contains($name, '--')) {
            return false;
        }
        return (bool)preg_match('/^[a-z0-9](?:[a-z0-9._-]*[a-z0-9])?$/', $name);
    }

    private function storedName(string $name): string
    {
        $name = strtolower(trim($name));
        $this->sync();
        if ($name === '' || $this->db->skillByName($name)) {
            return $name;
        }
        $needle = str_replace('-', '_', $name);
        foreach ($this->db->skills() as $skill) {
            $known = (string)$skill['name'];
            if (str_replace('-', '_', $known) === $needle) {
                return $known;
            }
        }
        return $name;
    }

    private function allowsOs(array $map): bool
    {
        $need = $this->stringList($map['platforms'] ?? null);
        $meta = $map['metadata'] ?? null;
        if (is_array($meta)) {
            $openclaw = $meta['openclaw'] ?? null;
            if (is_array($openclaw)) {
                $need = array_merge($need, $this->stringList($openclaw['os'] ?? null));
            }
        }
        $need = array_values(array_unique($need));
        if ($need === []) {
            return true;
        }
        $now = match (PHP_OS_FAMILY) {
            'Darwin' => 'macos',
            'Windows' => 'windows',
            'Linux' => 'linux',
            default => strtolower(PHP_OS_FAMILY),
        };
        foreach ($need as $item) {
            if ($item === $now || ($now === 'macos' && in_array($item, ['darwin', 'mac'], true))) {
                return true;
            }
        }
        return false;
    }

    private function stringList(mixed $value): array
    {
        if (is_string($value) && $value !== '') {
            return [strtolower($value)];
        }
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $item) {
            if (is_string($item) && trim($item) !== '') {
                $out[] = strtolower(trim($item));
            }
        }
        return $out;
    }

    private function body(array $skill): string
    {
        try {
            return $this->markdownBody($this->absoluteDir((string)$skill['script_path']));
        } catch (Throwable $e) {
            return '';
        }
    }

    private function markdownBody(string $dir): string
    {
        $file = $dir . '/SKILL.md';
        if (!is_file($file)) {
            return '';
        }
        $split = $this->splitFrontmatter((string)file_get_contents($file));
        if ($split === null) {
            return trim((string)file_get_contents($file));
        }
        return trim($split['body']);
    }

    private function liftProgram(string $source, ?string $script, string $lang): array
    {
        $split = $this->splitFrontmatter($source);
        $body = $split === null ? $source : $split['body'];
        $found = $this->findProgram($body);
        if ($found === null) {
            return [$source, $script, $lang];
        }
        $code = $found['code'];
        $codeLang = $found['lang'];
        $guard = 0;
        while ($found !== null && $guard < 5) {
            $body = $found['prose'];
            $found = $this->findProgram($body);
            $guard++;
        }
        $prose = trim($body);
        if ($split === null) {
            $source = $prose === '' ? '' : $prose . "\n";
        } else {
            $source = $this->emitFrontmatter($split['map']) . ($prose === '' ? '' : $prose . "\n");
        }
        if ($script !== null && trim($script) !== '') {
            return [$source, $script, $lang];
        }
        return [$source, $code, $codeLang];
    }

    private function findProgram(string $body): ?array
    {
        $body = preg_replace("/\r\n?/", "\n", $body) ?? $body;
        if (preg_match_all('/```(?:python3?|py|php)?[^\n]*\n(.*?)```/s', $body, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            foreach ($matches as $match) {
                $code = $this->dedent($this->trimEdges($match[1][0]));
                if (!$this->programBody($code)) {
                    continue;
                }
                $whole = $match[0][0];
                $at = $match[0][1];
                $prose = trim(substr($body, 0, $at) . substr($body, $at + strlen($whole)));
                return ['code' => rtrim($code) . "\n", 'lang' => $this->programLang($code), 'prose' => $prose];
            }
        }
        $lines = explode("\n", $body);
        foreach ($lines as $i => $line) {
            if (!$this->programStart($line)) {
                continue;
            }
            $code = $this->dedent($this->trimEdges(implode("\n", array_slice($lines, $i))));
            if (!$this->programBody($code)) {
                continue;
            }
            return [
                'code' => rtrim($code) . "\n",
                'lang' => $this->programLang($code),
                'prose' => trim(implode("\n", array_slice($lines, 0, $i))),
            ];
        }
        return null;
    }

    private function programStart(string $line): bool
    {
        $line = ltrim($line);
        return str_starts_with($line, '#!/')
            || str_starts_with($line, '<?php')
            || preg_match('/^#\s*-\*- coding:/', $line) === 1
            || preg_match('/^(?:import |from |def |class |async def |function |namespace )/', $line) === 1;
    }

    private function programBody(string $code): bool
    {
        $code = $this->dedent($code);
        $codeLines = 0;
        foreach (explode("\n", $code) as $line) {
            $line = ltrim($line);
            if ($line === '' || str_starts_with($line, '#') || str_starts_with($line, '//') || str_starts_with($line, '/*') || str_starts_with($line, '*')) {
                continue;
            }
            if (preg_match('/^(?:import |from |def |class |async def |if |for |while |return |print\(|<\?php|function |namespace |use |echo |\$[A-Za-z_])/', $line)) {
                $codeLines++;
            }
        }
        if ($codeLines < 4) {
            return false;
        }
        return preg_match('/^<\?php\b/m', $code) === 1
            || preg_match('/^#!/m', ltrim($code)) === 1
            || (preg_match('/^(?:import |from )/m', $code) === 1 && preg_match('/^(?:def |class |async def )/m', $code) === 1)
            || (preg_match('/^<\?php\b/m', $code) !== 1 && preg_match('/^function /m', $code) === 1);
    }

    private function programLang(string $code): string
    {
        $code = $this->dedent($code);
        if (preg_match('/^<\?php\b/m', $code) === 1 || preg_match('/^#!.*\bphp\b/m', $code) === 1 || preg_match('/^function /m', $code) === 1) {
            return 'php';
        }
        return 'py';
    }

    private function trimEdges(string $code): string
    {
        $code = preg_replace("/\r\n?/", "\n", $code) ?? $code;
        $lines = explode("\n", $code);
        while ($lines !== [] && trim($lines[0]) === '') {
            array_shift($lines);
        }
        while ($lines !== [] && trim($lines[array_key_last($lines)]) === '') {
            array_pop($lines);
        }
        return implode("\n", $lines);
    }

    private function dedent(string $code): string
    {
        $code = preg_replace("/\r\n?/", "\n", $code) ?? $code;
        $lines = explode("\n", $code);
        $prefix = null;
        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }
            if (preg_match('/^[ \t]*/', $line, $match) !== 1) {
                return $code;
            }
            $lead = $match[0];
            if ($prefix === null) {
                $prefix = $lead;
                continue;
            }
            $limit = min(strlen($prefix), strlen($lead));
            $shared = '';
            for ($i = 0; $i < $limit; $i++) {
                if ($prefix[$i] !== $lead[$i]) {
                    break;
                }
                $shared .= $prefix[$i];
            }
            $prefix = $shared;
            if ($prefix === '') {
                return $code;
            }
        }
        if ($prefix === null || $prefix === '') {
            return $code;
        }
        $width = strlen($prefix);
        $out = [];
        foreach ($lines as $line) {
            if (trim($line) === '') {
                $out[] = '';
                continue;
            }
            $out[] = substr($line, $width);
        }
        return implode("\n", $out);
    }

    private function sourceBody(string $source): string
    {
        $split = $this->splitFrontmatter($source);
        return $split === null ? $source : $split['body'];
    }

    private function repairCustomPrograms(): void
    {
        foreach ($this->db->skills() as $skill) {
            $name = (string)$skill['name'];
            if ((string)($skill['created_by'] ?? '') === 'system' || in_array($name, self::SYSTEM, true)) {
                continue;
            }
            try {
                $source = $this->readSource($skill);
                if ($this->findProgram($this->sourceBody($source)) === null) {
                    continue;
                }
                $dir = $this->absoluteDir((string)$skill['script_path']);
                $script = null;
                $lang = '';
                $runner = $this->runnerFile($dir);
                if ($runner !== null) {
                    $loaded = file_get_contents($runner);
                    if ($loaded !== false) {
                        $script = $loaded;
                        $lang = str_ends_with($runner, '.py') ? 'py' : 'php';
                    }
                }
                $this->saveSource(
                    $name,
                    (string)$skill['description'],
                    $source,
                    (bool)$skill['is_active'],
                    (string)($skill['created_by'] ?? 'user'),
                    true,
                    $script,
                    $lang
                );
            } catch (Throwable $e) {
            }
        }
    }

    private function composeMarkdown(string $name, string $description, string $source, ?array $existing): string
    {
        $previous = [];
        if ($existing) {
            try {
                $raw = $this->readSource($existing);
                $split = $this->splitFrontmatter($raw);
                if ($split !== null) {
                    $previous = $split['map'];
                }
            } catch (Throwable $e) {
                $previous = [];
            }
        }
        $split = $this->splitFrontmatter($source);
        if ($split === null) {
            $map = $previous;
            $body = $source;
        } else {
            $map = $split['map'];
            $body = $split['body'];
        }
        if (trim($body) === '') {
            $body = $description . "\n";
        }
        $map['name'] = $name;
        $map['description'] = $description;
        if (!isset($map['version']) || !is_string($map['version']) || $map['version'] === '') {
            $map['version'] = '0.1.0';
        }
        return $this->emitFrontmatter($map) . ltrim($body, "\n");
    }

    private function splitFrontmatter(string $text): ?array
    {
        $text = preg_replace("/\r\n?/", "\n", $text) ?? $text;
        if (!preg_match('/^---\n(.*?)\n---\n?(.*)$/s', $text, $match)) {
            return null;
        }
        $map = $this->parseYamlBlock($match[1]);
        if (!is_array($map)) {
            return null;
        }
        return ['map' => $map, 'body' => $match[2]];
    }

    private function parseYamlBlock(string $yaml): array
    {
        $lines = explode("\n", $yaml);
        $parsed = $this->parseYamlLines($lines, 0, 0);
        return is_array($parsed['value']) ? $parsed['value'] : [];
    }

    private function parseYamlLines(array $lines, int $index, int $minIndent): array
    {
        $map = [];
        $list = [];
        $mode = '';
        $count = count($lines);
        while ($index < $count) {
            $raw = $lines[$index];
            if (trim($raw) === '' || str_starts_with(ltrim($raw), '#')) {
                $index++;
                continue;
            }
            $indent = strlen($raw) - strlen(ltrim($raw));
            if ($indent < $minIndent) {
                break;
            }
            $line = trim($raw);
            if (str_starts_with($line, '- ')) {
                if ($mode === 'map') {
                    break;
                }
                $mode = 'list';
                $item = trim(substr($line, 2));
                if ($item === '') {
                    $nested = $this->parseYamlLines($lines, $index + 1, $indent + 2);
                    $list[] = $nested['value'];
                    $index = $nested['next'];
                    continue;
                }
                $list[] = $this->yamlScalar($item);
                $index++;
                continue;
            }
            if (!str_contains($line, ':')) {
                break;
            }
            if ($mode === 'list') {
                break;
            }
            $mode = 'map';
            [$key, $rest] = explode(':', $line, 2);
            $key = trim($key);
            $rest = trim($rest);
            if ($key === '') {
                break;
            }
            if ($rest === '|' || $rest === '>') {
                $block = [];
                $index++;
                $blockIndent = null;
                while ($index < $count) {
                    $next = $lines[$index];
                    if (trim($next) === '') {
                        $block[] = '';
                        $index++;
                        continue;
                    }
                    $nextIndent = strlen($next) - strlen(ltrim($next));
                    if ($nextIndent <= $indent) {
                        break;
                    }
                    if ($blockIndent === null) {
                        $blockIndent = $nextIndent;
                    }
                    $block[] = substr($next, $blockIndent);
                    $index++;
                }
                $map[$key] = rtrim(implode("\n", $block));
                continue;
            }
            if ($rest === '') {
                $nested = $this->parseYamlLines($lines, $index + 1, $indent + 1);
                $map[$key] = $nested['value'];
                $index = $nested['next'];
                continue;
            }
            $map[$key] = $this->yamlValue($rest);
            $index++;
        }
        if ($mode === 'list') {
            return ['value' => $list, 'next' => $index];
        }
        return ['value' => $mode === '' ? [] : $map, 'next' => $index];
    }

    private function yamlValue(string $rest): mixed
    {
        if (str_starts_with($rest, '[') && str_ends_with($rest, ']')) {
            $inner = trim(substr($rest, 1, -1));
            if ($inner === '') {
                return [];
            }
            $parts = str_getcsv($inner, ',', '"', '\\');
            $out = [];
            foreach ($parts as $part) {
                $out[] = $this->yamlScalar(trim((string)$part));
            }
            return $out;
        }
        return $this->yamlScalar($rest);
    }

    private function yamlScalar(string $value): string
    {
        $value = trim($value);
        if (strlen($value) >= 2 && (($value[0] === '"' && str_ends_with($value, '"')) || ($value[0] === "'" && str_ends_with($value, "'")))) {
            $inner = substr($value, 1, -1);
            if ($value[0] === '"') {
                return stripcslashes($inner);
            }
            return str_replace("''", "'", $inner);
        }
        return $value;
    }

    private function emitFrontmatter(array $map): string
    {
        $order = ['name', 'description', 'version', 'license', 'compatibility', 'platforms', 'metadata'];
        $lines = ['---'];
        $done = [];
        foreach ($order as $key) {
            if (!array_key_exists($key, $map)) {
                continue;
            }
            $lines = array_merge($lines, $this->emitYamlPair($key, $map[$key], 0));
            $done[$key] = true;
        }
        foreach ($map as $key => $value) {
            if (!is_string($key) || isset($done[$key])) {
                continue;
            }
            $lines = array_merge($lines, $this->emitYamlPair($key, $value, 0));
        }
        $lines[] = '---';
        return implode("\n", $lines) . "\n";
    }

    private function emitYamlPair(string $key, mixed $value, int $indent): array
    {
        $pad = str_repeat(' ', $indent);
        if (is_array($value) && array_is_list($value)) {
            if ($value === []) {
                return [$pad . $key . ': []'];
            }
            $scalar = true;
            foreach ($value as $item) {
                if (!is_string($item) && !is_int($item) && !is_float($item)) {
                    $scalar = false;
                    break;
                }
            }
            if ($scalar) {
                $parts = array_map(fn(mixed $item): string => $this->emitScalar((string)$item), $value);
                return [$pad . $key . ': [' . implode(', ', $parts) . ']'];
            }
            $lines = [$pad . $key . ':'];
            foreach ($value as $item) {
                if (is_array($item)) {
                    $lines[] = $pad . '  -';
                    foreach ($item as $childKey => $child) {
                        if (!is_string($childKey)) {
                            continue;
                        }
                        $lines = array_merge($lines, $this->emitYamlPair($childKey, $child, $indent + 4));
                    }
                    continue;
                }
                $lines[] = $pad . '  - ' . $this->emitScalar((string)$item);
            }
            return $lines;
        }
        if (is_array($value)) {
            $lines = [$pad . $key . ':'];
            foreach ($value as $childKey => $child) {
                if (!is_string($childKey)) {
                    continue;
                }
                $lines = array_merge($lines, $this->emitYamlPair($childKey, $child, $indent + 2));
            }
            return $lines;
        }
        return [$pad . $key . ': ' . $this->emitScalar((string)$value)];
    }

    private function emitScalar(string $value): string
    {
        if ($value === '' || preg_match('/[:#\n"\'\[\]{}&*!|>%@`,]/', $value) || preg_match('/^\s|\s$/', $value) || in_array(strtolower($value), ['true', 'false', 'null', 'yes', 'no'], true)) {
            return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
        }
        return $value;
    }

    private function writeRunner(string $dir, string $script, string $lang): void
    {
        $php = $dir . '/scripts/run.php';
        $py = $dir . '/scripts/run.py';
        if (trim($script) === '') {
            if (is_file($php)) {
                unlink($php);
            }
            if (is_file($py)) {
                unlink($py);
            }
            if (is_dir($dir . '/scripts') && $this->dirIsEmpty($dir . '/scripts')) {
                rmdir($dir . '/scripts');
            }
            return;
        }
        if (!is_dir($dir . '/scripts') && !mkdir($dir . '/scripts', 0775, true) && !is_dir($dir . '/scripts')) {
            throw new RuntimeException('Could not save the skill.');
        }
        $file = $dir . '/scripts/run.' . $lang;
        $other = $lang === 'php' ? $py : $php;
        if (is_file($other)) {
            unlink($other);
        }
        if (file_put_contents($file, $script) === false) {
            throw new RuntimeException('Could not save the skill.');
        }
        $this->assertParses($file, 'run.' . $lang, $script);
    }

    private function runnerFile(string $dir): ?string
    {
        foreach (['run.php', 'run.py'] as $name) {
            $file = $dir . '/scripts/' . $name;
            if (is_file($file)) {
                return $file;
            }
        }
        return null;
    }

    private function readInside(string $dir, string $relative): string
    {
        $relative = str_replace('\\', '/', trim($relative));
        if (!$this->allowedRelative($relative)) {
            throw new RuntimeException('That skill file is not available.');
        }
        $root = realpath($dir);
        $full = $dir . '/' . $relative;
        $real = realpath($full);
        if ($root === false || $real === false || !is_file($real) || !str_starts_with($real, $root . DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('That skill file is not available.');
        }
        $data = file_get_contents($real);
        if ($data === false) {
            throw new RuntimeException('That skill file is not available.');
        }
        if (strlen($data) > 200000) {
            $data = substr($data, 0, 200000) . "\n…[truncated]";
        }
        return $data;
    }

    private function allowedRelative(string $path): bool
    {
        $path = str_replace('\\', '/', $path);
        if ($path === '' || str_contains($path, "\0") || str_contains($path, '..') || str_contains($path, '//')) {
            return false;
        }
        if ($path === 'SKILL.md') {
            return true;
        }
        foreach (['scripts/', 'references/', 'assets/', 'templates/'] as $prefix) {
            if (str_starts_with($path, $prefix) && (bool)preg_match('#^[A-Za-z0-9._/-]+$#', $path)) {
                return true;
            }
        }
        return false;
    }

    private function collectText(string $root, string $relative, array &$files): void
    {
        $dir = $relative === '' ? $root : $root . '/' . $relative;
        foreach (scandir($dir) ?: [] as $name) {
            if ($name === '.' || $name === '..' || str_starts_with($name, '.')) {
                continue;
            }
            $rel = $relative === '' ? $name : $relative . '/' . $name;
            $full = $root . '/' . $rel;
            if (is_link($full)) {
                continue;
            }
            if (is_dir($full)) {
                if ($relative === '' && !in_array($name, ['scripts', 'references', 'assets', 'templates'], true)) {
                    continue;
                }
                $this->collectText($root, $rel, $files);
                continue;
            }
            if (!is_file($full) || !$this->allowedRelative($rel) || count($files) >= 40) {
                continue;
            }
            $data = file_get_contents($full);
            if ($data === false || strlen($data) > 200000 || str_contains($data, "\0")) {
                continue;
            }
            $files[] = ['path' => $rel, 'content' => $data];
        }
    }

    private function withSource(array $skills): array
    {
        foreach ($skills as &$skill) {
            $skill['script'] = '';
            $skill['script_lang'] = '';
            $skill['instructions'] = '';
            $skill['available'] = !isset($this->hidden[(string)$skill['name']]);
            try {
                $dir = $this->absoluteDir((string)$skill['script_path']);
                $skill['source'] = $this->readSource($skill);
                $skill['instructions'] = $this->markdownBody($dir);
                $runner = $this->runnerFile($dir);
                if ($runner !== null) {
                    $skill['script'] = (string)file_get_contents($runner);
                    $skill['script_lang'] = str_ends_with($runner, '.py') ? 'py' : 'php';
                }
            } catch (Throwable $e) {
                $skill['source'] = '';
                $skill['instructions'] = '';
            }
        }
        return $skills;
    }

    private function testInput(array $call): array
    {
        if (array_key_exists('input', $call)) {
            return $this->testObject($call['input']);
        }
        if (array_key_exists('args', $call)) {
            return $this->testObject($call['args']);
        }
        unset($call['name'], $call['skill']);
        return $call;
    }

    private function testObject(mixed $value): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            return is_array($decoded) ? $decoded : [];
        }
        return is_array($value) ? $value : [];
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
            $check = sys_get_temp_dir() . '/skill-check-' . bin2hex(random_bytes(4));
            if (!mkdir($check, 0700, true) && !is_dir($check)) {
                throw new RuntimeException('Could not check the skill.');
            }
            $copy = $check . '/' . $filename;
            if (file_put_contents($copy, $source) === false) {
                $this->removeTree($check);
                throw new RuntimeException('Could not check the skill.');
            }
            try {
                $result = Process::run(['python3', '-m', 'py_compile', $copy], $check, null, 15);
            } finally {
                $this->removeTree($check);
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

    private function snapshotDir(?string $dir): ?string
    {
        if ($dir === null || !is_dir($dir)) {
            return null;
        }
        $tmp = sys_get_temp_dir() . '/skill-snap-' . bin2hex(random_bytes(4));
        $this->copyTree($dir, $tmp);
        return $tmp;
    }

    private function restoreSnapshot(string $dir, ?string $snap, bool $existed): void
    {
        if (is_dir($dir)) {
            $this->removeTree($dir);
        }
        if ($snap !== null && $existed) {
            $this->copyTree($snap, $dir);
        }
        if ($snap !== null && is_dir($snap)) {
            $this->removeTree($snap);
        }
    }

    private function copyTree(string $from, string $to): void
    {
        if (!mkdir($to, 0775, true) && !is_dir($to)) {
            throw new RuntimeException('Could not save the skill.');
        }
        foreach (scandir($from) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $src = $from . '/' . $name;
            $dst = $to . '/' . $name;
            if (is_link($src)) {
                continue;
            }
            if (is_dir($src)) {
                $this->copyTree($src, $dst);
            } elseif (is_file($src)) {
                copy($src, $dst);
            }
        }
    }

    private function removeSkillDir(string $relative): void
    {
        try {
            $dir = $this->absoluteDir($relative);
        } catch (Throwable $e) {
            return;
        }
        $root = realpath($this->dataDir . '/skills');
        $real = realpath($dir);
        if ($root === false || $real === false || $real === $root || !str_starts_with($real, $root . DIRECTORY_SEPARATOR)) {
            return;
        }
        $this->removeTree($real);
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
            if (is_dir($path) && !is_link($path)) {
                $this->removeTree($path);
            } elseif (is_file($path) || is_link($path)) {
                unlink($path);
            }
        }
        rmdir($dir);
    }

    private function dirIsEmpty(string $dir): bool
    {
        foreach (scandir($dir) ?: [] as $name) {
            if ($name !== '.' && $name !== '..') {
                return false;
            }
        }
        return true;
    }

    private function absoluteDir(string $relative): string
    {
        $relative = str_replace('\\', '/', trim($relative, '/'));
        if ($relative === '' || str_contains($relative, "\0") || str_contains($relative, '..') || !preg_match('#^[a-z0-9._-]+(?:/[a-z0-9._-]+)?$#', $relative)) {
            throw new RuntimeException('Skill path is not allowed.');
        }
        return $this->dataDir . '/skills/' . $relative;
    }

    private function assertEditable(string $name): void
    {
        $needle = str_replace('-', '_', $name);
        $reserved = array_merge(self::SYSTEM, [
            'search', 'create_task', 'list_tasks', 'wiki_search', 'wiki_read', 'wiki_write', 'wiki_reindex',
            'list_files', 'read_file', 'write_file', 'delete_file', 'move_file',
        ]);
        foreach ($reserved as $known) {
            if (str_replace('-', '_', $known) === $needle) {
                throw new RuntimeException('Skill ' . $known . ' is built in and cannot be replaced. It can only be turned on or off.');
            }
        }
        $existing = $this->db->skillByName($name);
        if ($existing && (string)($existing['created_by'] ?? '') === 'system') {
            throw new RuntimeException('Skill ' . $name . ' is built in and cannot be replaced. It can only be turned on or off.');
        }
    }

    private function existsMessage(array $skill): string
    {
        $name = (string)($skill['name'] ?? '');
        $description = $this->oneLine((string)($skill['description'] ?? ''));
        return 'Skill ' . $name . ' already exists: ' . $description . ' It was not changed. Call write_skill with that name to update a custom skill. A built-in skill cannot be changed.';
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
