<?php
/*
---
name: sqlite_helper
description: Creates or changes a SQLite file in database/, including the folder. Use this instead of mkdir, run_command, or a new skill. Pass database as the filename.
---
*/

/*
Usage:
  database is the filename or the path, such as link_database.db or database/link_database.db. The helper is not locked to regulations.db. action, or command, is count, inspect, insert, query, drop, or ensure.
  Create workflow: always check the database and table first. If either already exists, do not recreate it. The schema is returned so you can compare columns.
  ensure: {"database":"link_database.sqlite","table":"links","columns":["url TEXT","title TEXT"]} or one CREATE TABLE statement in sql.
  When the table exists, read columns, missing_columns, and extra_columns. Add a column with execute and ALTER TABLE links ADD COLUMN name TYPE. Remove one with ALTER TABLE links DROP COLUMN name. Do not CREATE TABLE again.
  count: {"database":"link_database.db"} returns each table and the total. Add table to count one table.
  inspect: read schemas, indexes, row counts, and sample rows. database all lists every database file in the folder.
  query: run a read-only SELECT, WITH, or PRAGMA statement with params.
  insert: add a row with table and values.
  drop: {"database":"link_database.db","table":"links"} deletes that table. if_exists true leaves a missing table alone.
  execute: run a parameterized write. CREATE TABLE is checked first and is not run when that table exists. DROP TABLE is the drop action.
  init: create one explicitly named regulations.db or materials.db only when its tables are missing. Existing tables return their schema.
  Every existing database is snapshotted in the project backup vault before a write.
Do not use the sqlite3 CLI. Do not ask anyone to open the database file.
*/

$args = json_decode(stream_get_contents(STDIN) ?: '', true);
if (!is_array($args)) {
    $args = [];
}

$root = realpath(getcwd() ?: '.');
$relativeDir = trim((string)($args['db_dir'] ?? ''));
$database = str_replace('\\', '/', trim((string)($args['database'] ?? '')));
$action = strtolower(trim((string)($args['action'] ?? '')));
if ($action === '') {
    $action = strtolower(trim((string)($args['command'] ?? '')));
}
if ($database !== '' && $database !== 'all' && str_contains($database, '/')) {
    if (preg_match('#(^|/)\.\.(/|$)#', $database)) {
        echo json_encode(['status' => 'error', 'action' => $action, 'message' => 'Database path must stay inside the project.']);
        exit(1);
    }
    $dirPart = trim(dirname($database), '/');
    $database = basename($database);
    if ($dirPart !== '' && $dirPart !== '.') {
        $givenDir = trim(str_replace('\\', '/', $relativeDir), '/');
        if ($givenDir === '' || $givenDir === 'database') {
            $relativeDir = $dirPart;
        } elseif ($givenDir !== $dirPart) {
            echo json_encode(['status' => 'error', 'action' => $action, 'message' => 'Database path does not match db_dir.']);
            exit(1);
        }
    }
}
if ($relativeDir === '') {
    $relativeDir = 'database';
}
if ($action === '' || $action === 'create') {
    $hasDefinition = trim((string)($args['table'] ?? '')) !== ''
        || trim((string)($args['sql'] ?? '')) !== ''
        || (isset($args['columns']) && $args['columns'] !== [] && $args['columns'] !== '');
    $action = $hasDefinition ? 'ensure' : 'init';
}
if ($database === '') {
    echo json_encode(['status' => 'error', 'action' => $action, 'message' => 'Pass database as link_database.db or database/link_database.db. The helper is not locked to one file. Do not use sqlite3.']);
    exit(1);
}

if ($action === 'requirements') {
    $drivers = class_exists('PDO') ? PDO::getAvailableDrivers() : [];
    $result = [
        'php' => PHP_VERSION,
        'pdo' => class_exists('PDO'),
        'pdo_sqlite' => in_array('sqlite', $drivers, true),
        'sqlite3_cli' => trim((string)shell_exec('command -v sqlite3 2>/dev/null')) !== '',
    ];
    $result['ready'] = $result['pdo'] && $result['pdo_sqlite'];
    echo json_encode(['status' => $result['ready'] ? 'success' : 'error', 'action' => 'requirements', 'requirements' => $result]);
    exit($result['ready'] ? 0 : 1);
}

if ($root === false || $relativeDir === '' || str_contains($relativeDir, "\0") || preg_match('#(^|/)\.\.(/|$)#', str_replace('\\', '/', $relativeDir))) {
    echo json_encode(['status' => 'error', 'message' => 'Database directory must be inside the current project.']);
    exit(1);
}

$relativeDir = trim(str_replace('\\', '/', $relativeDir), '/');
$dbDir = $root . '/' . $relativeDir;
$dbPath = $dbDir . '/' . ($database !== '' ? $database : 'regulations.db');

if ($action !== 'inspect' || $database !== 'all') {
    if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]*\.(?:db|sqlite|sqlite3)$/', $database)) {
        echo json_encode(['status' => 'error', 'message' => 'Database must be a .db, .sqlite, or .sqlite3 file, such as link_database.db or database/link_database.db.']);
        exit(1);
    }
}

if ($action !== 'inspect' && !is_dir($dbDir) && !mkdir($dbDir, 0775, true) && !is_dir($dbDir)) {
    echo json_encode(['status' => 'error', 'message' => 'Could not create database directory.']);
    exit(1);
}

try {
    if ($action === 'ensure' || ($action === 'execute' && preg_match('/^\s*CREATE\s+TABLE\b/i', trim((string)($args['sql'] ?? ''))))) {
        echo json_encode(sqlite_ensure($relativeDir, $database, $dbPath, $args, $action));
        exit(0);
    }

    if ($action === 'init') {
        $databases = [
            'regulations.db' => [
                "CREATE TABLE IF NOT EXISTS regulations (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    topic TEXT NOT NULL,
                    summary TEXT,
                    source_url TEXT,
                    priority TEXT NOT NULL DEFAULT 'Medium' CHECK(priority IN ('High', 'Medium', 'Low')),
                    status TEXT NOT NULL DEFAULT 'Researching',
                    notes TEXT,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME
                )",
                "CREATE INDEX IF NOT EXISTS idx_regulations_status ON regulations(status)",
                "CREATE INDEX IF NOT EXISTS idx_regulations_priority ON regulations(priority)",
            ],
            'materials.db' => [
                "CREATE TABLE IF NOT EXISTS materials (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    category TEXT NOT NULL,
                    name TEXT NOT NULL,
                    supplier TEXT,
                    specs TEXT,
                    unit_cost REAL,
                    currency TEXT NOT NULL DEFAULT 'SEK',
                    availability TEXT,
                    notes TEXT,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME
                )",
                "CREATE INDEX IF NOT EXISTS idx_materials_category ON materials(category)",
                "CREATE INDEX IF NOT EXISTS idx_materials_name ON materials(name)",
            ],
        ];
        if (!isset($databases[$database])) {
            throw new InvalidArgumentException('init supports regulations.db or materials.db. Pass a specific database filename.');
        }
        $databases = [$database => $databases[$database]];
        $created = [];
        $anyExisted = false;
        foreach ($databases as $name => $schema) {
            $path = $dbDir . '/' . $name;
            $wasNew = !is_file($path);
            if (!$wasNew) {
                sqlite_snapshot($path, $name, $root);
            }
            $db = sqlite_open($path);
            $reports = [];
            $skipIndexFor = [];
            foreach ($schema as $sql) {
                if (preg_match('/^\s*CREATE\s+TABLE\b/i', $sql)) {
                    $tableName = sqlite_create_table_name($sql);
                    if ($tableName === null || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $tableName)) {
                        throw new InvalidArgumentException('Could not read a table name.');
                    }
                    if (sqlite_table_exists($db, $tableName)) {
                        $schemaRow = sqlite_schema($db, $tableName);
                        $schemaRow['existed'] = true;
                        $schemaRow['created'] = false;
                        $reports[] = $schemaRow;
                        $skipIndexFor[$tableName] = true;
                        $anyExisted = true;
                        continue;
                    }
                    $db->exec($sql);
                    $schemaRow = sqlite_schema($db, $tableName);
                    $schemaRow['existed'] = false;
                    $schemaRow['created'] = true;
                    $reports[] = $schemaRow;
                    continue;
                }
                if (preg_match('/^\s*CREATE\s+(?:UNIQUE\s+)?INDEX\b/i', $sql)) {
                    if (preg_match('/\bON\s+"?([A-Za-z_][A-Za-z0-9_]*)"?/i', $sql, $indexOn) && isset($skipIndexFor[$indexOn[1]])) {
                        continue;
                    }
                    $db->exec($sql);
                }
            }
            foreach ($reports as &$schemaRow) {
                if (empty($schemaRow['created'])) {
                    continue;
                }
                $fresh = sqlite_schema($db, (string)$schemaRow['name']);
                $fresh['existed'] = false;
                $fresh['created'] = true;
                $schemaRow = $fresh;
            }
            unset($schemaRow);
            $entry = [
                'database' => $name,
                'created' => $wasNew,
                'existed' => !$wasNew,
                'tables' => $reports,
            ];
            if ($skipIndexFor !== []) {
                $entry['message'] = 'Existing tables in ' . $name . ' were left in place. Compare their columns before you add or remove any.';
            }
            $created[] = $entry;
        }
        $payload = ['status' => 'success', 'action' => 'init', 'directory' => $relativeDir, 'databases' => $created];
        $payload['message'] = $anyExisted
            ? 'Existing tables were left in place and their schemas are below. Compare columns before you add or remove any.'
            : 'Created the missing tables in ' . $database . '.';
        echo json_encode($payload);
        exit(0);
    }

    if ($action === 'inspect') {
        if ($database === 'all') {
            $targets = [];
            foreach (is_dir($dbDir) ? (scandir($dbDir) ?: []) : [] as $name) {
                if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]*\.(?:db|sqlite|sqlite3)$/', $name)) {
                    $targets[] = $name;
                }
            }
            sort($targets);
            if ($targets === []) {
                throw new RuntimeException('No database files in ' . $relativeDir . '. Pass database as the filename, such as link_database.db.');
            }
        } else {
            $targets = [$database];
        }
        $inspected = [];
        foreach ($targets as $target) {
            if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]*\.(?:db|sqlite|sqlite3)$/', $target)) {
                throw new InvalidArgumentException('Database must be a .db, .sqlite, or .sqlite3 filename.');
            }
            $path = $dbDir . '/' . $target;
            if (!is_file($path)) {
                throw new RuntimeException('Database not found: ' . $relativeDir . '/' . $target . '. Pass database as that filename. ensure with table and columns creates it. init only creates regulations.db and materials.db.');
            }
            $inspectDb = new PDO('sqlite:' . $path, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            $inspectDb->exec('PRAGMA foreign_keys = ON');
            $tables = $inspectDb->query("SELECT name, sql FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name")->fetchAll();
            foreach ($tables as &$table) {
                $tableName = (string)$table['name'];
                $quotedTable = '"' . str_replace('"', '""', $tableName) . '"';
                $table['columns'] = $inspectDb->query('PRAGMA table_info(' . $quotedTable . ')')->fetchAll();
                $table['indexes'] = $inspectDb->query('PRAGMA index_list(' . $quotedTable . ')')->fetchAll();
                $table['row_count'] = (int)$inspectDb->query('SELECT COUNT(*) FROM ' . $quotedTable)->fetchColumn();
                $limit = isset($args['limit']) && is_numeric($args['limit']) ? max(0, min(100, (int)$args['limit'])) : 20;
                $table['rows'] = $limit > 0 ? $inspectDb->query('SELECT * FROM ' . $quotedTable . ' LIMIT ' . $limit)->fetchAll() : [];
            }
            unset($table);
            $inspected[] = ['database' => $target, 'tables' => $tables];
        }
        echo json_encode(['status' => 'success', 'action' => 'inspect', 'directory' => $relativeDir, 'databases' => $inspected]);
        exit(0);
    }

    if (!is_file($dbPath)) {
        throw new RuntimeException('Database not found: ' . $relativeDir . '/' . $database . '. Pass database as that filename. ensure with table and columns creates it. init only creates regulations.db and materials.db.');
    }

    $db = new PDO('sqlite:' . $dbPath, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $db->exec('PRAGMA foreign_keys = ON');
    $db->exec('PRAGMA busy_timeout = 5000');

    if ($action === 'drop' || $action === 'delete' || ($action === 'execute' && preg_match('/^\s*DROP\s+TABLE\b/i', trim((string)($args['sql'] ?? ''))))) {
        sqlite_snapshot($dbPath, $database, $root);
        echo json_encode(sqlite_drop_action($db, $args, $database));
        exit(0);
    }

    if ($action === 'count') {
        echo json_encode(sqlite_count_action($db, $args, $database));
        exit(0);
    }

    if ($action === 'tables') {
        $tables = $db->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
        echo json_encode(['status' => 'success', 'action' => 'tables', 'database' => $database, 'tables' => $tables]);
        exit(0);
    }

    if ($action === 'query') {
        $sql = trim((string)($args['sql'] ?? ''));
        if ($sql === '' || !preg_match('/^(SELECT|WITH|PRAGMA)\b/i', $sql)) {
            throw new InvalidArgumentException('Query action only accepts SELECT, WITH, or PRAGMA statements.');
        }
        $statement = $db->prepare($sql);
        $statement->execute(is_array($args['params'] ?? null) ? $args['params'] : []);
        echo json_encode(['status' => 'success', 'action' => 'query', 'database' => $database, 'rows' => $statement->fetchAll()]);
        exit(0);
    }

    if ($action === 'insert') {
        $table = trim((string)($args['table'] ?? ''));
        $values = $args['values'] ?? [];
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $table) || !is_array($values) || $values === []) {
            throw new InvalidArgumentException('Insert requires a table and a non-empty values object.');
        }
        sqlite_snapshot($dbPath, $database, $root);
        $columns = array_keys($values);
        foreach ($columns as $column) {
            if (!is_string($column) || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $column)) {
                throw new InvalidArgumentException('Column names may contain only letters, numbers, and underscores.');
            }
        }
        $quotedColumns = implode(', ', array_map(static fn(string $column): string => '"' . $column . '"', $columns));
        $placeholders = implode(', ', array_fill(0, count($columns), '?'));
        $statement = $db->prepare('INSERT INTO "' . $table . '" (' . $quotedColumns . ') VALUES (' . $placeholders . ')');
        $statement->execute(array_values($values));
        echo json_encode(['status' => 'success', 'action' => 'insert', 'database' => $database, 'table' => $table, 'id' => $db->lastInsertId(), 'changes' => $statement->rowCount()]);
        exit(0);
    }

    if ($action === 'execute') {
        $sql = trim((string)($args['sql'] ?? ''));
        if ($sql === '' || preg_match('/^\s*(SELECT|WITH|PRAGMA)\b/i', $sql)) {
            throw new InvalidArgumentException('Execute requires a non-read SQL statement.');
        }
        sqlite_snapshot($dbPath, $database, $root);
        $statement = $db->prepare($sql);
        $statement->execute(is_array($args['params'] ?? null) ? $args['params'] : []);
        echo json_encode(['status' => 'success', 'action' => 'execute', 'database' => $database, 'changes' => $statement->rowCount()]);
        exit(0);
    }

    throw new InvalidArgumentException('Unknown action. Use ensure, init, inspect, tables, count, query, insert, drop, or execute.');
} catch (Throwable $e) {
    echo json_encode(['status' => 'error', 'action' => $action, 'message' => $e->getMessage()]);
    exit(1);
}

function sqlite_open(string $path): PDO
{
    $db = new PDO('sqlite:' . $path, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $db->exec('PRAGMA foreign_keys = ON');
    $db->exec('PRAGMA busy_timeout = 5000');
    return $db;
}

function sqlite_snapshot(string $path, string $database, string $root): void
{
    if (!is_file($path)) {
        return;
    }
    $backupDir = dirname($root) . '/.backups';
    if (!is_dir($backupDir) && !mkdir($backupDir, 0775, true) && !is_dir($backupDir)) {
        throw new RuntimeException('Could not create the database backup folder.');
    }
    $safe = preg_replace('/[^A-Za-z0-9._-]+/', '_', basename($database)) ?: 'database.db';
    $stored = date('Ymd_His') . '_' . $safe;
    if (file_exists($backupDir . '/' . $stored)) {
        $stored = date('Ymd_His') . '_' . substr(uniqid(), -4) . '_' . $safe;
    }
    if (!copy($path, $backupDir . '/' . $stored)) {
        throw new RuntimeException('Could not back up ' . $database . ' before changing it.');
    }
    $meta = [
        'original' => basename($root) . '/database/' . basename($database),
        'project_folder' => basename($root),
        'relative_path' => 'database/' . basename($database),
        'backed_up_at' => date('c'),
        'filename' => basename($database),
        'stored_as' => $stored,
    ];
    if (file_put_contents($backupDir . '/' . $stored . '.json', json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) === false) {
        @unlink($backupDir . '/' . $stored);
        throw new RuntimeException('Could not save the database backup metadata.');
    }
}

function sqlite_table_exists(PDO $db, string $table): bool
{
    $stmt = $db->prepare("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ?");
    $stmt->execute([$table]);
    return (bool)$stmt->fetchColumn();
}

function sqlite_drop_table_name(string $sql): ?array
{
    $body = rtrim(trim($sql), " \t\r\n;");
    if (!preg_match('/^\s*DROP\s+TABLE\s+(IF\s+EXISTS\s+)?(?:"([^"]+)"|`([^`]+)`|\[([^\]]+)\]|([A-Za-z_][A-Za-z0-9_]*))\s*$/i', $body, $m)) {
        return null;
    }
    $name = '';
    foreach ([2, 3, 4, 5] as $i) {
        if (($m[$i] ?? '') !== '') {
            $name = $m[$i];
            break;
        }
    }
    if ($name === '') {
        return null;
    }
    return ['table' => $name, 'if_exists' => trim((string)($m[1] ?? '')) !== ''];
}

function sqlite_count_action(PDO $db, array $args, string $database): array
{
    $table = trim((string)($args['table'] ?? ''));
    $names = $db->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
    if ($table !== '') {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $table)) {
            throw new InvalidArgumentException('Table names may contain only letters, numbers, and underscores.');
        }
        if (!in_array($table, $names, true)) {
            throw new RuntimeException('Table ' . $table . ' is not in ' . $database . '.');
        }
        $count = sqlite_row_count($db, $table);
        return [
            'status' => 'success',
            'action' => 'count',
            'database' => $database,
            'table' => $table,
            'rows' => $count,
            'message' => $table . ' has ' . $count . ' ' . ($count === 1 ? 'row' : 'rows') . ' in ' . $database . '.',
        ];
    }
    $tables = [];
    $total = 0;
    foreach ($names as $name) {
        $count = sqlite_row_count($db, (string)$name);
        $tables[] = ['table' => (string)$name, 'rows' => $count];
        $total += $count;
    }
    $tableWord = count($tables) === 1 ? 'table' : 'tables';
    return [
        'status' => 'success',
        'action' => 'count',
        'database' => $database,
        'rows' => $total,
        'tables' => $tables,
        'message' => $database . ' has ' . $total . ' ' . ($total === 1 ? 'row' : 'rows') . ' across ' . count($tables) . ' ' . $tableWord . '.',
    ];
}

function sqlite_row_count(PDO $db, string $table): int
{
    $quoted = '"' . str_replace('"', '""', $table) . '"';
    return (int)$db->query('SELECT COUNT(*) FROM ' . $quoted)->fetchColumn();
}

function sqlite_drop_action(PDO $db, array $args, string $database): array
{
    $ifExists = in_array(strtolower(trim((string)($args['if_exists'] ?? ''))), ['1', 'true', 'yes'], true);
    $table = trim((string)($args['table'] ?? ''));
    $sql = trim((string)($args['sql'] ?? ''));
    if ($sql !== '') {
        $parsed = sqlite_drop_table_name($sql);
        if ($parsed === null) {
            throw new InvalidArgumentException('Pass one DROP TABLE statement, or action drop with table.');
        }
        if ($table === '') {
            $table = $parsed['table'];
        } elseif (strcasecmp($table, $parsed['table']) !== 0) {
            throw new InvalidArgumentException('table does not match the DROP TABLE statement.');
        }
        $ifExists = $ifExists || $parsed['if_exists'];
    }
    if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $table)) {
        throw new InvalidArgumentException('Drop requires a table name. Table names may contain only letters, numbers, and underscores.');
    }
    $existed = sqlite_table_exists($db, $table);
    if (!$existed && !$ifExists) {
        throw new RuntimeException('Table ' . $table . ' is not in ' . $database . '.');
    }
    if ($existed) {
        $db->exec('DROP TABLE "' . str_replace('"', '""', $table) . '"');
    }
    $tables = $db->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
    return [
        'status' => 'success',
        'action' => 'drop',
        'database' => $database,
        'table' => $table,
        'dropped' => $existed,
        'tables' => $tables,
        'message' => $existed
            ? 'Dropped table ' . $table . ' from ' . $database . '.'
            : 'Table ' . $table . ' was not in ' . $database . '.',
    ];
}

function sqlite_schema(PDO $db, string $table): array
{
    $quoted = '"' . str_replace('"', '""', $table) . '"';
    $stmt = $db->prepare("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = ?");
    $stmt->execute([$table]);
    $sql = $stmt->fetchColumn();
    return [
        'name' => $table,
        'sql' => $sql === false ? null : (string)$sql,
        'columns' => $db->query('PRAGMA table_info(' . $quoted . ')')->fetchAll(),
        'indexes' => $db->query('PRAGMA index_list(' . $quoted . ')')->fetchAll(),
        'row_count' => (int)$db->query('SELECT COUNT(*) FROM ' . $quoted)->fetchColumn(),
    ];
}

function sqlite_schemas(PDO $db): array
{
    $tables = $db->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
    $out = [];
    foreach ($tables as $name) {
        $out[] = sqlite_schema($db, (string)$name);
    }
    return $out;
}

function sqlite_create_table_name(string $sql): ?string
{
    if (!preg_match('/^\s*CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?(?:"([^"]+)"|`([^`]+)`|\[([^\]]+)\]|([A-Za-z_][A-Za-z0-9_]*))\b/i', $sql, $m)) {
        return null;
    }
    foreach ([1, 2, 3, 4] as $i) {
        if (($m[$i] ?? '') !== '') {
            return $m[$i];
        }
    }
    return null;
}

function sqlite_single_create(string $sql): string
{
    $body = rtrim(trim($sql), " \t\r\n;");
    if ($body === '' || str_contains($body, ';')) {
        throw new InvalidArgumentException('Pass one CREATE TABLE statement.');
    }
    if (!preg_match('/^CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?(?:"[^"]+"|`[^`]+`|\[[^\]]+\]|[A-Za-z_][A-Za-z0-9_]*)\s*\(/i', $body)) {
        throw new InvalidArgumentException('sql must be one CREATE TABLE statement with a column list. Use execute to add or remove columns.');
    }
    if (sqlite_create_table_name($body) === null) {
        throw new InvalidArgumentException('Could not read the table name from sql.');
    }
    return $body;
}

function sqlite_columns_from_create(string $sql): array
{
    $body = rtrim(trim($sql), " \t\r\n;");
    $start = strpos($body, '(');
    $end = strrpos($body, ')');
    if ($start === false || $end === false || $end <= $start) {
        return [];
    }
    $list = substr($body, $start + 1, $end - $start - 1);
    $parts = [];
    $depth = 0;
    $buf = '';
    $len = strlen($list);
    for ($i = 0; $i < $len; $i++) {
        $ch = $list[$i];
        if ($ch === '(') {
            $depth++;
        } elseif ($ch === ')') {
            $depth = max(0, $depth - 1);
        }
        if ($ch === ',' && $depth === 0) {
            $parts[] = $buf;
            $buf = '';
            continue;
        }
        $buf .= $ch;
    }
    if (trim($buf) !== '') {
        $parts[] = $buf;
    }
    $columns = [];
    foreach ($parts as $part) {
        $part = trim($part);
        if ($part === '' || preg_match('/^(CONSTRAINT|PRIMARY|UNIQUE|CHECK|FOREIGN)\b/i', $part)) {
            continue;
        }
        if (!preg_match('/^(?:"([^"]+)"|`([^`]+)`|\[([^\]]+)\]|([A-Za-z_][A-Za-z0-9_]*))\s+([A-Za-z][A-Za-z0-9_]*)\b/', $part, $m)) {
            continue;
        }
        $name = $m[1] !== '' ? $m[1] : ($m[2] !== '' ? $m[2] : ($m[3] !== '' ? $m[3] : $m[4]));
        $columns[] = ['name' => $name, 'type' => strtoupper($m[5])];
    }
    return $columns;
}

function sqlite_normalize_columns(array $columns): array
{
    $out = [];
    foreach ($columns as $key => $column) {
        if (is_string($column)) {
            if (!preg_match('/^([A-Za-z_][A-Za-z0-9_]*)\s+([A-Za-z][A-Za-z0-9_]*)\b/', trim($column), $m)) {
                throw new InvalidArgumentException('Each column must look like "name TYPE".');
            }
            $out[] = ['name' => $m[1], 'type' => strtoupper($m[2])];
            continue;
        }
        if (is_string($key) && is_string($column)) {
            $type = strtoupper(trim($column));
            if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key) || !preg_match('/^[A-Z][A-Z0-9_]*$/', $type)) {
                throw new InvalidArgumentException('Each column needs a name and a SQLite type.');
            }
            $out[] = ['name' => $key, 'type' => $type];
            continue;
        }
        if (!is_array($column)) {
            throw new InvalidArgumentException('Each column must be a name and type.');
        }
        $name = trim((string)($column['name'] ?? ''));
        $type = strtoupper(trim((string)($column['type'] ?? 'TEXT')));
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) || !preg_match('/^[A-Z][A-Z0-9_]*$/', $type)) {
            throw new InvalidArgumentException('Each column needs a name and a SQLite type.');
        }
        $out[] = ['name' => $name, 'type' => $type];
    }
    if ($out === []) {
        throw new InvalidArgumentException('Pass columns or a CREATE TABLE statement.');
    }
    return $out;
}

function sqlite_create_sql(string $table, array $columns): string
{
    $parts = [];
    foreach ($columns as $column) {
        $parts[] = '"' . $column['name'] . '" ' . $column['type'];
    }
    return 'CREATE TABLE "' . $table . '" (' . implode(', ', $parts) . ')';
}

function sqlite_column_label(array $column): string
{
    return $column['name'] . ' ' . strtoupper((string)$column['type']);
}

function sqlite_compare_columns(array $existing, array $wanted): array
{
    $have = [];
    foreach ($existing as $column) {
        $label = sqlite_column_label(['name' => (string)$column['name'], 'type' => (string)$column['type']]);
        $have[strtolower((string)$column['name']) . ' ' . strtoupper((string)$column['type'])] = $label;
    }
    $need = [];
    foreach ($wanted as $column) {
        $need[strtolower($column['name']) . ' ' . strtoupper($column['type'])] = sqlite_column_label($column);
    }
    return [
        'columns_match' => array_diff_key($need, $have) === [] && array_diff_key($have, $need) === [],
        'missing_columns' => array_values(array_diff_key($need, $have)),
        'extra_columns' => array_values(array_diff_key($have, $need)),
    ];
}

function sqlite_exists_message(string $database, string $table, ?array $compare): string
{
    $base = 'Table ' . $table . ' already exists in ' . $database . '. It was not recreated. The schema is in columns. Drop it with action drop and table ' . $table . '.';
    if ($compare === null) {
        return $base . ' Compare it with the columns you need. Add a column with action execute and sql ALTER TABLE ' . $table . ' ADD COLUMN name TYPE. Remove a column with ALTER TABLE ' . $table . ' DROP COLUMN name.';
    }
    if ($compare['columns_match']) {
        return $base . ' The columns match what you asked for.';
    }
    $bits = [];
    if ($compare['missing_columns'] !== []) {
        $bits[] = 'Missing: ' . implode(', ', $compare['missing_columns']) . '.';
    }
    if ($compare['extra_columns'] !== []) {
        $bits[] = 'Extra: ' . implode(', ', $compare['extra_columns']) . '.';
    }
    return $base . ' ' . implode(' ', $bits) . ' Add a column with action execute and sql ALTER TABLE ' . $table . ' ADD COLUMN name TYPE. Remove a column with ALTER TABLE ' . $table . ' DROP COLUMN name. Do not create the table again.';
}

function sqlite_ensure(string $relativeDir, string $database, string $dbPath, array $args, string $action): array
{
    $root = realpath(getcwd() ?: '.');
    if ($root === false) {
        throw new RuntimeException('Could not resolve the project folder.');
    }
    $fileExisted = is_file($dbPath);
    $table = trim((string)($args['table'] ?? ''));
    $rawSql = trim((string)($args['sql'] ?? ''));
    $hasColumns = isset($args['columns']) && is_array($args['columns']) && $args['columns'] !== [];
    $wanted = $hasColumns ? sqlite_normalize_columns($args['columns']) : [];
    $createSql = '';
    if ($rawSql !== '') {
        $createSql = sqlite_single_create($rawSql);
        $fromSql = sqlite_create_table_name($createSql);
        if ($table === '') {
            $table = (string)$fromSql;
        } elseif ($fromSql !== null && strcasecmp($fromSql, $table) !== 0) {
            throw new InvalidArgumentException('table does not match the CREATE TABLE statement.');
        }
        if (!$hasColumns) {
            $wanted = sqlite_columns_from_create($createSql);
        }
    }
    if ($table !== '') {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $table)) {
            throw new InvalidArgumentException('Table names may contain only letters, numbers, and underscores.');
        }
    }
    if (!$fileExisted && $table === '') {
        throw new RuntimeException('Database not found: ' . $relativeDir . '/' . $database . '. Pass table and columns or sql to create it.');
    }
    if (!$fileExisted && $wanted === [] && $createSql === '') {
        throw new InvalidArgumentException('Pass columns or sql to create ' . $table . '.');
    }
    if (!$fileExisted && $table !== '' && $createSql === '' && $wanted === []) {
        throw new InvalidArgumentException('Table ' . $table . ' does not exist. Pass columns or sql to create it.');
    }
    $db = sqlite_open($dbPath);
    if ($table === '') {
        return [
            'status' => 'success',
            'action' => $action,
            'directory' => $relativeDir,
            'database' => $database,
            'existed' => true,
            'created' => false,
            'tables' => sqlite_schemas($db),
            'message' => 'Database already exists. It was not recreated. Schemas are below. Pass table and columns only for a table that is not listed.',
        ];
    }
    if (sqlite_table_exists($db, $table)) {
        $schema = sqlite_schema($db, $table);
        $compare = $wanted !== [] ? sqlite_compare_columns($schema['columns'], $wanted) : null;
        $payload = [
            'status' => 'success',
            'action' => $action,
            'directory' => $relativeDir,
            'database' => $database,
            'existed' => true,
            'created' => false,
            'table' => $table,
            'sql' => $schema['sql'],
            'columns' => $schema['columns'],
            'indexes' => $schema['indexes'],
            'row_count' => $schema['row_count'],
            'message' => sqlite_exists_message($relativeDir . '/' . $database, $table, $compare),
        ];
        if ($compare !== null) {
            $payload['columns_match'] = $compare['columns_match'];
            $payload['missing_columns'] = $compare['missing_columns'];
            $payload['extra_columns'] = $compare['extra_columns'];
        }
        return $payload;
    }
    if ($createSql === '' && $wanted === []) {
        throw new InvalidArgumentException('Table ' . $table . ' does not exist. Pass columns or sql to create it.');
    }
    sqlite_snapshot($dbPath, $database, $root);
    $statement = $createSql !== '' && !$hasColumns ? $createSql : sqlite_create_sql($table, $wanted);
    $db->exec($statement);
    $schema = sqlite_schema($db, $table);
    return [
        'status' => 'success',
        'action' => $action,
        'directory' => $relativeDir,
        'database' => $database,
        'existed' => false,
        'created' => true,
        'table' => $table,
        'sql' => $schema['sql'],
        'columns' => $schema['columns'],
        'indexes' => $schema['indexes'],
        'row_count' => $schema['row_count'],
        'message' => 'Created table ' . $table . ' in ' . $relativeDir . '/' . $database . '.',
    ];
}
