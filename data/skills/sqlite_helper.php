<?php
/*
---
name: sqlite_helper
description: Creates, inspects, and manages SQLite databases from PHP in the current project. Stores databases in the project's database folder by default. Supports dependency checks, schema and content inspection, initialization, parameterized queries, inserts, updates, deletes, and table listing.
---
*/

/*
Usage:
  requirements: verify PHP PDO SQLite and the sqlite3 CLI.
  init: create database/regulations.db and materials.db in the current project.
  inspect: read schemas, indexes, row counts, and sample rows without sqlite3.
  inspect with {"database":"all","limit":20}: inspect both default databases.
  query: run a read-only SELECT, WITH, or PRAGMA statement with params.
  insert: add a row with table and values.
  execute: run a parameterized write or schema statement.
Always use inspect before changing an unfamiliar database.
*/

$args = json_decode(stream_get_contents(STDIN) ?: '', true);
if (!is_array($args)) {
    $args = [];
}

$root = realpath(getcwd() ?: '.');
$relativeDir = trim((string)($args['db_dir'] ?? 'database'));
$database = trim((string)($args['database'] ?? 'regulations.db'));
$action = strtolower(trim((string)($args['action'] ?? 'init')));

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
    if (!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.-]*\.db$/', basename($dbPath))) {
    echo json_encode(['status' => 'error', 'message' => 'Database must be a .db filename.']);
    exit(1);
    }
}

if ($action !== 'inspect' && !is_dir($dbDir) && !mkdir($dbDir, 0775, true) && !is_dir($dbDir)) {
    echo json_encode(['status' => 'error', 'message' => 'Could not create database directory.']);
    exit(1);
}

try {
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
        $created = [];
        foreach ($databases as $name => $schema) {
            $path = $dbDir . '/' . $name;
            $wasNew = !is_file($path);
            $db = new PDO('sqlite:' . $path, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
            $db->exec('PRAGMA foreign_keys = ON');
            $db->exec('PRAGMA busy_timeout = 5000');
            $db->beginTransaction();
            foreach ($schema as $sql) {
                $db->exec($sql);
            }
            $db->commit();
            $created[] = ['database' => $name, 'created' => $wasNew];
        }
        echo json_encode(['status' => 'success', 'action' => 'init', 'directory' => $relativeDir, 'databases' => $created]);
        exit(0);
    }

    if ($action === 'inspect') {
        $targets = $database === 'all' ? ['regulations.db', 'materials.db'] : [$database];
        $inspected = [];
        foreach ($targets as $target) {
            if (!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.-]*\.db$/', $target)) {
                throw new InvalidArgumentException('Database must be a .db filename.');
            }
            $path = $dbDir . '/' . $target;
            if (!is_file($path)) {
                throw new RuntimeException('Database not found: ' . $relativeDir . '/' . $target . '. Run the init action first.');
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
        throw new RuntimeException('Database not found: ' . $relativeDir . '/' . $database . '. Run the init action first.');
    }

    $db = new PDO('sqlite:' . $dbPath, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $db->exec('PRAGMA foreign_keys = ON');
    $db->exec('PRAGMA busy_timeout = 5000');

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
        $statement = $db->prepare($sql);
        $statement->execute(is_array($args['params'] ?? null) ? $args['params'] : []);
        echo json_encode(['status' => 'success', 'action' => 'execute', 'database' => $database, 'changes' => $statement->rowCount()]);
        exit(0);
    }

    throw new InvalidArgumentException('Unknown action. Use init, tables, query, insert, or execute.');
} catch (Throwable $e) {
    echo json_encode(['status' => 'error', 'action' => $action, 'message' => $e->getMessage()]);
    exit(1);
}
