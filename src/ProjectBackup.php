<?php

class ProjectBackup
{
    private const RECORD_LIMIT = 256 * 1024 * 1024;
    private const BYTE_LIMIT = 32 * 1024 * 1024 * 1024;
    private const FILE_LIMIT = 200000;

    public function __construct(private Database $db)
    {
    }

    public function export(array $folders): string
    {
        $delegated = $this->delegate('export', [$folders]);
        if ($delegated !== null) {
            if (!is_string($delegated) || $delegated === '') {
                throw new RuntimeException('Could not create the backup.');
            }
            return $delegated;
        }
        $this->prepareRuntime();
        $projects = $this->selected($folders);
        $tmp = tempnam(sys_get_temp_dir(), 'danaco-backup-');
        if ($tmp === false) {
            throw new RuntimeException('Could not create the backup.');
        }
        @unlink($tmp);
        $zipPath = $tmp . '.zip';
        $zip = $this->openNew($zipPath);
        try {
            $names = [];
            foreach ($projects as $project) {
                $folder = (string)$project['folder_name'];
                $names[] = $folder;
                $json = json_encode($this->recordFor($project), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
                if (!is_string($json)) {
                    throw new RuntimeException('Could not write the backup.');
                }
                if ($zip->addFromString('records/' . $folder . '.json', $json) !== true) {
                    throw new RuntimeException('Could not write the backup.');
                }
                $dir = $this->projectDir($folder);
                if ($dir !== null) {
                    $this->addDirectory($zip, $dir, 'projects/' . $folder);
                }
                if ($zip->addEmptyDir('projects/' . $folder) !== true) {
                    throw new RuntimeException('Could not write the backup.');
                }
            }
            $skills = json_encode($this->customSkills(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            if (!is_string($skills) || $zip->addFromString('skills.json', $skills) !== true) {
                throw new RuntimeException('Could not write the backup.');
            }
            $manifest = json_encode([
                'format' => 1,
                'created_at' => date('c'),
                'projects' => $names,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
            if (!is_string($manifest) || $zip->addFromString('manifest.json', $manifest) !== true) {
                throw new RuntimeException('Could not write the backup.');
            }
        } catch (Throwable $e) {
            $zip->close();
            @unlink($zipPath);
            throw $e;
        }
        if ($zip->close() !== true) {
            @unlink($zipPath);
            throw new RuntimeException('Could not save the backup.');
        }
        @chmod($zipPath, 0600);
        return $zipPath;
    }

    public function stageUpload(string $tmp): array
    {
        $this->prepareRuntime();
        if (!is_file($tmp)) {
            throw new InvalidArgumentException('Choose a backup zip.');
        }
        $token = bin2hex(random_bytes(16));
        $dest = $this->stagingDir() . '/' . $token . '.zip';
        if (!move_uploaded_file($tmp, $dest) && !copy($tmp, $dest)) {
            throw new RuntimeException('Could not store that backup.');
        }
        @chmod($dest, 0600);
        try {
            $info = $this->inspect($dest);
        } catch (Throwable $e) {
            @unlink($dest);
            throw $e;
        }
        $info['token'] = $token;
        return $info;
    }

    public function inspect(string $path): array
    {
        $delegated = $this->delegate('inspect', [$path]);
        if (is_array($delegated)) {
            return $delegated;
        }
        $this->prepareRuntime();
        $zip = $this->openZip($path);
        try {
            $projects = [];
            $conflicts = [];
            foreach ($this->manifest($zip) as $folder) {
                $record = $this->readRecord($zip, $folder);
                $reason = $this->describeConflict($record['folder_name'], $record['name']);
                $projects[] = [
                    'name' => $record['name'],
                    'folder' => $record['folder_name'],
                    'duplicate' => $reason !== null,
                ];
                if ($reason !== null) {
                    $conflicts[] = [
                        'name' => $record['name'],
                        'folder' => $record['folder_name'],
                        'reason' => $reason,
                    ];
                }
            }
        } finally {
            $zip->close();
        }
        return ['projects' => $projects, 'conflicts' => $conflicts];
    }

    public function restore(string $path, array $choices): array
    {
        $delegated = $this->delegate('restore', [$path, $choices]);
        if (is_array($delegated)) {
            return $delegated;
        }
        $this->prepareRuntime();
        $choices = $this->normalizeChoices($choices);
        foreach ($this->inspect($path)['conflicts'] as $conflict) {
            $choice = $choices[$conflict['folder']] ?? '';
            if (!in_array($choice, ['overwrite', 'ignore', 'keep'], true)) {
                throw new InvalidArgumentException('Choose overwrite, ignore, or keep both for ' . $conflict['name'] . '.');
            }
        }
        $zip = $this->openZip($path);
        $written = 0;
        $imported = [];
        $ignored = [];
        $skillsRestored = 0;
        try {
            foreach ($this->manifest($zip) as $folder) {
                $record = $this->readRecord($zip, $folder);
                $reason = $this->describeConflict($record['folder_name'], $record['name']);
                $destFolder = $record['folder_name'];
                $destName = $record['name'];
                $removed = [];
                $action = 'imported';
                if ($reason !== null) {
                    $choice = $choices[$record['folder_name']] ?? 'keep';
                    if (!in_array($choice, ['overwrite', 'ignore', 'keep'], true)) {
                        $choice = 'keep';
                    }
                    if ($choice === 'ignore') {
                        $ignored[] = [
                            'name' => $record['name'],
                            'folder' => $record['folder_name'],
                            'from' => $record['name'],
                            'action' => 'ignored',
                        ];
                        continue;
                    }
                    if ($choice === 'keep') {
                        $destFolder = $this->freeFolder($record['folder_name']);
                        $destName = $this->freeName($record['name']);
                        $action = 'kept';
                    } else {
                        $removed = $this->matchingProjects($record['folder_name'], $record['name']);
                        $action = 'overwritten';
                    }
                }
                $this->importOne($zip, $record, $destFolder, $destName, $removed, $action === 'overwritten', $written);
                $imported[] = [
                    'name' => $destName,
                    'folder' => $destFolder,
                    'from' => $record['name'],
                    'action' => $action,
                ];
            }
            $skillsRestored = $this->restoreSkills($zip);
        } finally {
            $zip->close();
        }
        $lines = [];
        foreach (array_merge($imported, $ignored) as $item) {
            $lines[] = $this->line($item);
        }
        if (!$lines) {
            $lines[] = 'Nothing was imported.';
        }
        if ($skillsRestored > 0) {
            $lines[] = 'Restored ' . $skillsRestored . ' custom skill' . ($skillsRestored === 1 ? '' : 's') . '.';
        }
        $this->reindex();
        return ['imported' => $imported, 'ignored' => $ignored, 'lines' => $lines];
    }

    public function restoreToken(string $token, array $choices): array
    {
        $path = $this->tokenPath($token);
        $result = $this->restore($path, $choices);
        @unlink($path);
        return $result;
    }

    public function discardToken(string $token): void
    {
        try {
            $path = $this->tokenPath($token);
        } catch (InvalidArgumentException $e) {
            return;
        }
        @unlink($path);
    }

    private function importOne(ZipArchive $zip, array $record, string $destFolder, string $destName, array $removed, bool $overwrite, int &$written): void
    {
        $stageParent = $this->holdRoot() . '/in-' . bin2hex(random_bytes(8));
        if (!mkdir($stageParent, 0775, true) && !is_dir($stageParent)) {
            throw new RuntimeException('Could not prepare the restore.');
        }
        $staged = $stageParent . '/' . $destFolder;
        $parks = [];
        $placed = false;
        $pdo = $this->db->pdo();
        try {
            if (!mkdir($staged, 0775, true) && !is_dir($staged)) {
                throw new RuntimeException('Could not prepare the restore.');
            }
            $this->extractProject($zip, $record['folder_name'], $staged, $written);
            foreach ($removed as $old) {
                $parks[(string)$old['folder_name']] = $this->park((string)$old['folder_name']);
            }
            if (file_exists(Projects::root($destFolder))) {
                $parks[$destFolder] = $this->park($destFolder);
            }
            if (!rename($staged, Projects::root($destFolder))) {
                throw new RuntimeException('Could not place ' . $destName . '.');
            }
            $placed = true;
            @rmdir($stageParent);
            $this->retitle($destFolder, $record['folder_name'], $record['name'], $destName);
            $this->ensureDesc($destFolder, $destName, $record['purpose']);
            $pdo->beginTransaction();
            foreach ($removed as $old) {
                $this->deleteProjectRow((int)$old['id']);
            }
            $id = $this->insertProject($record, $destFolder, $destName);
            $this->insertChildren($id, $record);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($placed) {
                $root = Projects::root($destFolder);
                if (is_dir($root) && !is_link($root)) {
                    $this->removeTree($root);
                }
            } elseif (is_dir($stageParent)) {
                $this->removeTree($stageParent);
            }
            foreach ($parks as $folder => $held) {
                try {
                    $this->unpark((string)$folder, $held);
                } catch (Throwable $restoreError) {
                    error_log($restoreError->getMessage());
                }
            }
            throw $e;
        }
        foreach ($parks as $held) {
            $this->discardPark($held);
        }
        if ($overwrite) {
            Projects::removeIndex($destFolder);
            foreach ($removed as $old) {
                Projects::removeIndex((string)$old['folder_name']);
            }
        }
        $project = $this->db->projectByFolder($destFolder);
        if ($project) {
            Projects::appendIndex($project);
        }
    }

    private function selected(array $folders): array
    {
        if (!$folders) {
            throw new InvalidArgumentException('Choose at least one project.');
        }
        $byFolder = [];
        foreach ($this->db->projects() as $project) {
            $byFolder[(string)$project['folder_name']] = $project;
        }
        $picked = [];
        foreach ($folders as $folder) {
            $folder = (string)$folder;
            if (isset($picked[$folder])) {
                continue;
            }
            if (!isset($byFolder[$folder])) {
                $shown = preg_replace('/[^\x20-\x7e]/', '', $folder) ?? '';
                $shown = substr($shown, 0, 80);
                throw new InvalidArgumentException($shown === '' ? 'Unknown project.' : 'Unknown project: ' . $shown);
            }
            $picked[$folder] = $byFolder[$folder];
        }
        if (!$picked) {
            throw new InvalidArgumentException('Choose at least one project.');
        }
        return array_values($picked);
    }

    private function recordFor(array $project): array
    {
        $id = (int)$project['id'];
        return [
            'name' => (string)$project['name'],
            'folder_name' => (string)$project['folder_name'],
            'purpose' => (string)($project['purpose'] ?? ''),
            'project_type' => (string)($project['project_type'] ?? ''),
            'status' => (string)($project['status'] ?? 'active'),
            'created_at' => (string)($project['created_at'] ?? ''),
            'tasks' => $this->plainRows('SELECT title, description, status, assigned_agent, schedule_time, recurrence_interval, result, finished_at, created_at FROM tasks WHERE project_id = ? ORDER BY id', $id),
            'messages' => $this->plainRows('SELECT role, agent_name, content, failed, detail, created_at FROM messages WHERE project_id = ? ORDER BY id', $id),
            'email_accounts' => $this->plainRows('SELECT label, from_name, from_email, smtp_host, smtp_port, smtp_security, username, password, created_at FROM email_accounts WHERE project_id = ? ORDER BY id', $id),
            'ftp_accounts' => $this->plainRows('SELECT label, host, port, security, username, password, created_at FROM ftp_accounts WHERE project_id = ? ORDER BY id', $id),
            'agent_logs' => $this->plainRows('SELECT agent_name, log_type, content, created_at FROM agent_logs WHERE project_id = ? ORDER BY id', $id),
        ];
    }

    private function customSkills(): array
    {
        $skills = app_services()['skills']->sync();
        $custom = [];
        foreach ($skills as $skill) {
            if ((string)($skill['created_by'] ?? '') === 'system') {
                continue;
            }
            $custom[] = [
                'name' => (string)$skill['name'],
                'description' => (string)$skill['description'],
                'script_path' => (string)$skill['script_path'],
                'is_active' => !empty($skill['is_active']),
                'files' => app_services()['skills']->exportFiles($skill),
            ];
        }
        return $custom;
    }

    private function plainRows(string $sql, int $id): array
    {
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([$id]);
        $rows = $stmt->fetchAll();
        foreach ($rows as &$row) {
            foreach (['failed', 'smtp_port', 'port'] as $key) {
                if (array_key_exists($key, $row) && $row[$key] !== null) {
                    $row[$key] = (int)$row[$key];
                }
            }
        }
        unset($row);
        return $rows;
    }

    private function projectDir(string $folder): ?string
    {
        $root = Projects::root($folder);
        if (!file_exists($root)) {
            return null;
        }
        if (is_link($root) || !is_dir($root)) {
            throw new RuntimeException('The folder ' . $folder . ' cannot be exported.');
        }
        $real = realpath($root);
        $data = realpath(rtrim(app_config()['data_dir'], '/'));
        if ($real === false || $data === false || !str_starts_with($real, $data . DIRECTORY_SEPARATOR) || basename($real) !== $folder) {
            throw new RuntimeException('The folder ' . $folder . ' cannot be exported.');
        }
        return $real;
    }

    private function addDirectory(ZipArchive $zip, string $root, string $prefix): void
    {
        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST
            );
        } catch (UnexpectedValueException $e) {
            throw new RuntimeException('Could not read ' . basename($root) . '.');
        }
        foreach ($iterator as $item) {
            if (!$item instanceof SplFileInfo) {
                continue;
            }
            $stored = $item->getPathname();
            if ($item->isLink()) {
                $target = $item->getRealPath();
                if ($target === false || !is_file($target) || !str_starts_with($target, $root . DIRECTORY_SEPARATOR)) {
                    continue;
                }
                $stored = $target;
            } elseif ($item->isDir()) {
                $rel = $this->relativeTo($root, $item->getPathname());
                if ($rel === '') {
                    continue;
                }
                if ($zip->addEmptyDir($prefix . '/' . $rel) !== true) {
                    throw new RuntimeException('Could not add a folder to the backup.');
                }
                continue;
            }
            if (!is_file($stored)) {
                continue;
            }
            $rel = $this->relativeTo($root, $item->getPathname());
            if ($rel === '') {
                continue;
            }
            if ($zip->addFile($stored, $prefix . '/' . $rel) !== true) {
                throw new RuntimeException('Could not add ' . $rel . ' to the backup.');
            }
        }
    }

    private function relativeTo(string $root, string $path): string
    {
        $root = rtrim(str_replace('\\', '/', $root), '/');
        $path = str_replace('\\', '/', $path);
        if (!str_starts_with($path, $root . '/')) {
            return '';
        }
        $rel = substr($path, strlen($root) + 1);
        if ($rel === '' || str_contains($rel, '..') || str_contains($rel, "\0")) {
            return '';
        }
        return $rel;
    }

    private function delegate(string $method, array $args): mixed
    {
        if (class_exists(ZipArchive::class)) {
            return null;
        }
        if (getenv('DANACO_ZIP_CHILD') === '1') {
            throw new RuntimeException('PHP zip support is not installed.');
        }
        $this->installZipSupport();
        if (class_exists(ZipArchive::class)) {
            return null;
        }
        if (!$this->freshPhpHasZip()) {
            throw new RuntimeException('PHP zip support is not installed.');
        }
        return $this->runDelegated($method, $args);
    }

    private function installZipSupport(): void
    {
        $root = dirname(__DIR__);
        $script = $root . '/bin/ensure-php.sh';
        if (!is_file($script)) {
            return;
        }
        $this->prepareRuntime();
        $result = Process::run(['bash', $script], $root, '', 900);
        $code = (int)($result['code'] ?? 1);
        if ($code !== 0 && $code !== 10) {
            $detail = trim((string)($result['stderr'] ?? '') . "\n" . (string)($result['stdout'] ?? ''));
            if ($detail !== '') {
                error_log($detail);
            }
        }
    }

    private function freshPhpHasZip(): bool
    {
        $php = PHP_BINARY !== '' ? PHP_BINARY : 'php';
        $result = Process::run([$php, '-r', 'exit(class_exists("ZipArchive") ? 0 : 1);'], dirname(__DIR__), '', 30);
        return (int)($result['code'] ?? 1) === 0;
    }

    private function childEnv(): array
    {
        $env = getenv();
        $clean = [];
        if (is_array($env)) {
            foreach ($env as $key => $value) {
                if (is_string($key) && is_string($value)) {
                    $clean[$key] = $value;
                }
            }
        }
        $clean['DANACO_ZIP_CHILD'] = '1';
        return $clean;
    }

    private function runDelegated(string $method, array $args): mixed
    {
        $root = dirname(__DIR__);
        $payload = json_encode(['method' => $method, 'args' => $args], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        if (!is_string($payload)) {
            throw new RuntimeException('Could not create the backup.');
        }
        $php = PHP_BINARY !== '' ? PHP_BINARY : 'php';
        $result = Process::run(
            [$php, '-r', 'require $argv[1] . "/src/bootstrap.php"; ProjectBackup::childMain();', $root],
            $root,
            $payload,
            86400,
            $this->childEnv()
        );
        $data = json_decode((string)($result['stdout'] ?? ''), true);
        $code = (int)($result['code'] ?? 1);
        if ($code === 0 && is_array($data) && array_key_exists('result', $data)) {
            return $data['result'];
        }
        $message = is_array($data) ? trim((string)($data['error'] ?? '')) : '';
        if ($message === '') {
            $message = trim((string)($result['stderr'] ?? ''));
        }
        if ($message === '') {
            $message = 'Could not create the backup.';
        }
        if ($code === 2) {
            throw new InvalidArgumentException($message);
        }
        throw new RuntimeException($message);
    }

    public static function childMain(): void
    {
        $raw = stream_get_contents(STDIN);
        $job = json_decode(is_string($raw) ? $raw : '', true);
        if (!is_array($job)) {
            fwrite(STDERR, "Could not create the backup.\n");
            exit(1);
        }
        $method = (string)($job['method'] ?? '');
        $args = $job['args'] ?? null;
        if (!is_array($args) || !in_array($method, ['export', 'inspect', 'restore'], true)) {
            fwrite(STDERR, "Could not create the backup.\n");
            exit(1);
        }
        try {
            $backup = new self(Database::instance());
            $result = $backup->{$method}(...array_values($args));
            $json = json_encode(['result' => $result], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            if (!is_string($json)) {
                throw new RuntimeException('Could not create the backup.');
            }
            echo $json;
        } catch (InvalidArgumentException $e) {
            $json = json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            echo is_string($json) ? $json : '';
            exit(2);
        } catch (Throwable $e) {
            $json = json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            echo is_string($json) ? $json : '';
            exit(1);
        }
    }

    private function openNew(string $path): ZipArchive
    {
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('PHP zip support is not installed.');
        }
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Could not create the backup.');
        }
        return $zip;
    }

    private function openZip(string $path): ZipArchive
    {
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('PHP zip support is not installed.');
        }
        if (!is_file($path)) {
            throw new InvalidArgumentException('That backup was not found.');
        }
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw new InvalidArgumentException('That file is not a project backup.');
        }
        if ($zip->numFiles > self::FILE_LIMIT) {
            $zip->close();
            throw new InvalidArgumentException('That backup has too many files.');
        }
        return $zip;
    }

    private function manifest(ZipArchive $zip): array
    {
        $stat = $zip->statName('manifest.json');
        if (!is_array($stat) || (int)$stat['size'] > 1024 * 1024) {
            throw new InvalidArgumentException('That file is not a project backup.');
        }
        $raw = $zip->getFromName('manifest.json');
        $data = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($data) || (int)($data['format'] ?? 0) !== 1 || !is_array($data['projects'] ?? null)) {
            throw new InvalidArgumentException('That file is not a project backup.');
        }
        $folders = [];
        foreach ($data['projects'] as $folder) {
            $folder = (string)$folder;
            $this->assertFolder($folder);
            if (isset($folders[$folder])) {
                throw new InvalidArgumentException('That backup lists ' . $folder . ' more than once.');
            }
            $folders[$folder] = true;
        }
        if (!$folders) {
            throw new InvalidArgumentException('That backup has no projects.');
        }
        return array_keys($folders);
    }

    private function readRecord(ZipArchive $zip, string $folder): array
    {
        $stat = $zip->statName('records/' . $folder . '.json');
        if (!is_array($stat) || (int)$stat['size'] > self::RECORD_LIMIT) {
            throw new InvalidArgumentException('The backup for ' . $folder . ' could not be read.');
        }
        $raw = $zip->getFromName('records/' . $folder . '.json');
        $data = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($data) || (string)($data['folder_name'] ?? '') !== $folder) {
            throw new InvalidArgumentException('The backup for ' . $folder . ' could not be read.');
        }
        $name = trim($this->text($data['name'] ?? '', 120, true, 'A project name'));
        if ($name === '') {
            throw new InvalidArgumentException('A project name in that backup is missing.');
        }
        return [
            'name' => $name,
            'folder_name' => $folder,
            'purpose' => $this->text($data['purpose'] ?? '', 100000, false, 'A project purpose'),
            'project_type' => $this->text($data['project_type'] ?? '', 200, false, 'A project type'),
            'status' => $this->token($data['status'] ?? 'active', 'active'),
            'created_at' => $this->looseStamp($data['created_at'] ?? null),
            'tasks' => $this->listOf($data['tasks'] ?? [], fn(array $row): array => $this->cleanTask($row), 'Tasks'),
            'messages' => $this->listOf($data['messages'] ?? [], fn(array $row): array => $this->cleanMessage($row), 'Messages'),
            'email_accounts' => $this->listOf($data['email_accounts'] ?? [], fn(array $row): array => $this->cleanEmail($row), 'Mailboxes'),
            'ftp_accounts' => $this->listOf($data['ftp_accounts'] ?? [], fn(array $row): array => $this->cleanFtp($row), 'FTP logins'),
            'agent_logs' => $this->listOf($data['agent_logs'] ?? [], fn(array $row): array => $this->cleanLog($row), 'Logs'),
        ];
    }

    private function restoreSkills(ZipArchive $zip): int
    {
        $stat = $zip->statName('skills.json');
        if (!is_array($stat)) {
            return 0;
        }
        if ((int)$stat['size'] > 25 * 1024 * 1024) {
            throw new InvalidArgumentException('The custom skills in that backup are too large.');
        }
        $raw = $zip->getFromName('skills.json');
        $skills = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($skills) || count($skills) > 100) {
            throw new InvalidArgumentException('The custom skills in that backup could not be read.');
        }
        $restored = 0;
        foreach ($skills as $skill) {
            if (!is_array($skill)) {
                throw new InvalidArgumentException('The custom skills in that backup could not be read.');
            }
            $files = $skill['files'] ?? null;
            if (!is_array($files)) {
                continue;
            }
            app_services()['skills']->restoreTree(
                (string)($skill['name'] ?? ''),
                (string)($skill['description'] ?? ''),
                $files,
                !empty($skill['is_active'])
            );
            $restored++;
        }
        return $restored;
    }

    private function cleanTask(array $row): array
    {
        return [
            'title' => $this->text($row['title'] ?? '', 200, true, 'A task title'),
            'description' => $this->text($row['description'] ?? '', 20000, false, 'A task description'),
            'status' => $this->token($row['status'] ?? 'todo', 'todo'),
            'assigned_agent' => $this->token($row['assigned_agent'] ?? 'orchestrator', 'orchestrator'),
            'schedule_time' => $this->looseStamp($row['schedule_time'] ?? null),
            'recurrence_interval' => $this->token($row['recurrence_interval'] ?? 'none', 'none'),
            'result' => $this->nullableText($row['result'] ?? null, 20000),
            'finished_at' => $this->looseStamp($row['finished_at'] ?? null),
            'created_at' => $this->looseStamp($row['created_at'] ?? null),
        ];
    }

    private function cleanMessage(array $row): array
    {
        return [
            'role' => $this->token($row['role'] ?? '', 'user'),
            'agent_name' => $this->nullableText($row['agent_name'] ?? null, 80),
            'content' => $this->text($row['content'] ?? '', 32 * 1024 * 1024, false, 'A message'),
            'failed' => !empty($row['failed']) ? 1 : 0,
            'detail' => $this->detailValue($row['detail'] ?? null),
            'created_at' => $this->looseStamp($row['created_at'] ?? null),
        ];
    }

    private function cleanEmail(array $row): array
    {
        return [
            'label' => $this->text($row['label'] ?? '', 200, true, 'A mailbox label'),
            'from_name' => $this->text($row['from_name'] ?? '', 200, true, 'A mailbox name'),
            'from_email' => $this->text($row['from_email'] ?? '', 320, true, 'A mailbox address'),
            'smtp_host' => $this->text($row['smtp_host'] ?? '', 255, true, 'A mailbox host'),
            'smtp_port' => $this->port($row['smtp_port'] ?? 0, 'A mailbox port'),
            'smtp_security' => $this->choice($row['smtp_security'] ?? '', ['tls', 'ssl', 'none'], 'tls'),
            'username' => $this->text($row['username'] ?? '', 320, true, 'A mailbox username'),
            'password' => $this->text($row['password'] ?? '', 8000, false, 'A mailbox password'),
            'created_at' => $this->looseStamp($row['created_at'] ?? null),
        ];
    }

    private function cleanFtp(array $row): array
    {
        return [
            'label' => $this->text($row['label'] ?? '', 200, true, 'An FTP name'),
            'host' => $this->text($row['host'] ?? '', 255, true, 'An FTP host'),
            'port' => $this->port($row['port'] ?? 0, 'An FTP port'),
            'security' => $this->choice($row['security'] ?? '', ['ftp', 'ftps'], 'ftp'),
            'username' => $this->text($row['username'] ?? '', 320, true, 'An FTP username'),
            'password' => $this->text($row['password'] ?? '', 8000, false, 'An FTP password'),
            'created_at' => $this->looseStamp($row['created_at'] ?? null),
        ];
    }

    private function cleanLog(array $row): array
    {
        return [
            'agent_name' => $this->text($row['agent_name'] ?? '', 80, true, 'A log'),
            'log_type' => $this->token($row['log_type'] ?? 'note', 'note'),
            'content' => $this->text($row['content'] ?? '', 32000, false, 'A log'),
            'created_at' => $this->looseStamp($row['created_at'] ?? null),
        ];
    }

    private function listOf(mixed $value, callable $clean, string $label): array
    {
        if ($value === null) {
            return [];
        }
        if (!is_array($value) || count($value) > 100000) {
            throw new InvalidArgumentException($label . ' in that backup could not be read.');
        }
        $out = [];
        foreach ($value as $row) {
            if (!is_array($row)) {
                throw new InvalidArgumentException($label . ' in that backup could not be read.');
            }
            $out[] = $clean($row);
        }
        return $out;
    }

    private function text(mixed $value, int $max, bool $required, string $label): string
    {
        if ($value === null) {
            $value = '';
        }
        if (!is_string($value) && !is_numeric($value)) {
            throw new InvalidArgumentException($label . ' in that backup could not be read.');
        }
        $value = (string)$value;
        if ($required && trim($value) === '') {
            throw new InvalidArgumentException($label . ' in that backup is missing.');
        }
        if (strlen($value) > $max) {
            throw new InvalidArgumentException($label . ' in that backup is too long.');
        }
        return $value;
    }

    private function nullableText(mixed $value, int $max): ?string
    {
        if ($value === null) {
            return null;
        }
        $text = $this->text($value, $max, false, 'A field');
        $text = trim($text);
        return $text === '' ? null : $text;
    }

    private function token(mixed $value, string $fallback): string
    {
        $value = is_string($value) || is_numeric($value) ? (string)$value : '';
        if (!preg_match('/^[A-Za-z0-9_.:-]{1,40}$/', $value)) {
            return $fallback;
        }
        return $value;
    }

    private function choice(mixed $value, array $allowed, string $fallback): string
    {
        $value = is_string($value) ? strtolower($value) : '';
        return in_array($value, $allowed, true) ? $value : $fallback;
    }

    private function port(mixed $value, string $label): int
    {
        $port = (int)$value;
        if ($port < 1 || $port > 65535) {
            throw new InvalidArgumentException($label . ' in that backup is invalid.');
        }
        return $port;
    }

    private function looseStamp(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (!is_string($value) && !is_numeric($value)) {
            return null;
        }
        $value = trim((string)$value);
        if ($value === '' || strlen($value) > 40 || preg_match('/[\x00-\x1f]/', $value)) {
            return null;
        }
        return $value;
    }

    private function detailValue(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_array($value)) {
            $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            return is_string($json) ? $json : null;
        }
        if (!is_string($value)) {
            return null;
        }
        if (strlen($value) > 8 * 1024 * 1024) {
            throw new InvalidArgumentException('A message in that backup is too large.');
        }
        return $value;
    }

    private function describeConflict(string $folder, string $name): ?string
    {
        $byFolder = $this->db->projectByFolder($folder);
        $byName = $this->projectByName($name);
        $disk = $byFolder ? false : $this->folderOccupied($folder);
        if (!$byFolder && !$byName && !$disk) {
            return null;
        }
        if ($byFolder && $byName && (int)$byFolder['id'] === (int)$byName['id']) {
            return 'A project named ' . $name . ' already uses the folder ' . $folder . '.';
        }
        if ($byFolder && $byName) {
            return 'The folder ' . $folder . ' already exists, and the name ' . $name . ' is already used by ' . $byName['folder_name'] . '.';
        }
        if ($byFolder || $disk) {
            return 'The folder ' . $folder . ' already exists.';
        }
        return 'A project named ' . $name . ' already exists in the folder ' . $byName['folder_name'] . '.';
    }

    private function projectByName(string $name): ?array
    {
        foreach ($this->db->projects() as $project) {
            if (strcasecmp((string)$project['name'], $name) === 0) {
                return $project;
            }
        }
        return null;
    }

    private function matchingProjects(string $folder, string $name): array
    {
        $found = [];
        $byFolder = $this->db->projectByFolder($folder);
        if ($byFolder) {
            $found[(int)$byFolder['id']] = $byFolder;
        }
        foreach ($this->db->projects() as $project) {
            if (strcasecmp((string)$project['name'], $name) === 0) {
                $found[(int)$project['id']] = $project;
            }
        }
        return array_values($found);
    }

    private function folderOccupied(string $folder): bool
    {
        if (!preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $folder)) {
            return false;
        }
        return file_exists(Projects::root($folder));
    }

    private function freeFolder(string $base): string
    {
        $candidate = $base;
        $n = 2;
        while ($this->folderOccupied($candidate) || $this->db->projectByFolder($candidate)) {
            $suffix = '-' . $n;
            $room = 64 - strlen($suffix);
            $trimmed = rtrim(substr($base, 0, max(1, $room)), '-');
            if (!preg_match('/^[a-z0-9]/', $trimmed)) {
                $trimmed = 'project';
            }
            $candidate = substr($trimmed, 0, $room) . $suffix;
            $n++;
            if ($n > 1000) {
                throw new RuntimeException('Could not find a free folder name.');
            }
        }
        return Projects::sanitizeFolder($candidate);
    }

    private function freeName(string $base): string
    {
        $base = trim($base);
        if ($base === '') {
            $base = 'Project';
        }
        $candidate = $base;
        $n = 2;
        while ($this->projectByName($candidate)) {
            $suffix = ' (' . $n . ')';
            $trimmed = rtrim($this->clipBytes($base, max(1, 120 - strlen($suffix))));
            if ($trimmed === '') {
                $trimmed = 'Project';
            }
            $candidate = $trimmed . $suffix;
            $n++;
            if ($n > 1000 || strlen($candidate) > 120) {
                throw new RuntimeException('Could not find a free project name.');
            }
        }
        return $candidate;
    }

    private function clipBytes(string $text, int $max): string
    {
        if (strlen($text) <= $max) {
            return $text;
        }
        $text = substr($text, 0, $max);
        for ($i = 0; $i < 4 && $text !== '' && preg_match('//u', $text) !== 1; $i++) {
            $text = substr($text, 0, -1);
        }
        return $text;
    }

    private function extractProject(ZipArchive $zip, string $sourceFolder, string $destRoot, int &$written): void
    {
        $prefix = 'projects/' . $sourceFolder . '/';
        if (!is_dir($destRoot) && !mkdir($destRoot, 0775, true) && !is_dir($destRoot)) {
            throw new RuntimeException('Could not create the project folder.');
        }
        $rootReal = realpath($destRoot);
        if ($rootReal === false) {
            throw new RuntimeException('Could not create the project folder.');
        }
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (!is_string($name)) {
                continue;
            }
            $name = str_replace('\\', '/', $name);
            if (!str_starts_with($name, $prefix)) {
                continue;
            }
            $rel = substr($name, strlen($prefix));
            if ($rel === '') {
                continue;
            }
            $this->assertSafeRelative($rel);
            if ($this->isZipSymlink($zip, $i)) {
                continue;
            }
            $target = $rootReal . '/' . rtrim($rel, '/');
            if (str_ends_with($name, '/')) {
                if (!is_dir($target) && !mkdir($target, 0775, true) && !is_dir($target)) {
                    throw new RuntimeException('Could not create a folder in ' . $sourceFolder . '.');
                }
                continue;
            }
            $parent = dirname($target);
            if (!is_dir($parent) && !mkdir($parent, 0775, true) && !is_dir($parent)) {
                throw new RuntimeException('Could not create a folder in ' . $sourceFolder . '.');
            }
            $parentReal = realpath($parent);
            if ($parentReal === false || ($parentReal !== $rootReal && !str_starts_with($parentReal, $rootReal . DIRECTORY_SEPARATOR))) {
                throw new RuntimeException('The backup contains an unsafe path.');
            }
            $this->streamEntry($zip, $name, $target, $written);
            $fileReal = realpath($target);
            if ($fileReal === false || !str_starts_with($fileReal, $rootReal . DIRECTORY_SEPARATOR)) {
                @unlink($target);
                throw new RuntimeException('The backup contains an unsafe path.');
            }
        }
    }

    private function assertSafeRelative(string $rel): void
    {
        $rel = str_replace('\\', '/', $rel);
        if ($rel === '' || str_contains($rel, "\0") || str_starts_with($rel, '/')) {
            throw new RuntimeException('The backup contains an unsafe path.');
        }
        foreach (explode('/', rtrim($rel, '/')) as $part) {
            if ($part === '' || $part === '.' || $part === '..' || strlen($part) > 255 || preg_match('/[\x00-\x1f]/', $part)) {
                throw new RuntimeException('The backup contains an unsafe path.');
            }
        }
    }

    private function isZipSymlink(ZipArchive $zip, int $index): bool
    {
        $opsys = 0;
        $attr = 0;
        if ($zip->getExternalAttributesIndex($index, $opsys, $attr) !== true) {
            return false;
        }
        $mode = ($attr >> 16) & 0xFFFF;
        return ($mode & 0xF000) === 0xA000;
    }

    private function streamEntry(ZipArchive $zip, string $name, string $target, int &$written): void
    {
        $in = $zip->getStream($name);
        if (!is_resource($in)) {
            throw new RuntimeException('Could not read a file from the backup.');
        }
        $out = fopen($target, 'wb');
        if ($out === false) {
            fclose($in);
            throw new RuntimeException('Could not write a restored file.');
        }
        try {
            while (!feof($in)) {
                $chunk = fread($in, 1024 * 1024);
                if ($chunk === false) {
                    throw new RuntimeException('Could not read a file from the backup.');
                }
                if ($chunk === '') {
                    break;
                }
                $written += strlen($chunk);
                if ($written > self::BYTE_LIMIT) {
                    throw new RuntimeException('That backup is too large.');
                }
                if (fwrite($out, $chunk) === false) {
                    throw new RuntimeException('Could not write a restored file.');
                }
            }
        } catch (Throwable $e) {
            fclose($in);
            fclose($out);
            @unlink($target);
            throw $e;
        }
        fclose($in);
        fclose($out);
    }

    private function insertProject(array $record, string $folder, string $name): int
    {
        $pdo = $this->db->pdo();
        if ($record['created_at']) {
            $stmt = $pdo->prepare('INSERT INTO projects (name, folder_name, purpose, project_type, status, created_at) VALUES (?, ?, ?, ?, ?, ?)');
            $stmt->execute([$name, $folder, $record['purpose'], $record['project_type'], $record['status'], $record['created_at']]);
        } else {
            $stmt = $pdo->prepare('INSERT INTO projects (name, folder_name, purpose, project_type, status) VALUES (?, ?, ?, ?, ?)');
            $stmt->execute([$name, $folder, $record['purpose'], $record['project_type'], $record['status']]);
        }
        return (int)$pdo->lastInsertId();
    }

    private function insertChildren(int $projectId, array $record): void
    {
        $pdo = $this->db->pdo();
        $task = $pdo->prepare('INSERT INTO tasks (project_id, title, description, status, assigned_agent, schedule_time, recurrence_interval, result, finished_at, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, COALESCE(?, CURRENT_TIMESTAMP))');
        foreach ($record['tasks'] as $row) {
            $task->execute([
                $projectId,
                $row['title'],
                $row['description'],
                $row['status'],
                $row['assigned_agent'],
                $row['schedule_time'],
                $row['recurrence_interval'],
                $row['result'],
                $row['finished_at'],
                $row['created_at'],
            ]);
        }
        $message = $pdo->prepare('INSERT INTO messages (project_id, role, agent_name, content, failed, detail, created_at) VALUES (?, ?, ?, ?, ?, ?, COALESCE(?, CURRENT_TIMESTAMP))');
        foreach ($record['messages'] as $row) {
            $message->execute([
                $projectId,
                $row['role'],
                $row['agent_name'],
                $row['content'],
                $row['failed'],
                $row['detail'],
                $row['created_at'],
            ]);
        }
        $email = $pdo->prepare('INSERT INTO email_accounts (project_id, label, from_name, from_email, smtp_host, smtp_port, smtp_security, username, password, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, COALESCE(?, CURRENT_TIMESTAMP))');
        foreach ($record['email_accounts'] as $row) {
            $email->execute([
                $projectId,
                $row['label'],
                $row['from_name'],
                $row['from_email'],
                $row['smtp_host'],
                $row['smtp_port'],
                $row['smtp_security'],
                $row['username'],
                $row['password'],
                $row['created_at'],
            ]);
        }
        $ftp = $pdo->prepare('INSERT INTO ftp_accounts (project_id, label, host, port, security, username, password, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, COALESCE(?, CURRENT_TIMESTAMP))');
        foreach ($record['ftp_accounts'] as $row) {
            $ftp->execute([
                $projectId,
                $row['label'],
                $row['host'],
                $row['port'],
                $row['security'],
                $row['username'],
                $row['password'],
                $row['created_at'],
            ]);
        }
        $log = $pdo->prepare('INSERT INTO agent_logs (agent_name, project_id, log_type, content, created_at) VALUES (?, ?, ?, ?, COALESCE(?, CURRENT_TIMESTAMP))');
        foreach ($record['agent_logs'] as $row) {
            $log->execute([
                $row['agent_name'],
                $projectId,
                $row['log_type'],
                $row['content'],
                $row['created_at'],
            ]);
        }
    }

    private function deleteProjectRow(int $id): void
    {
        $this->db->pdo()->prepare('DELETE FROM agent_logs WHERE project_id = ?')->execute([$id]);
        $this->db->deleteProject($id);
    }

    private function retitle(string $folder, string $oldFolder, string $oldName, string $newName): void
    {
        $wiki = Projects::root($folder) . '/wiki';
        $from = $wiki . '/' . $oldFolder . '_desc.md';
        $to = $wiki . '/' . Projects::descFile($folder);
        if ($from !== $to && is_file($from) && !is_file($to) && !rename($from, $to)) {
            throw new RuntimeException('Could not rename the project description.');
        }
        if ($oldName === $newName) {
            return;
        }
        $desc = $wiki . '/' . Projects::descFile($folder);
        if (!is_file($desc)) {
            return;
        }
        $text = (string)file_get_contents($desc);
        $heading = '# ' . $oldName;
        if ($text !== $heading && !str_starts_with($text, $heading . "\n") && !str_starts_with($text, $heading . "\r\n")) {
            return;
        }
        if (file_put_contents($desc, '# ' . $newName . substr($text, strlen($heading))) === false) {
            throw new RuntimeException('Could not rename the project description.');
        }
    }

    private function ensureDesc(string $folder, string $name, string $purpose): void
    {
        $wiki = Projects::root($folder) . '/wiki';
        if (!is_dir($wiki) && !mkdir($wiki, 0775, true) && !is_dir($wiki)) {
            throw new RuntimeException('Could not create the project wiki.');
        }
        $desc = $wiki . '/' . Projects::descFile($folder);
        if (is_file($desc)) {
            return;
        }
        $purpose = trim($purpose);
        $body = '# ' . $name . "\n\n" . ($purpose !== '' ? $purpose . "\n" : '');
        if (file_put_contents($desc, $body) === false) {
            throw new RuntimeException('Could not create the project description.');
        }
    }

    private function line(array $item): string
    {
        if ($item['action'] === 'ignored') {
            return 'Ignored ' . $item['from'] . '.';
        }
        if ($item['action'] === 'kept') {
            return 'Imported ' . $item['name'] . ' into ' . $item['folder'] . '.';
        }
        if ($item['action'] === 'overwritten') {
            return 'Replaced ' . $item['name'] . '.';
        }
        return 'Imported ' . $item['name'] . '.';
    }

    private function assertFolder(string $folder): void
    {
        if (!preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $folder) || in_array($folder, ['wiki', 'skills', 'sessions', 'projects', Projects::generalFolder()], true) || str_contains($folder, 'trashcan') || str_contains($folder, 'backups')) {
            throw new InvalidArgumentException('That backup contains an unsafe project name.');
        }
    }

    private function normalizeChoices(array $choices): array
    {
        $out = [];
        foreach ($choices as $folder => $choice) {
            if (!is_string($folder) && !is_int($folder)) {
                continue;
            }
            $folder = (string)$folder;
            if (!preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $folder) || !is_string($choice)) {
                continue;
            }
            $out[$folder] = $choice;
        }
        return $out;
    }

    private function park(string $folder): ?string
    {
        $root = Projects::root($folder);
        if (!file_exists($root)) {
            return null;
        }
        if (!is_dir($root) || is_link($root)) {
            throw new RuntimeException('The folder ' . $folder . ' is in the way.');
        }
        $hold = $this->holdRoot() . '/' . bin2hex(random_bytes(8));
        if (!mkdir($hold, 0775, true) && !is_dir($hold)) {
            throw new RuntimeException('Could not move the existing project aside.');
        }
        $held = $hold . '/' . $folder;
        if (!rename($root, $held)) {
            @rmdir($hold);
            throw new RuntimeException('Could not move the existing project aside.');
        }
        return $held;
    }

    private function unpark(string $folder, ?string $held): void
    {
        if ($held === null || !file_exists($held)) {
            return;
        }
        $root = Projects::root($folder);
        if (file_exists($root)) {
            $this->removeTree($root);
        }
        if (!rename($held, $root)) {
            throw new RuntimeException('Could not put the existing project back.');
        }
        @rmdir(dirname($held));
    }

    private function discardPark(?string $held): void
    {
        if ($held === null || !file_exists($held)) {
            return;
        }
        $this->removeTree($held);
        @rmdir(dirname($held));
    }

    private function removeTree(string $path): void
    {
        if (is_link($path) || !is_dir($path)) {
            @unlink($path);
            return;
        }
        $real = realpath($path);
        $data = realpath(rtrim(app_config()['data_dir'], '/'));
        if ($real === false || $data === false || $real === $data || !str_starts_with($real, $data . DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('Could not remove a folder outside the data directory.');
        }
        foreach (scandir($path) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $this->removeTree($path . '/' . $name);
        }
        @rmdir($path);
    }

    private function stagingDir(): string
    {
        $dir = rtrim(app_config()['data_dir'], '/') . '/.backup-staging';
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Could not store that backup.');
        }
        return $dir;
    }

    private function holdRoot(): string
    {
        $dir = rtrim(app_config()['data_dir'], '/') . '/.backup-hold';
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Could not prepare the restore.');
        }
        return $dir;
    }

    private function tokenPath(string $token): string
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
            throw new InvalidArgumentException('That restore has expired. Choose the backup again.');
        }
        $path = $this->stagingDir() . '/' . $token . '.zip';
        if (!is_file($path)) {
            throw new InvalidArgumentException('That restore has expired. Choose the backup again.');
        }
        $real = realpath($path);
        $dir = realpath($this->stagingDir());
        if ($real === false || $dir === false || !str_starts_with($real, $dir . DIRECTORY_SEPARATOR)) {
            throw new InvalidArgumentException('That restore has expired. Choose the backup again.');
        }
        return $real;
    }

    private function prepareRuntime(): void
    {
        @ini_set('memory_limit', '1024M');
        @set_time_limit(0);
        $this->cleanupOld();
    }

    private function cleanupOld(): void
    {
        $this->purgeOld(rtrim(app_config()['data_dir'], '/') . '/.backup-staging', 86400, false);
        $this->purgeOld(rtrim(app_config()['data_dir'], '/') . '/.backup-hold', 7 * 86400, true);
    }

    private function purgeOld(string $dir, int $age, bool $tree): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $now = time();
        foreach (scandir($dir) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $path = $dir . '/' . $name;
            $mtime = filemtime($path);
            if ($mtime === false || ($now - $mtime) < $age) {
                continue;
            }
            if ($tree) {
                $this->removeTree($path);
            } else {
                @unlink($path);
            }
        }
    }

    private function reindex(): void
    {
        if (!function_exists('app_services')) {
            return;
        }
        try {
            app_services()['memory']->reindex();
        } catch (Throwable $e) {
        }
    }
}
