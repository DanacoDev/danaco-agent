<?php

require __DIR__ . '/../src/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Run this from the command line.\n");
    exit(1);
}

$db = Database::instance();
if (!$db->tryLock('tick_lock', 900)) {
    exit(0);
}

try {
    app_services()['safety']->emptyOldTrash(30);
    $db->purgeComplianceEvents();
    $heartbeat = new Heartbeat($db);
    $heartbeat->beat();
    foreach ($db->dueTasks() as $task) {
        if (!$task['project_id']) {
            continue;
        }
        $heartbeat->runTask($task);
    }
} finally {
    $db->unlock('tick_lock');
}
