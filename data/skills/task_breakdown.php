<?php
/*
---
name: task_breakdown
description: Adds backlog cards for agent work or a scheduled action. args project_id, tasks (each has title and agent). Not a user todo.
---
*/
$args = json_decode(stream_get_contents(STDIN) ?: '', true);
if (!is_array($args)) {
    $args = [];
}
$rawProject = $args['project_id'] ?? 0;
$projectId = (is_int($rawProject) || (is_string($rawProject) && ctype_digit(trim($rawProject)))) ? (int)$rawProject : 0;
$tasks = $args['tasks'] ?? [];
if (is_string($tasks)) {
    $tasks = json_decode($tasks, true);
}
if (!is_array($tasks) || $tasks === []) {
    echo json_encode(['status' => 'error', 'message' => 'Pass tasks. Each task has a title and an agent.']);
    exit(1);
}
$allowed = ['orchestrator', 'researcher', 'writer', 'coder', 'analyst', 'marketer'];
$cards = [];
foreach ($tasks as $task) {
    if (is_string($task)) {
        $title = trim($task);
        $agent = 'orchestrator';
    } elseif (is_array($task)) {
        $title = trim((string)($task['title'] ?? ''));
        $agent = strtolower(trim((string)($task['agent'] ?? 'orchestrator')));
    } else {
        continue;
    }
    if ($title === '') {
        continue;
    }
    if ($agent === 'human' || $agent === 'user') {
        echo json_encode(['status' => 'error', 'message' => 'Cards are not personal todos. Assign orchestrator for planning with the user, or the subagent who will produce the result.']);
        exit(1);
    }
    if (!in_array($agent, $allowed, true)) {
        $agent = 'orchestrator';
    }
    $cards[] = [
        'title' => strlen($title) > 200 ? substr($title, 0, 200) : $title,
        'agent' => $agent,
    ];
    if (count($cards) >= 40) {
        break;
    }
}
if ($cards === []) {
    echo json_encode(['status' => 'error', 'message' => 'Pass tasks with a title and an agent.']);
    exit(1);
}
$dbPath = dirname(__DIR__) . '/app.sqlite';
if (!is_file($dbPath)) {
    echo json_encode(['status' => 'error', 'message' => 'Database file not found.']);
    exit(1);
}
$db = new PDO('sqlite:' . $dbPath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$db->exec('PRAGMA busy_timeout = 5000');
if ($projectId <= 0) {
    $names = [];
    $folder = strtolower(trim((string)$rawProject));
    if (preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $folder)) {
        $names[] = $folder;
    }
    $cwd = basename(getcwd() ?: '');
    if (preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $cwd) && !in_array($cwd, $names, true)) {
        $names[] = $cwd;
    }
    $byFolder = $db->prepare('SELECT id FROM projects WHERE folder_name = ?');
    foreach ($names as $name) {
        $byFolder->execute([$name]);
        $projectId = (int)$byFolder->fetchColumn();
        if ($projectId > 0) {
            break;
        }
    }
}
$exists = $db->prepare('SELECT id FROM projects WHERE id = ?');
$exists->execute([$projectId]);
if ($projectId <= 0 || !$exists->fetch()) {
    echo json_encode(['status' => 'error', 'message' => 'Project not found.']);
    exit(1);
}
$insert = $db->prepare("INSERT INTO tasks (project_id, title, description, status, assigned_agent, recurrence_interval) VALUES (?, ?, '', 'backlog', ?, 'none')");
foreach ($cards as $card) {
    $insert->execute([$projectId, $card['title'], $card['agent']]);
}
echo json_encode(['status' => 'success', 'message' => count($cards) . ' cards appended to the backlog.']);
