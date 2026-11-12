<?php

class Database
{
    private static ?self $instance = null;
    private PDO $pdo;

    private array $loopScope = [];

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        $config = app_config();
        $dir = $config['data_dir'];
        foreach ([$dir, $dir . '/skills', $dir . '/wiki', $dir . '/sessions', $dir . '/.backups', $dir . '/.trashcan'] as $path) {
            if (!is_dir($path) && !mkdir($path, 0775, true) && !is_dir($path)) {
                throw new RuntimeException('Could not create ' . $path);
            }
        }
        $this->pdo = new PDO('sqlite:' . $config['db_path']);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->pdo->exec('PRAGMA foreign_keys = ON');
        $this->pdo->exec('PRAGMA busy_timeout = 5000');
        $this->pdo->query('PRAGMA journal_mode = WAL')->fetch();
        $this->migrate();
        $this->seedDefaults($config);
        Projects::migrateLayout($this->projects());
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    private function migrate(): void
    {
        $this->pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS projects (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    folder_name TEXT UNIQUE NOT NULL,
    purpose TEXT,
    project_type TEXT,
    status TEXT DEFAULT 'active',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS tasks (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    project_id INTEGER,
    title TEXT NOT NULL,
    description TEXT,
    status TEXT DEFAULT 'todo',
    assigned_agent TEXT DEFAULT 'orchestrator',
    schedule_time DATETIME NULL,
    recurrence_interval TEXT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(project_id) REFERENCES projects(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS skills (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT UNIQUE NOT NULL,
    description TEXT NOT NULL,
    script_path TEXT NOT NULL,
    is_active INTEGER DEFAULT 1,
    created_by TEXT DEFAULT 'system',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS settings (
    key TEXT PRIMARY KEY,
    value TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS agent_logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    agent_name TEXT NOT NULL,
    project_id INTEGER NULL,
    log_type TEXT NOT NULL,
    content TEXT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS messages (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    project_id INTEGER,
    role TEXT NOT NULL,
    agent_name TEXT,
    content TEXT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(project_id) REFERENCES projects(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS login_attempts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    ip TEXT NOT NULL,
    success INTEGER NOT NULL DEFAULT 0,
    attempted_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_tasks_project ON tasks(project_id);
CREATE INDEX IF NOT EXISTS idx_tasks_schedule ON tasks(schedule_time);
CREATE INDEX IF NOT EXISTS idx_messages_project ON messages(project_id);
CREATE INDEX IF NOT EXISTS idx_login_ip ON login_attempts(ip, attempted_at);
CREATE INDEX IF NOT EXISTS idx_agent_logs_lookup ON agent_logs(agent_name, project_id, id);
SQL);
        $messageColumns = array_column($this->pdo->query('PRAGMA table_info(messages)')->fetchAll(), 'name');
        if (!in_array('failed', $messageColumns, true)) {
            $this->pdo->exec('ALTER TABLE messages ADD COLUMN failed INTEGER NOT NULL DEFAULT 0');
        }
        if (!in_array('detail', $messageColumns, true)) {
            $this->pdo->exec('ALTER TABLE messages ADD COLUMN detail TEXT');
        }
        $taskColumns = array_column($this->pdo->query('PRAGMA table_info(tasks)')->fetchAll(), 'name');
        if (!in_array('result', $taskColumns, true)) {
            $this->pdo->exec('ALTER TABLE tasks ADD COLUMN result TEXT');
        }
        if (!in_array('finished_at', $taskColumns, true)) {
            $this->pdo->exec('ALTER TABLE tasks ADD COLUMN finished_at DATETIME');
        }
        if (!in_array('held', $taskColumns, true)) {
            $this->pdo->exec('ALTER TABLE tasks ADD COLUMN held INTEGER NOT NULL DEFAULT 0');
        }
        $projectColumns = array_column($this->pdo->query('PRAGMA table_info(projects)')->fetchAll(), 'name');
        if (!in_array('former_name', $projectColumns, true)) {
            $this->pdo->exec('ALTER TABLE projects ADD COLUMN former_name TEXT');
        }
        $this->pdo->exec("UPDATE tasks SET status = 'in_progress' WHERE schedule_time IS NOT NULL AND status IN ('todo', 'backlog')");
        $this->pdo->exec("UPDATE tasks SET status = 'in_progress' WHERE recurrence_interval IS NOT NULL AND recurrence_interval NOT IN ('', 'none') AND status IN ('todo', 'backlog', 'review', 'done')");
        $this->pdo->exec("UPDATE tasks SET status = 'in_progress', held = 1 WHERE status IN ('todo', 'backlog')");
        $this->pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS jobs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    kind TEXT NOT NULL,
    ref_id INTEGER,
    project_id INTEGER,
    status TEXT NOT NULL DEFAULT 'running',
    attempts INTEGER NOT NULL DEFAULT 1,
    payload TEXT NOT NULL,
    last_error TEXT,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_jobs_due ON jobs(status, updated_at);
SQL);
        $this->pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS loop_logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    job_id INTEGER NULL,
    project_id INTEGER NULL,
    agent_name TEXT NOT NULL,
    kind TEXT NOT NULL,
    line TEXT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_loop_logs_lookup ON loop_logs(project_id, id);
SQL);
        $this->pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS email_accounts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    project_id INTEGER NOT NULL,
    label TEXT NOT NULL,
    from_name TEXT NOT NULL,
    from_email TEXT NOT NULL,
    smtp_host TEXT NOT NULL,
    smtp_port INTEGER NOT NULL,
    smtp_security TEXT NOT NULL DEFAULT 'tls',
    username TEXT NOT NULL,
    password TEXT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(project_id) REFERENCES projects(id) ON DELETE CASCADE
);
SQL);
        $this->pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS ftp_accounts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    project_id INTEGER NOT NULL,
    label TEXT NOT NULL,
    host TEXT NOT NULL,
    port INTEGER NOT NULL,
    security TEXT NOT NULL DEFAULT 'ftp',
    username TEXT NOT NULL,
    password TEXT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(project_id) REFERENCES projects(id) ON DELETE CASCADE
);
SQL);
        $this->pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS mcp_servers (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    label TEXT NOT NULL,
    target TEXT NOT NULL,
    env_json TEXT NOT NULL DEFAULT '{}',
    tools_json TEXT NOT NULL DEFAULT '[]',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);
SQL);
        $this->pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS compliance_events (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    project_id INTEGER NULL,
    actor TEXT NOT NULL DEFAULT 'system',
    event_type TEXT NOT NULL,
    summary TEXT NOT NULL,
    detail_json TEXT,
    related_job_id INTEGER NULL
);
CREATE INDEX IF NOT EXISTS idx_compliance_events_created ON compliance_events(created_at);
CREATE INDEX IF NOT EXISTS idx_compliance_events_type ON compliance_events(event_type, id);
SQL);
    }

    private function seedDefaults(array $config): void
    {
        $defaults = [
            'model_mode' => 'cpu_failsafe',
            'ollama_url' => $config['ollama_url'],
            'local_model' => $config['local_model'],
            'cpu_failsafe_model' => $config['cpu_failsafe_model'],
            'cloud_base_url' => $config['cloud_base_url'],
            'cloud_model' => $config['cloud_model'],
            'cloud_api_key' => '',
            'cloud_api_use' => 'main',
            'max_turns' => (string)$config['max_turns'],
            'cloud_max_turns' => (string)$config['cloud_max_turns'],
            'chat_font_size' => '16',
            'web_search' => '1',
            'search_provider' => 'duckduckgo',
            'headless_browser' => '1',
            'terminal' => '1',
            'full_system_control' => '0',
            'api_keys' => '1',
            'extra_api_key_name' => '',
            'extra_api_key_value' => '',
            'ai_act_applies' => '',
            'compliance_logging' => '0',
            'hitl_external_actions' => '0',
            'compliance_retention_days' => '90',
            'compliance_responsible_person' => '',
            'compliance_intended_use' => '',
            'terms_accepted_version' => '',
            'terms_accepted_at' => '',
            'system_card_exported_at' => '',
            'agent_status' => json_encode(self::idleStatus()),
        ];
        $stmt = $this->pdo->prepare('INSERT OR IGNORE INTO settings (key, value) VALUES (?, ?)');
        foreach ($defaults as $key => $value) {
            $stmt->execute([$key, $value]);
        }
        if ($this->getSetting('max_turns_raised') !== '1') {
            if ((int)$this->getSetting('max_turns', '8') <= 8) {
                $this->setSetting('max_turns', '50');
            }
            $this->setSetting('max_turns_raised', '1');
        }
        if ($this->getSetting('tools_default_on') !== '1') {
            foreach (['web_search', 'headless_browser', 'terminal', 'api_keys'] as $key) {
                $this->setSetting($key, '1');
            }
            $this->setSetting('tools_default_on', '1');
        }
        $wiki = $config['data_dir'] . '/wiki/projects_index.md';
        $heading = "# Main wiki\n\nProjects are listed here. Check this index before creating a new project.\n";
        if (!is_file($wiki)) {
            file_put_contents($wiki, $heading);
        } else {
            $text = (string)file_get_contents($wiki);
            $updated = preg_replace('/\A# Central Hive Wiki\b/', '# Main wiki', $text, 1);
            if (is_string($updated) && $updated !== $text) {
                file_put_contents($wiki, $updated);
            }
        }
        $memory = new MemoryManager($config['data_dir']);
        if ($memory->relocateToolNotes()) {
            $memory->reindex();
        }
        $tools = $config['data_dir'] . '/tool-notes.md';
        if (!is_file($tools)) {
            file_put_contents($tools, "# Tools\n\nNotes the agent keeps about email, ftp, the headless browser, and other tools.\n");
        }
        if ($this->getSetting('tools_noted') !== '1') {
            if ($this->getSetting('headless_browser', '0') === '1') {
                $memory->remember('browser', 'Headless browser is on. browse_url opens a public page and runs its scripts.');
            }
            if ($this->getSetting('web_search', '0') === '1') {
                $memory->remember('search', 'Web search is on for the researcher. The main agent delegates web research.');
            }
            if ($this->getSetting('terminal', '0') === '1') {
                $memory->remember('terminal', 'Terminal commands are on inside the project folder.');
            }
            $this->setSetting('tools_noted', '1');
        }
    }

    public static function idleStatus(): array
    {
        return [
            'orchestrator' => 'idle',
            'researcher' => 'idle',
            'writer' => 'idle',
            'coder' => 'idle',
            'analyst' => 'idle',
            'marketer' => 'idle',
            'consultant' => 'idle',
        ];
    }

    public function consultantState(): array
    {
        $use = (string)$this->getSetting('cloud_api_use', 'main');
        $key = trim((string)$this->getSetting('cloud_api_key', ''));
        $mode = (string)$this->getSetting('model_mode', 'cpu_failsafe');
        if ($key === '') {
            return ['active' => false, 'note' => 'No API key', 'reason' => 'no_key'];
        }
        if ($use !== 'consultant' || $mode === 'cloud') {
            return ['active' => false, 'note' => $mode === 'cloud' ? 'API is the main model' : '', 'reason' => 'off'];
        }
        return ['active' => true, 'note' => '', 'reason' => 'on'];
    }

    public function consultantActive(): bool
    {
        return $this->consultantState()['active'];
    }

    public function fullSystemControl(): bool
    {
        return $this->getSetting('full_system_control', '0') === '1';
    }

    public function getSetting(string $key, ?string $default = null): ?string
    {
        $stmt = $this->pdo->prepare('SELECT value FROM settings WHERE key = ?');
        $stmt->execute([$key]);
        $row = $stmt->fetch();
        return $row ? (string)$row['value'] : $default;
    }

    public function setSetting(string $key, string $value): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO settings (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value');
        $stmt->execute([$key, $value]);
    }

    public function allSettings(): array
    {
        $rows = $this->pdo->query('SELECT key, value FROM settings')->fetchAll();
        $out = [];
        foreach ($rows as $row) {
            $out[$row['key']] = $row['value'];
        }
        return $out;
    }

    public function projects(): array
    {
        return array_map([$this, 'castProject'], $this->pdo->query('SELECT * FROM projects ORDER BY name COLLATE NOCASE')->fetchAll());
    }

    public function project(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM projects WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ? $this->castProject($row) : null;
    }

    public function projectByFolder(string $folder): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM projects WHERE folder_name = ?');
        $stmt->execute([$folder]);
        $row = $stmt->fetch();
        return $row ? $this->castProject($row) : null;
    }

    public function insertProject(string $name, string $folder, string $purpose, string $type): array
    {
        $stmt = $this->pdo->prepare('INSERT INTO projects (name, folder_name, purpose, project_type) VALUES (?, ?, ?, ?)');
        $stmt->execute([$name, $folder, $purpose, $type]);
        return $this->project((int)$this->pdo->lastInsertId());
    }

    public function renameProject(int $id, string $name, ?string $folder = null, ?string $formerName = null): ?array
    {
        $name = trim($name);
        if ($name === '' || strlen($name) > 120) {
            throw new InvalidArgumentException('Project name is required.');
        }
        if (!$this->project($id)) {
            return null;
        }
        $sets = ['name = ?'];
        $values = [$name];
        if ($folder !== null) {
            $sets[] = 'folder_name = ?';
            $values[] = $folder;
        }
        if ($formerName !== null) {
            $sets[] = 'former_name = ?';
            $values[] = $formerName;
        }
        $values[] = $id;
        $this->pdo->prepare('UPDATE projects SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($values);
        return $this->project($id);
    }

    public function deleteProject(int $id): ?array
    {
        $existing = $this->project($id);
        if (!$existing) {
            return null;
        }
        $this->pdo->prepare('DELETE FROM jobs WHERE project_id = ?')->execute([$id]);
        $this->pdo->prepare('DELETE FROM projects WHERE id = ?')->execute([$id]);
        return $existing;
    }

    public function tasks(?int $projectId = null): array
    {
        if ($projectId) {
            $stmt = $this->pdo->prepare('SELECT * FROM tasks WHERE project_id = ? ORDER BY id DESC');
            $stmt->execute([$projectId]);
            $rows = $stmt->fetchAll();
        } else {
            $rows = $this->pdo->query('SELECT * FROM tasks ORDER BY id DESC')->fetchAll();
        }
        return array_map([$this, 'castTask'], $rows);
    }

    public function task(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM tasks WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ? $this->castTask($row) : null;
    }

    public function insertTask(array $fields): array
    {
        $stmt = $this->pdo->prepare('INSERT INTO tasks (project_id, title, description, status, assigned_agent, schedule_time, recurrence_interval) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([
            $fields['project_id'],
            $fields['title'],
            $fields['description'] ?? '',
            $fields['status'] ?? 'todo',
            $fields['assigned_agent'] ?? 'orchestrator',
            $fields['schedule_time'] ?? null,
            $fields['recurrence_interval'] ?? 'none',
        ]);
        return $this->task((int)$this->pdo->lastInsertId());
    }

    public function updateTask(int $id, array $fields): ?array
    {
        $allowed = ['title', 'description', 'status', 'assigned_agent', 'schedule_time', 'recurrence_interval', 'project_id', 'result', 'finished_at', 'held'];
        $sets = [];
        $values = [];
        foreach ($allowed as $key) {
            if (!array_key_exists($key, $fields)) {
                continue;
            }
            $sets[] = $key . ' = ?';
            $values[] = $fields[$key];
        }
        if ($sets) {
            $values[] = $id;
            $this->pdo->prepare('UPDATE tasks SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($values);
        }
        return $this->task($id);
    }

    public function deleteTask(int $id): bool
    {
        if (!$this->task($id)) {
            return false;
        }
        $this->pdo->prepare("DELETE FROM jobs WHERE kind = 'task' AND ref_id = ?")->execute([$id]);
        $this->pdo->prepare('DELETE FROM tasks WHERE id = ?')->execute([$id]);
        return true;
    }

    public function deleteFinishedTasks(int $projectId): int
    {
        if ($projectId <= 0) {
            return 0;
        }
        $this->pdo->prepare("DELETE FROM jobs WHERE kind = 'task' AND ref_id IN (SELECT id FROM tasks WHERE project_id = ? AND status = 'done')")->execute([$projectId]);
        $stmt = $this->pdo->prepare("DELETE FROM tasks WHERE project_id = ? AND status = 'done'");
        $stmt->execute([$projectId]);
        return $stmt->rowCount();
    }

    public function dueTasks(): array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM tasks WHERE project_id IS NOT NULL AND status IN ('todo', 'backlog', 'in_progress') AND COALESCE(held, 0) = 0 AND id NOT IN (SELECT ref_id FROM jobs WHERE kind = 'task' AND status IN ('running', 'error') AND ref_id IS NOT NULL) AND ((schedule_time IS NOT NULL AND schedule_time <= ?) OR (schedule_time IS NULL AND status = 'in_progress' AND COALESCE(recurrence_interval, 'none') NOT IN ('', 'none')) OR (schedule_time IS NULL AND status = 'in_progress' AND COALESCE(recurrence_interval, 'none') IN ('', 'none') AND project_id NOT IN (SELECT project_id FROM jobs WHERE project_id IS NOT NULL AND kind IN ('chat', 'subagent') AND updated_at >= datetime('now', '-5 minutes')))) ORDER BY schedule_time");
        $stmt->execute([date('Y-m-d H:i:s')]);
        $rows = $stmt->fetchAll();
        $tasks = array_map([$this, 'castTask'], $rows);
        return array_values(array_filter($tasks, function (array $task): bool {
            return !$this->heartbeatPaused((int)($task['project_id'] ?? 0));
        }));
    }

    public function pauseHeartbeat(int $projectId): void
    {
        if ($projectId <= 0) {
            return;
        }
        $this->setSetting($this->heartbeatPauseKey($projectId), '1');
    }

    public function heartbeatPaused(int $projectId): bool
    {
        if ($projectId <= 0) {
            return false;
        }
        return $this->getSetting($this->heartbeatPauseKey($projectId), '0') === '1';
    }

    public function resumeHeartbeat(int $projectId): void
    {
        if ($projectId <= 0) {
            return;
        }
        $this->setSetting($this->heartbeatPauseKey($projectId), '0');
    }

    private function heartbeatPauseKey(int $projectId): string
    {
        return 'heartbeat_paused_' . max(0, $projectId);
    }

    public function messages(int $projectId, int $limit = 100): array
    {
        if ($projectId <= 0) {
            $stmt = $this->pdo->prepare('SELECT * FROM messages WHERE project_id IS NULL ORDER BY id DESC LIMIT ?');
            $stmt->bindValue(1, $limit, PDO::PARAM_INT);
        } else {
            $stmt = $this->pdo->prepare('SELECT * FROM messages WHERE project_id = ? ORDER BY id DESC LIMIT ?');
            $stmt->bindValue(1, $projectId, PDO::PARAM_INT);
            $stmt->bindValue(2, $limit, PDO::PARAM_INT);
        }
        $stmt->execute();
        return array_map([$this, 'castMessage'], array_reverse($stmt->fetchAll()));
    }

    public function addMessage(int $projectId, string $role, string $content, ?string $agent = null, bool $failed = false, ?string $detail = null): array
    {
        $stmt = $this->pdo->prepare('INSERT INTO messages (project_id, role, agent_name, content, failed, detail) VALUES (?, ?, ?, ?, ?, ?)');
        $stmt->execute([$projectId > 0 ? $projectId : null, $role, $agent, $content, $failed ? 1 : 0, $detail]);
        $id = (int)$this->pdo->lastInsertId();
        $row = $this->pdo->prepare('SELECT * FROM messages WHERE id = ?');
        $row->execute([$id]);
        return $this->castMessage($row->fetch());
    }

    public function deleteFailedMessage(int $projectId, int $id): void
    {
        if ($id <= 0) {
            return;
        }
        if ($projectId > 0) {
            $stmt = $this->pdo->prepare("DELETE FROM messages WHERE id = ? AND project_id = ? AND role = 'assistant' AND (failed = 1 OR content = 'No response.')");
            $stmt->execute([$id, $projectId]);
            return;
        }
        $stmt = $this->pdo->prepare("DELETE FROM messages WHERE id = ? AND project_id IS NULL AND role = 'assistant' AND (failed = 1 OR content = 'No response.')");
        $stmt->execute([$id]);
    }

    public function clearMessages(int $projectId): void
    {
        if ($projectId > 0) {
            $this->pdo->prepare('DELETE FROM messages WHERE project_id = ?')->execute([$projectId]);
            return;
        }
        $this->pdo->prepare('DELETE FROM messages WHERE project_id IS NULL')->execute();
    }

    public function deleteLatestFailed(int $projectId): void
    {
        if ($projectId > 0) {
            $this->pdo->prepare("DELETE FROM messages WHERE id = (SELECT id FROM messages WHERE project_id = ? AND role = 'assistant' AND (failed = 1 OR content = 'No response.') ORDER BY id DESC LIMIT 1)")->execute([$projectId]);
            return;
        }
        $this->pdo->prepare("DELETE FROM messages WHERE id = (SELECT id FROM messages WHERE project_id IS NULL AND role = 'assistant' AND (failed = 1 OR content = 'No response.') ORDER BY id DESC LIMIT 1)")->execute();
    }

    public function hasRunningJob(int $projectId): bool
    {
        if ($projectId > 0) {
            $stmt = $this->pdo->prepare("SELECT 1 FROM jobs WHERE project_id = ? AND status = 'running' AND updated_at >= datetime('now', '-5 minutes') LIMIT 1");
            $stmt->execute([$projectId]);
        } else {
            $stmt = $this->pdo->query("SELECT 1 FROM jobs WHERE project_id IS NULL AND status = 'running' AND updated_at >= datetime('now', '-5 minutes') LIMIT 1");
        }
        return (bool)$stmt->fetchColumn();
    }

    public function modelInUse(): bool
    {
        $stmt = $this->pdo->query("SELECT 1 FROM jobs WHERE kind IN ('chat', 'subagent', 'task') AND status = 'running' AND updated_at >= datetime('now', '-5 minutes') LIMIT 1");
        return (bool)$stmt->fetchColumn();
    }

    public function taskRunning(): bool
    {
        $stmt = $this->pdo->query("SELECT 1 FROM jobs WHERE kind = 'task' AND status = 'running' AND updated_at >= datetime('now', '-5 minutes') LIMIT 1");
        return (bool)$stmt->fetchColumn();
    }

    public function startJob(string $kind, ?int $projectId, array $payload, ?int $refId = null): int
    {
        $projectId = $projectId && $projectId > 0 ? $projectId : null;
        if ($kind === 'chat') {
            if ($projectId) {
                $close = $this->pdo->prepare("UPDATE jobs SET status = 'done', updated_at = datetime('now') WHERE kind = 'chat' AND project_id = ? AND status IN ('running', 'error')");
                $close->execute([$projectId]);
            } else {
                $close = $this->pdo->prepare("UPDATE jobs SET status = 'done', updated_at = datetime('now') WHERE kind = 'chat' AND project_id IS NULL AND status IN ('running', 'error')");
                $close->execute();
            }
            $closed = $close->rowCount();
            if ($closed > 0) {
                $this->loopWrite(null, $projectId, 'heartbeat', 'process', 'Closed ' . $closed . ' earlier chat ' . ($closed === 1 ? 'job' : 'jobs'));
            }
        }
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $stmt = $this->pdo->prepare("INSERT INTO jobs (kind, ref_id, project_id, status, attempts, payload, updated_at) VALUES (?, ?, ?, 'running', 1, ?, datetime('now'))");
        $stmt->execute([$kind, $refId, $projectId, is_string($json) ? $json : '{}']);
        return (int)$this->pdo->lastInsertId();
    }

    public function touchJob(int $id): void
    {
        static $last = [];
        $now = microtime(true);
        if (isset($last[$id]) && ($now - $last[$id]) < 30) {
            return;
        }
        $last[$id] = $now;
        $stmt = $this->pdo->prepare("UPDATE jobs SET updated_at = datetime('now') WHERE id = ? AND status = 'running'");
        $stmt->execute([$id]);
        if ($stmt->rowCount() < 1) {
            return;
        }
        $job = $this->job($id);
        if (!$job) {
            return;
        }
        $project = $job['project_id'] !== null ? (int)$job['project_id'] : null;
        $this->loopWrite($id, $project, $this->loopJobAgent($job), 'process', 'Job ' . $id . ' alive · ' . $job['kind'] . ' · attempt ' . (int)$job['attempts']);
    }

    public function finishJob(int $id): void
    {
        $this->pdo->prepare("UPDATE jobs SET status = 'done', last_error = NULL, updated_at = datetime('now') WHERE id = ?")->execute([$id]);
    }

    public function finishRunningJobs(): void
    {
        $this->pdo->exec("UPDATE jobs SET status = 'done', updated_at = datetime('now') WHERE status = 'running'");
    }

    public function bumpJob(int $id): void
    {
        $this->pdo->prepare("UPDATE jobs SET attempts = attempts + 1, status = 'running', updated_at = datetime('now') WHERE id = ?")->execute([$id]);
    }

    public function failJob(int $id, string $error, int $maxAttempts): void
    {
        $job = $this->job($id);
        if ($job && $this->runStopRequested($job['project_id'] !== null ? (int)$job['project_id'] : 0)) {
            $this->closeStoppedJob($job);
            return;
        }
        $stmt = $this->pdo->prepare('SELECT attempts FROM jobs WHERE id = ?');
        $stmt->execute([$id]);
        $attempts = (int)$stmt->fetchColumn();
        $status = $attempts >= $maxAttempts ? 'gave_up' : 'error';
        $this->pdo->prepare("UPDATE jobs SET status = ?, last_error = ?, updated_at = datetime('now') WHERE id = ?")->execute([$status, clip_text($error, 2000), $id]);
    }

    public function job(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM jobs WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function dueJobs(int $staleSeconds, int $maxAttempts): array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM jobs WHERE status IN ('running', 'error') AND attempts < ? AND updated_at <= datetime('now', ?) ORDER BY id");
        $stmt->execute([$maxAttempts, '-' . $staleSeconds . ' seconds']);
        return $stmt->fetchAll();
    }

    public function taskRanSince(int $taskId, ?string $scheduleTime): bool
    {
        if ($taskId <= 0) {
            return false;
        }
        $stmt = $this->pdo->prepare("SELECT updated_at FROM jobs WHERE kind = 'task' AND ref_id = ? AND status IN ('done', 'gave_up') ORDER BY id DESC LIMIT 1");
        $stmt->execute([$taskId]);
        $updated = $stmt->fetchColumn();
        if (!is_string($updated) || $updated === '') {
            return false;
        }
        if ($scheduleTime === null || trim($scheduleTime) === '') {
            return true;
        }
        $slot = strtotime($scheduleTime);
        $at = strtotime($updated . ' UTC');
        return $slot !== false && $at !== false && $at >= $slot;
    }

    public function scheduledAnswerSaved(int $projectId, int $taskId, string $title): bool
    {
        if ($projectId <= 0 || $taskId <= 0) {
            return false;
        }
        $idNeedle = 'Scheduled task: #' . $taskId . ' ';
        $title = trim($title);
        $titleNeedle = $title !== '' ? 'Scheduled task: ' . $title : '';
        $stmt = $this->pdo->prepare("SELECT id, content FROM messages WHERE project_id = ? AND role = 'user' ORDER BY id DESC LIMIT 80");
        $stmt->execute([$projectId]);
        $promptId = 0;
        foreach ($stmt->fetchAll() as $row) {
            $content = (string)$row['content'];
            if (str_starts_with($content, $idNeedle)) {
                $promptId = (int)$row['id'];
                break;
            }
            if ($titleNeedle !== '' && str_starts_with($content, $titleNeedle)) {
                $after = strlen($titleNeedle);
                if (strlen($content) === $after || $content[$after] === "\n") {
                    $promptId = (int)$row['id'];
                    break;
                }
            }
        }
        if ($promptId <= 0) {
            return false;
        }
        $next = $this->pdo->prepare('SELECT role, failed, content FROM messages WHERE project_id = ? AND id > ? ORDER BY id ASC LIMIT 20');
        $next->execute([$projectId, $promptId]);
        foreach ($next->fetchAll() as $row) {
            $role = (string)$row['role'];
            if ($role === 'notice') {
                continue;
            }
            if ($role !== 'assistant') {
                return false;
            }
            if ((int)($row['failed'] ?? 0) === 1 || trim((string)($row['content'] ?? '')) === 'No response.') {
                return false;
            }
            return true;
        }
        return false;
    }

    public function freshJobs(int $staleSeconds): array
    {
        $stmt = $this->pdo->prepare("SELECT id, kind, project_id, payload, updated_at FROM jobs WHERE status = 'running' AND updated_at >= datetime('now', ?) ORDER BY updated_at DESC, id DESC");
        $stmt->execute(['-' . $staleSeconds . ' seconds']);
        $rows = [];
        foreach ($stmt->fetchAll() as $row) {
            $payload = json_decode((string)($row['payload'] ?? ''), true);
            $row['id'] = (int)$row['id'];
            $row['project_id'] = $row['project_id'] !== null ? (int)$row['project_id'] : null;
            $row['payload'] = is_array($payload) ? $payload : [];
            $rows[] = $row;
        }
        return $rows;
    }

    public function lockFresh(string $key, int $staleSeconds): bool
    {
        $stamp = (int)$this->getSetting($key, '0');
        return $stamp !== 0 && (time() - $stamp) < $staleSeconds;
    }

    public function latestJobProject(string $agent): ?int
    {
        if ($agent === 'orchestrator' || $agent === 'consultant') {
            $value = $this->pdo->query("SELECT project_id FROM jobs WHERE kind IN ('chat', 'task') ORDER BY id DESC LIMIT 1")->fetchColumn();
            return $value !== false && $value !== null ? (int)$value : null;
        }
        $stmt = $this->pdo->query("SELECT project_id, payload FROM jobs WHERE kind = 'subagent' ORDER BY id DESC");
        foreach ($stmt->fetchAll() as $row) {
            $payload = json_decode((string)($row['payload'] ?? ''), true);
            if (is_array($payload) && (string)($payload['agent'] ?? '') === $agent) {
                return $row['project_id'] !== null ? (int)$row['project_id'] : null;
            }
        }
        return null;
    }

    public function skills(): array
    {
        return array_map([$this, 'castSkill'], $this->pdo->query('SELECT * FROM skills ORDER BY name COLLATE NOCASE')->fetchAll());
    }

    public function skill(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM skills WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ? $this->castSkill($row) : null;
    }

    public function skillByName(string $name): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM skills WHERE name = ?');
        $stmt->execute([$name]);
        $row = $stmt->fetch();
        return $row ? $this->castSkill($row) : null;
    }

    public function saveSkill(string $name, string $description, string $scriptPath, bool $active, string $createdBy = 'user'): array
    {
        $existing = $this->skillByName($name);
        if ($existing) {
            $stmt = $this->pdo->prepare('UPDATE skills SET description = ?, script_path = ?, is_active = ? WHERE id = ?');
            $stmt->execute([$description, $scriptPath, $active ? 1 : 0, $existing['id']]);
            return $this->skill($existing['id']);
        }
        $stmt = $this->pdo->prepare('INSERT INTO skills (name, description, script_path, is_active, created_by) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute([$name, $description, $scriptPath, $active ? 1 : 0, $createdBy]);
        return $this->skill((int)$this->pdo->lastInsertId());
    }

    public function setSkillActive(int $id, bool $active): ?array
    {
        $this->pdo->prepare('UPDATE skills SET is_active = ? WHERE id = ?')->execute([$active ? 1 : 0, $id]);
        return $this->skill($id);
    }

    public function deleteSkill(int $id): void
    {
        $this->pdo->prepare('DELETE FROM skills WHERE id = ?')->execute([$id]);
    }

    public function logAgent(string $agent, ?int $projectId, string $type, string $content): void
    {
        $projectId = $projectId && $projectId > 0 ? $projectId : null;
        $stmt = $this->pdo->prepare('INSERT INTO agent_logs (agent_name, project_id, log_type, content) VALUES (?, ?, ?, ?)');
        $stmt->execute([$agent, $projectId, $type, clip_text($content, 8000)]);
        $this->trimAgentLogs($agent, $projectId);
    }

    public function logs(int $afterId = 0, string $agent = '', ?int $projectId = null, int $limit = 100): array
    {
        $projectId = $projectId && $projectId > 0 ? $projectId : null;
        $limit = max(1, min(100, $limit));
        if ($agent !== '') {
            $this->trimAgentLogs($agent, $projectId);
        } else {
            $this->trimProjectLogs($projectId);
        }
        $sql = 'SELECT * FROM agent_logs WHERE id > ?';
        $values = [$afterId];
        if ($projectId === null) {
            $sql .= ' AND (project_id IS NULL OR project_id = 0)';
        } else {
            $sql .= ' AND project_id = ?';
            $values[] = $projectId;
        }
        if ($agent !== '') {
            $sql .= ' AND agent_name = ?';
            $values[] = $agent;
        }
        $sql .= ' ORDER BY id DESC LIMIT ?';
        $stmt = $this->pdo->prepare($sql);
        $index = 1;
        foreach ($values as $value) {
            $stmt->bindValue($index, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
            $index++;
        }
        $stmt->bindValue($index, $limit, PDO::PARAM_INT);
        $stmt->execute();
        return array_map([$this, 'castLog'], array_reverse($stmt->fetchAll()));
    }

    private function trimProjectLogs(?int $projectId): void
    {
        if ($projectId === null) {
            $names = $this->pdo->query('SELECT DISTINCT agent_name FROM agent_logs WHERE project_id IS NULL OR project_id = 0')->fetchAll(PDO::FETCH_COLUMN);
        } else {
            $stmt = $this->pdo->prepare('SELECT DISTINCT agent_name FROM agent_logs WHERE project_id = ?');
            $stmt->execute([$projectId]);
            $names = $stmt->fetchAll(PDO::FETCH_COLUMN);
        }
        foreach ($names as $name) {
            if (is_string($name) && $name !== '') {
                $this->trimAgentLogs($name, $projectId);
            }
        }
    }

    private function trimAgentLogs(string $agent, ?int $projectId): void
    {
        if ($projectId === null) {
            $sql = 'DELETE FROM agent_logs WHERE agent_name = ? AND (project_id IS NULL OR project_id = 0) AND id < COALESCE((SELECT id FROM agent_logs WHERE agent_name = ? AND (project_id IS NULL OR project_id = 0) ORDER BY id DESC LIMIT 1 OFFSET 99), 0)';
            $this->pdo->prepare($sql)->execute([$agent, $agent]);
            return;
        }
        $sql = 'DELETE FROM agent_logs WHERE agent_name = ? AND project_id = ? AND id < COALESCE((SELECT id FROM agent_logs WHERE agent_name = ? AND project_id = ? ORDER BY id DESC LIMIT 1 OFFSET 99), 0)';
        $this->pdo->prepare($sql)->execute([$agent, $projectId, $agent, $projectId]);
    }

    public function loopPush(?int $jobId, ?int $projectId): void
    {
        $this->loopScope[] = [
            'job_id' => $jobId && $jobId > 0 ? $jobId : null,
            'project_id' => $projectId && $projectId > 0 ? $projectId : null,
        ];
    }

    public function loopPop(): void
    {
        array_pop($this->loopScope);
    }

    public function loop(string $agent, string $kind, string $line, ?int $projectId = null): void
    {
        $jobId = null;
        $scoped = null;
        if ($this->loopScope !== []) {
            $scope = $this->loopScope[count($this->loopScope) - 1];
            $jobId = $scope['job_id'];
            $scoped = $scope['project_id'];
        }
        if ($projectId === null) {
            $projectId = $scoped;
        }
        $this->loopWrite($jobId, $projectId, $agent, $kind, $line);
    }

    public function loopWrite(?int $jobId, ?int $projectId, string $agent, string $kind, string $line): void
    {
        $projectId = $projectId && $projectId > 0 ? $projectId : null;
        $jobId = $jobId && $jobId > 0 ? $jobId : null;
        $agent = trim($agent);
        if ($agent === '') {
            $agent = 'heartbeat';
        }
        $kind = trim($kind);
        if ($kind === '') {
            $kind = 'process';
        }
        $line = preg_replace('/\s+/', ' ', trim($line)) ?? '';
        if ($line === '') {
            return;
        }
        if (mb_strlen($line) > 160) {
            $line = rtrim(mb_substr($line, 0, 159)) . '…';
        }
        $stmt = $this->pdo->prepare('INSERT INTO loop_logs (job_id, project_id, agent_name, kind, line) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute([$jobId, $projectId, $agent, $kind, $line]);
        $this->trimLoopLogs($projectId);
    }

    public function loopLogs(?int $projectId = null, int $limit = 400): array
    {
        $projectId = $projectId && $projectId > 0 ? $projectId : null;
        $limit = max(1, min(500, $limit));
        if ($projectId === null) {
            $stmt = $this->pdo->prepare('SELECT * FROM loop_logs WHERE project_id IS NULL OR project_id = 0 ORDER BY id DESC LIMIT ?');
            $stmt->bindValue(1, $limit, PDO::PARAM_INT);
        } else {
            $stmt = $this->pdo->prepare('SELECT * FROM loop_logs WHERE project_id = ? ORDER BY id DESC LIMIT ?');
            $stmt->bindValue(1, $projectId, PDO::PARAM_INT);
            $stmt->bindValue(2, $limit, PDO::PARAM_INT);
        }
        $stmt->execute();
        return array_map([$this, 'castLoopLog'], array_reverse($stmt->fetchAll()));
    }

    public function loopLogText(?int $projectId, string $projectName): string
    {
        $projectId = $projectId && $projectId > 0 ? $projectId : null;
        $limit = 5000;
        if ($projectId === null) {
            $stmt = $this->pdo->prepare('SELECT * FROM loop_logs WHERE project_id IS NULL OR project_id = 0 ORDER BY id ASC LIMIT ?');
            $stmt->bindValue(1, $limit, PDO::PARAM_INT);
        } else {
            $stmt = $this->pdo->prepare('SELECT * FROM loop_logs WHERE project_id = ? ORDER BY id ASC LIMIT ?');
            $stmt->bindValue(1, $projectId, PDO::PARAM_INT);
            $stmt->bindValue(2, $limit, PDO::PARAM_INT);
        }
        $stmt->execute();
        $rows = array_map([$this, 'castLoopLog'], $stmt->fetchAll());
        $name = trim($projectName) !== '' ? trim($projectName) : 'General chat';
        $lines = [
            '# Loop trace',
            '# Project: ' . $name,
            '# Lines: ' . count($rows),
            '# id	time	job	agent	kind	line',
        ];
        foreach ($rows as $row) {
            $job = $row['job_id'] !== null ? (string)$row['job_id'] : '-';
            $lines[] = $row['id'] . "\t" . $row['created_at'] . "\t" . $job . "\t" . $row['agent_name'] . "\t" . $row['kind'] . "\t" . $row['line'];
        }
        if ($rows === []) {
            $lines[] = '# No loop trace yet.';
        }
        return implode("\n", $lines) . "\n";
    }

    private function trimLoopLogs(?int $projectId): void
    {
        if ($projectId === null) {
            $sql = 'DELETE FROM loop_logs WHERE (project_id IS NULL OR project_id = 0) AND id < COALESCE((SELECT id FROM loop_logs WHERE project_id IS NULL OR project_id = 0 ORDER BY id DESC LIMIT 1 OFFSET 4999), 0)';
            $this->pdo->exec($sql);
            return;
        }
        $sql = 'DELETE FROM loop_logs WHERE project_id = ? AND id < COALESCE((SELECT id FROM loop_logs WHERE project_id = ? ORDER BY id DESC LIMIT 1 OFFSET 4999), 0)';
        $this->pdo->prepare($sql)->execute([$projectId, $projectId]);
    }

    private function loopJobAgent(array $job): string
    {
        $kind = (string)($job['kind'] ?? '');
        if ($kind === 'subagent') {
            $payload = json_decode((string)($job['payload'] ?? ''), true);
            $agent = is_array($payload) ? trim((string)($payload['agent'] ?? '')) : '';
            return $agent !== '' ? $agent : 'subagent';
        }
        return 'orchestrator';
    }

    public function setAgentStatus(string $agent, string $status): void
    {
        $all = $this->agentStatus();
        $all[$agent] = $status;
        $this->setSetting('agent_status', json_encode($all));
    }

    public function agentStatus(): array
    {
        $decoded = json_decode((string)$this->getSetting('agent_status', '{}'), true);
        if (!is_array($decoded)) {
            $decoded = [];
        }
        return array_merge(self::idleStatus(), $decoded);
    }

    public function recordLogin(string $ip, bool $success): void
    {
        $this->pdo->prepare("DELETE FROM login_attempts WHERE attempted_at < datetime('now', '-1 day')")->execute();
        $this->pdo->prepare('INSERT INTO login_attempts (ip, success) VALUES (?, ?)')->execute([$ip, $success ? 1 : 0]);
    }

    public function failedLogins(string $ip): int
    {
        $seconds = (int)app_config()['login_window_seconds'];
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND success = 0 AND attempted_at >= datetime('now', ?)");
        $stmt->execute([$ip, '-' . $seconds . ' seconds']);
        return (int)$stmt->fetchColumn();
    }

    public function clearLogins(string $ip): void
    {
        $this->pdo->prepare('DELETE FROM login_attempts WHERE ip = ?')->execute([$ip]);
    }

    public function tryLock(string $key, int $seconds): bool
    {
        $this->pdo->beginTransaction();
        try {
            $current = $this->getSetting($key, '0');
            if ((int)$current !== 0 && (time() - (int)$current) < $seconds) {
                $this->pdo->commit();
                return false;
            }
            $this->setSetting($key, (string)time());
            $this->pdo->commit();
            return true;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function unlock(string $key): void
    {
        $this->setSetting($key, '0');
    }

    public function beginChat(int $projectId, string $generation): void
    {
        $pending = (string)$this->getSetting($this->chatStopGenKey($projectId), '');
        $this->setSetting($this->chatGenKey($projectId), $generation);
        if ($pending !== '' && hash_equals($pending, $generation)) {
            $this->setSetting($this->chatStopKey($projectId), '1');
            return;
        }
        $this->setSetting($this->chatStopKey($projectId), '0');
    }

    public function requestChatStop(int $projectId, string $generation): void
    {
        $generation = trim($generation);
        if ($generation === '') {
            return;
        }
        $this->setSetting($this->chatStopGenKey($projectId), $generation);
        $current = (string)$this->getSetting($this->chatGenKey($projectId), '');
        if ($current !== '' && hash_equals($current, $generation)) {
            $this->setSetting($this->chatStopKey($projectId), '1');
        }
    }

    public function clearChatStop(int $projectId): void
    {
        $this->setSetting($this->chatStopKey($projectId), '0');
    }

    public function chatStopRequested(int $projectId): bool
    {
        return $this->getSetting($this->chatStopKey($projectId), '0') === '1';
    }

    public function chatGeneration(int $projectId): string
    {
        return (string)$this->getSetting($this->chatGenKey($projectId), '');
    }

    public function markChatFollowup(int $projectId, int $jobId): void
    {
        if ($jobId <= 0) {
            return;
        }
        $this->setSetting($this->chatFollowupKey($projectId), (string)$jobId);
    }

    public function takeChatFollowup(int $projectId, int $jobId): bool
    {
        if ($jobId <= 0) {
            return false;
        }
        $key = $this->chatFollowupKey($projectId);
        $on = (int)$this->getSetting($key, '0') === $jobId;
        if ($on) {
            $this->setSetting($key, '0');
        }
        return $on;
    }

    public function markChatAnswerSaved(int $jobId): void
    {
        if ($jobId <= 0) {
            return;
        }
        $this->setSetting($this->chatAnswerSavedKey($jobId), '1');
    }

    public function chatAnswerSaved(int $jobId): bool
    {
        if ($jobId <= 0) {
            return false;
        }
        return $this->getSetting($this->chatAnswerSavedKey($jobId), '0') === '1';
    }

    public function runningChatJob(int $projectId): ?array
    {
        if ($projectId > 0) {
            $stmt = $this->pdo->prepare("SELECT * FROM jobs WHERE kind = 'chat' AND project_id = ? AND status = 'running' ORDER BY id DESC LIMIT 1");
            $stmt->execute([$projectId]);
        } else {
            $stmt = $this->pdo->query("SELECT * FROM jobs WHERE kind = 'chat' AND project_id IS NULL AND status = 'running' ORDER BY id DESC LIMIT 1");
        }
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function stopAllChats(): void
    {
        $ids = [0];
        foreach ($this->pdo->query('SELECT id FROM projects') as $row) {
            $ids[] = (int)$row['id'];
        }
        foreach ($ids as $id) {
            $this->setSetting($this->chatStopKey($id), '1');
        }
    }

    private function chatStopKey(int $projectId): string
    {
        return 'chat_stop_' . max(0, $projectId);
    }

    private function chatGenKey(int $projectId): string
    {
        return 'chat_gen_' . max(0, $projectId);
    }

    private function chatStopGenKey(int $projectId): string
    {
        return 'chat_stop_gen_' . max(0, $projectId);
    }

    private function chatFollowupKey(int $projectId): string
    {
        return 'chat_followup_' . max(0, $projectId);
    }

    private function chatAnswerSavedKey(int $jobId): string
    {
        return 'chat_answer_saved_' . $jobId;
    }

    public function requestRunStop(int $projectId, array $jobIds): void
    {
        $ids = [];
        foreach ($jobIds as $jobId) {
            $jobId = (int)$jobId;
            if ($jobId > 0) {
                $ids[$jobId] = $jobId;
            }
        }
        $ids = array_values($ids);
        if (!$ids) {
            if ($this->runStopRequested($projectId)) {
                return;
            }
            $this->setSetting($this->runStopJobsKey($projectId), '');
            $this->setSetting($this->runStopKey($projectId), '0');
            return;
        }
        $this->setSetting($this->runStopJobsKey($projectId), implode(',', $ids));
        $this->setSetting($this->runStopKey($projectId), (string)time());
    }

    public function runStopRequested(int $projectId): bool
    {
        return $this->runStopAge($projectId) !== null;
    }

    public function jobIsRunning(int $id): bool
    {
        $job = $this->job($id);
        return is_array($job) && (string)($job['status'] ?? '') === 'running';
    }

    public function runStillStopping(int $projectId): bool
    {
        $age = $this->runStopAge($projectId);
        if ($age === null) {
            return false;
        }
        foreach ($this->runStopJobIds($projectId) as $id) {
            if (!$this->jobIsRunning($id)) {
                continue;
            }
            $job = $this->job($id);
            $updated = strtotime((string)($job['updated_at'] ?? '') . ' UTC');
            if ($updated !== false && (time() - $updated) < 90) {
                return true;
            }
        }
        if ($age < 2) {
            return true;
        }
        foreach ($this->runStopJobIds($projectId) as $id) {
            if ($this->jobIsRunning($id)) {
                $this->finishJob($id);
            }
        }
        $this->clearRunStop($projectId);
        return false;
    }

    public function runStopAge(int $projectId): ?int
    {
        $value = (string)$this->getSetting($this->runStopKey($projectId), '0');
        if ($value === '0' || $value === '' || !ctype_digit($value)) {
            return null;
        }
        return max(0, time() - (int)$value);
    }

    public function acknowledgeRunStop(int $projectId, int $jobId): void
    {
        if (!$this->runStopRequested($projectId)) {
            return;
        }
        $ids = $this->runStopJobIds($projectId);
        if ($jobId > 0) {
            $ids = array_values(array_diff($ids, [$jobId]));
        }
        $left = [];
        foreach ($ids as $id) {
            if ($this->jobIsRunning($id)) {
                $left[] = $id;
            }
        }
        if ($left) {
            $this->setSetting($this->runStopJobsKey($projectId), implode(',', $left));
            return;
        }
        $this->clearRunStop($projectId);
    }

    public function clearRunStop(int $projectId): void
    {
        $this->setSetting($this->runStopJobsKey($projectId), '');
        $this->setSetting($this->runStopKey($projectId), '0');
    }

    public function runningJobs(int $projectId): array
    {
        if ($projectId > 0) {
            $stmt = $this->pdo->prepare("SELECT * FROM jobs WHERE project_id = ? AND status IN ('running', 'error')");
            $stmt->execute([$projectId]);
        } else {
            $stmt = $this->pdo->query("SELECT * FROM jobs WHERE project_id IS NULL AND status IN ('running', 'error')");
        }
        return $stmt->fetchAll();
    }

    public function anyRunningJob(): bool
    {
        $stmt = $this->pdo->query("SELECT id FROM jobs WHERE status = 'running' LIMIT 1");
        return $stmt !== false && $stmt->fetch() !== false;
    }

    public function closeStoppedJob(array $job): void
    {
        $projectId = $job['project_id'] !== null ? (int)$job['project_id'] : 0;
        if ((string)($job['kind'] ?? '') === 'task') {
            $taskId = (int)($job['ref_id'] ?? 0);
            if ($taskId <= 0) {
                $payload = $job['payload'] ?? null;
                if (is_string($payload)) {
                    $payload = json_decode($payload, true);
                }
                if (is_array($payload)) {
                    $taskId = (int)($payload['task_id'] ?? 0);
                }
            }
            if ($taskId > 0) {
                $this->updateTask($taskId, ['held' => 1]);
            }
        }
        $id = (int)($job['id'] ?? 0);
        if ($id > 0) {
            $this->finishJob($id);
            $this->acknowledgeRunStop($projectId, $id);
        }
    }

    private function runStopKey(int $projectId): string
    {
        return 'run_stop_' . max(0, $projectId);
    }

    private function runStopJobsKey(int $projectId): string
    {
        return 'run_stop_jobs_' . max(0, $projectId);
    }

    private function runStopJobIds(int $projectId): array
    {
        $raw = (string)$this->getSetting($this->runStopJobsKey($projectId), '');
        if ($raw === '') {
            return [];
        }
        $ids = [];
        foreach (explode(',', $raw) as $part) {
            $id = (int)$part;
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }
        return array_values($ids);
    }

    public function answerInstall(string $id, bool $allow): bool
    {
        return $this->answerAction($id, $allow);
    }

    public function hitlExternalActions(): bool
    {
        return $this->getSetting('hitl_external_actions', '0') === '1';
    }

    public function complianceLogging(): bool
    {
        return $this->getSetting('compliance_logging', '0') === '1';
    }

    public function beginActionAsk(string $kind, string $summary, ?int $projectId = null): string
    {
        $id = bin2hex(random_bytes(8));
        $this->setSetting('action_ask_' . $id, '');
        $meta = json_encode([
            'kind' => $kind,
            'summary' => $summary,
            'project_id' => $projectId,
            'created_at' => gmdate('Y-m-d H:i:s'),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->setSetting('action_meta_' . $id, $meta === false ? '{}' : $meta);
        return $id;
    }

    public function answerAction(string $id, bool $allow): bool
    {
        if (!preg_match('/^[a-f0-9]{16}$/', $id)) {
            return false;
        }
        $key = 'action_ask_' . $id;
        $legacy = 'install_ask_' . $id;
        if ($this->getSetting($key, null) === null && $this->getSetting($legacy, null) === null) {
            return false;
        }
        if ($this->getSetting($key, null) !== null) {
            $this->setSetting($key, $allow ? 'yes' : 'no');
        }
        if ($this->getSetting($legacy, null) !== null) {
            $this->setSetting($legacy, $allow ? 'yes' : 'no');
        }
        $metaRaw = (string)$this->getSetting('action_meta_' . $id, '');
        $meta = $metaRaw !== '' ? json_decode($metaRaw, true) : null;
        $summary = is_array($meta) ? (string)($meta['summary'] ?? 'Action') : 'Action';
        $kind = is_array($meta) ? (string)($meta['kind'] ?? 'action') : 'action';
        $projectId = is_array($meta) && isset($meta['project_id']) ? (int)$meta['project_id'] : null;
        $this->logCompliance(
            $allow ? 'action_allowed' : 'action_denied',
            'user',
            ($allow ? 'Allowed' : 'Denied') . ': ' . $summary,
            ['kind' => $kind, 'id' => $id],
            $projectId && $projectId > 0 ? $projectId : null
        );
        return true;
    }

    public function pendingActions(): array
    {
        $out = [];
        foreach ($this->allSettings() as $key => $value) {
            if (!str_starts_with($key, 'action_ask_') || $value !== '') {
                continue;
            }
            $id = substr($key, strlen('action_ask_'));
            if (!preg_match('/^[a-f0-9]{16}$/', $id)) {
                continue;
            }
            $metaRaw = (string)$this->getSetting('action_meta_' . $id, '');
            $meta = $metaRaw !== '' ? json_decode($metaRaw, true) : [];
            if (!is_array($meta)) {
                $meta = [];
            }
            $out[] = [
                'id' => $id,
                'kind' => (string)($meta['kind'] ?? 'action'),
                'summary' => (string)($meta['summary'] ?? 'Pending action'),
                'project_id' => isset($meta['project_id']) ? (int)$meta['project_id'] : null,
                'created_at' => (string)($meta['created_at'] ?? ''),
            ];
        }
        usort($out, static fn(array $a, array $b): int => strcmp((string)$b['created_at'], (string)$a['created_at']));
        return $out;
    }

    public function clearActionAsk(string $id): void
    {
        $this->pdo->prepare('DELETE FROM settings WHERE key IN (?, ?, ?)')->execute([
            'action_ask_' . $id,
            'action_meta_' . $id,
            'install_ask_' . $id,
        ]);
    }

    public function logCompliance(string $eventType, string $actor, string $summary, ?array $detail = null, ?int $projectId = null, ?int $jobId = null): void
    {
        $always = in_array($eventType, ['terms_accepted', 'action_allowed', 'action_denied'], true);
        if (!$always && !$this->complianceLogging()) {
            return;
        }
        $json = $detail === null ? null : json_encode($detail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $stmt = $this->pdo->prepare('INSERT INTO compliance_events (project_id, actor, event_type, summary, detail_json, related_job_id) VALUES (?, ?, ?, ?, ?, ?)');
        $stmt->execute([
            $projectId && $projectId > 0 ? $projectId : null,
            $actor !== '' ? $actor : 'system',
            $eventType,
            clip_text($summary, 2000),
            $json === false ? null : $json,
            $jobId,
        ]);
    }

    public function complianceEvents(int $limit = 100, int $afterId = 0): array
    {
        $limit = max(1, min(500, $limit));
        if ($afterId > 0) {
            $stmt = $this->pdo->prepare('SELECT * FROM compliance_events WHERE id > ? ORDER BY id DESC LIMIT ?');
            $stmt->bindValue(1, $afterId, PDO::PARAM_INT);
            $stmt->bindValue(2, $limit, PDO::PARAM_INT);
            $stmt->execute();
        } else {
            $stmt = $this->pdo->prepare('SELECT * FROM compliance_events ORDER BY id DESC LIMIT ?');
            $stmt->bindValue(1, $limit, PDO::PARAM_INT);
            $stmt->execute();
        }
        return $stmt->fetchAll();
    }

    public function exportComplianceEvents(): array
    {
        return $this->pdo->query('SELECT id, created_at, project_id, actor, event_type, summary, detail_json, related_job_id FROM compliance_events ORDER BY id ASC')->fetchAll();
    }

    public function purgeComplianceEvents(?int $retentionDays = null): int
    {
        $days = $retentionDays ?? (int)$this->getSetting('compliance_retention_days', '90');
        $days = max(1, min(3650, $days));
        $stmt = $this->pdo->prepare("DELETE FROM compliance_events WHERE created_at < datetime('now', ?)");
        $stmt->execute(['-' . $days . ' days']);
        return $stmt->rowCount();
    }

    public function emailAccounts(?int $projectId = null, bool $withSecret = false): array
    {
        if ($projectId) {
            $stmt = $this->pdo->prepare('SELECT * FROM email_accounts WHERE project_id = ? ORDER BY label COLLATE NOCASE');
            $stmt->execute([$projectId]);
            $rows = $stmt->fetchAll();
        } else {
            $rows = $this->pdo->query('SELECT * FROM email_accounts ORDER BY label COLLATE NOCASE')->fetchAll();
        }
        return array_map(fn(array $row): array => $this->castEmail($row, $withSecret), $rows);
    }

    public function saveEmailAccount(array $fields): array
    {
        $stmt = $this->pdo->prepare('INSERT INTO email_accounts (project_id, label, from_name, from_email, smtp_host, smtp_port, smtp_security, username, password) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([
            $fields['project_id'],
            $fields['label'],
            $fields['from_name'],
            $fields['from_email'],
            $fields['smtp_host'],
            $fields['smtp_port'],
            $fields['smtp_security'],
            $fields['username'],
            $fields['password'],
        ]);
        $id = (int)$this->pdo->lastInsertId();
        $stmt = $this->pdo->prepare('SELECT * FROM email_accounts WHERE id = ?');
        $stmt->execute([$id]);
        return $this->castEmail($stmt->fetch(), false);
    }

    public function deleteEmailAccount(int $id): void
    {
        $this->pdo->prepare('DELETE FROM email_accounts WHERE id = ?')->execute([$id]);
    }

    public function ftpAccounts(?int $projectId = null, bool $withSecret = false): array
    {
        if ($projectId) {
            $stmt = $this->pdo->prepare('SELECT * FROM ftp_accounts WHERE project_id = ? ORDER BY label COLLATE NOCASE');
            $stmt->execute([$projectId]);
            $rows = $stmt->fetchAll();
        } else {
            $rows = $this->pdo->query('SELECT * FROM ftp_accounts ORDER BY label COLLATE NOCASE')->fetchAll();
        }
        return array_map(fn(array $row): array => $this->castFtp($row, $withSecret), $rows);
    }

    public function saveFtpAccount(array $fields): array
    {
        $stmt = $this->pdo->prepare('INSERT INTO ftp_accounts (project_id, label, host, port, security, username, password) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([
            $fields['project_id'],
            $fields['label'],
            $fields['host'],
            $fields['port'],
            $fields['security'],
            $fields['username'],
            $fields['password'],
        ]);
        $id = (int)$this->pdo->lastInsertId();
        $stmt = $this->pdo->prepare('SELECT * FROM ftp_accounts WHERE id = ?');
        $stmt->execute([$id]);
        return $this->castFtp($stmt->fetch(), false);
    }

    public function deleteFtpAccount(int $id): void
    {
        $this->pdo->prepare('DELETE FROM ftp_accounts WHERE id = ?')->execute([$id]);
    }

    public function mcpServers(bool $withSecret = false): array
    {
        $rows = $this->pdo->query('SELECT * FROM mcp_servers ORDER BY label COLLATE NOCASE')->fetchAll();
        return array_map(fn(array $row): array => $this->castMcp($row, $withSecret), $rows);
    }

    public function mcpServer(int $id, bool $withSecret = false): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM mcp_servers WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ? $this->castMcp($row, $withSecret) : null;
    }

    public function saveMcpServer(array $fields): array
    {
        $stmt = $this->pdo->prepare('INSERT INTO mcp_servers (label, target, env_json) VALUES (?, ?, ?)');
        $stmt->execute([$fields['label'], $fields['target'], $fields['env_json']]);
        $saved = $this->mcpServer((int)$this->pdo->lastInsertId());
        if (!$saved) {
            throw new RuntimeException('Could not save the MCP server.');
        }
        return $saved;
    }

    public function saveMcpTools(int $id, array $tools): void
    {
        $json = json_encode(array_values($tools), JSON_UNESCAPED_SLASHES);
        $this->pdo->prepare('UPDATE mcp_servers SET tools_json = ? WHERE id = ?')->execute([$json === false ? '[]' : $json, $id]);
    }

    public function deleteMcpServer(int $id): void
    {
        $this->pdo->prepare('DELETE FROM mcp_servers WHERE id = ?')->execute([$id]);
    }

    private function castProject(array $row): array
    {
        $row['id'] = (int)$row['id'];
        return $row;
    }

    private function castTask(array $row): array
    {
        $row['id'] = (int)$row['id'];
        $row['project_id'] = $row['project_id'] !== null ? (int)$row['project_id'] : null;
        $row['held'] = (int)($row['held'] ?? 0);
        return $row;
    }

    private function castMessage(array $row): array
    {
        $row['id'] = (int)$row['id'];
        $row['project_id'] = $row['project_id'] !== null ? (int)$row['project_id'] : null;
        $row['failed'] = ((int)($row['failed'] ?? 0) === 1 || trim((string)$row['content']) === 'No response.') ? 1 : 0;
        $detail = null;
        if (!empty($row['detail'])) {
            $decoded = json_decode((string)$row['detail'], true);
            if (is_array($decoded) && (!empty($decoded['thinking']) || !empty($decoded['tools']))) {
                $detail = [
                    'thinking' => is_array($decoded['thinking'] ?? null) ? array_values($decoded['thinking']) : [],
                    'tools' => is_array($decoded['tools'] ?? null) ? array_values($decoded['tools']) : [],
                ];
            }
        }
        $row['detail'] = $detail;
        return $row;
    }

    private function castEmail(array $row, bool $withSecret): array
    {
        $row['id'] = (int)$row['id'];
        $row['project_id'] = (int)$row['project_id'];
        $row['smtp_port'] = (int)$row['smtp_port'];
        if (!$withSecret) {
            unset($row['password']);
        }
        return $row;
    }

    private function castFtp(array $row, bool $withSecret): array
    {
        $row['id'] = (int)$row['id'];
        $row['project_id'] = (int)$row['project_id'];
        $row['port'] = (int)$row['port'];
        if (!$withSecret) {
            unset($row['password']);
        }
        return $row;
    }

    private function castMcp(array $row, bool $withSecret): array
    {
        $tools = json_decode((string)($row['tools_json'] ?? '[]'), true);
        $listed = [];
        if (is_array($tools)) {
            foreach ($tools as $tool) {
                if (!is_array($tool) || trim((string)($tool['name'] ?? '')) === '') {
                    continue;
                }
                $listed[] = [
                    'name' => (string)$tool['name'],
                    'description' => (string)($tool['description'] ?? ''),
                ];
            }
        }
        $envJson = (string)($row['env_json'] ?? '{}');
        $out = [
            'id' => (int)$row['id'],
            'label' => (string)$row['label'],
            'target' => (string)$row['target'],
            'has_env' => $envJson !== '' && $envJson !== '{}',
            'tools' => $listed,
        ];
        if ($withSecret) {
            $env = json_decode($envJson, true);
            $out['env'] = is_array($env) ? $env : [];
        }
        return $out;
    }

    private function castSkill(array $row): array
    {
        $row['id'] = (int)$row['id'];
        $row['is_active'] = (int)$row['is_active'];
        return $row;
    }

    private function castLog(array $row): array
    {
        $row['id'] = (int)$row['id'];
        $row['project_id'] = $row['project_id'] !== null ? (int)$row['project_id'] : null;
        return $row;
    }

    private function castLoopLog(array $row): array
    {
        $row['id'] = (int)$row['id'];
        $row['job_id'] = $row['job_id'] !== null ? (int)$row['job_id'] : null;
        $row['project_id'] = $row['project_id'] !== null ? (int)$row['project_id'] : null;
        return $row;
    }
}
