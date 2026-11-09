<?php

require __DIR__ . '/../src/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Run this from the command line.\n");
    exit(1);
}

$db = Database::instance();
if (!$db->tryLock('tick_lock', 900)) {
    $db->loopWrite(null, null, 'heartbeat', 'process', 'Tick lock already held');
    exit(0);
}

$tickProjects = [];
try {
    $trash = app_services()['safety']->emptyOldTrash(10);
    if ($trash > 0) {
        $db->loopWrite(null, null, 'heartbeat', 'process', 'Trash emptied · ' . $trash);
    }
    $purged = $db->purgeComplianceEvents();
    if ($purged > 0) {
        $db->loopWrite(null, null, 'heartbeat', 'process', 'Compliance purged · ' . $purged);
    }
    $dueJobs = $db->dueJobs(Heartbeat::STALE_SECONDS, Heartbeat::MAX_ATTEMPTS);
    $seenTasks = 0;
    foreach ($db->dueTasks() as $task) {
        if (!$task['project_id']) {
            continue;
        }
        $seenTasks++;
        $tickProjects[(int)$task['project_id']] = true;
    }
    foreach ($dueJobs as $job) {
        $projectId = $job['project_id'] !== null ? (int)$job['project_id'] : 0;
        if ($projectId > 0) {
            $tickProjects[$projectId] = true;
        }
    }
    $work = $dueJobs !== [] || $seenTasks > 0;
    if ($work) {
        $started = 'Tick started · ' . count($dueJobs) . ' stale jobs · ' . $seenTasks . ' due tasks';
        $db->loopWrite(null, null, 'heartbeat', 'process', $started);
        foreach (array_keys($tickProjects) as $projectId) {
            $db->loopWrite(null, $projectId, 'heartbeat', 'process', $started);
        }
    }
    $heartbeat = new Heartbeat($db);
    $heartbeat->beat();
    foreach ($db->dueTasks() as $task) {
        if (!$task['project_id']) {
            continue;
        }
        if (!$work) {
            $started = 'Tick started · tasks due after heartbeat';
            $db->loopWrite(null, null, 'heartbeat', 'process', $started);
            $db->loopWrite(null, (int)$task['project_id'], 'heartbeat', 'process', $started);
            $work = true;
        }
        $tickProjects[(int)$task['project_id']] = true;
        $heartbeat->runTask($task);
    }
    if ($work) {
        $db->loopWrite(null, null, 'heartbeat', 'process', 'Tick finished');
        foreach (array_keys($tickProjects) as $projectId) {
            $db->loopWrite(null, $projectId, 'heartbeat', 'process', 'Tick finished');
        }
    }
} catch (Throwable $e) {
    $aborted = 'Tick aborted · ' . $e->getMessage();
    $db->loopWrite(null, null, 'heartbeat', 'process', $aborted);
    foreach (array_keys($tickProjects) as $projectId) {
        $db->loopWrite(null, $projectId, 'heartbeat', 'process', $aborted);
    }
    throw $e;
} finally {
    $db->unlock('tick_lock');
}
