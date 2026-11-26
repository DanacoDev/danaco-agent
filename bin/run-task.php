<?php

require __DIR__ . '/../src/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Run this from the command line.\n");
    exit(1);
}

$taskId = (int)($argv[1] ?? 0);
$jobId = isset($argv[2]) && $argv[2] !== '' ? (int)$argv[2] : 0;
if ($taskId <= 0) {
    fwrite(STDERR, "Missing task id.\n");
    exit(1);
}

$db = Database::instance();
$task = $db->task($taskId);
if (!$task) {
    $db->loopWrite($jobId > 0 ? $jobId : null, null, 'heartbeat', 'process', 'Task ' . $taskId . ' missing · not started');
    exit(0);
}

$heartbeat = new Heartbeat($db);
try {
    $heartbeat->runTask($task, $jobId > 0 ? $jobId : null);
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
