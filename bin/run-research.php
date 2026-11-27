<?php

require __DIR__ . '/../src/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Run this from the command line.\n");
    exit(1);
}

$jobId = (int)($argv[1] ?? 0);
if ($jobId <= 0) {
    fwrite(STDERR, "Missing job id.\n");
    exit(1);
}

$db = Database::instance();
$job = $db->job($jobId);
if (!$job || (string)($job['kind'] ?? '') !== 'research') {
    exit(0);
}
$projectId = (int)($job['project_id'] ?? 0);
$payload = json_decode((string)($job['payload'] ?? ''), true);
$slug = is_array($payload) ? trim((string)($payload['slug'] ?? '')) : '';
$folder = is_array($payload) ? trim((string)($payload['folder'] ?? '')) : '';
if ($projectId <= 0 || $slug === '' || $folder === '') {
    $db->finishJob($jobId);
    exit(0);
}

$passes = 0;
$hold = '';
try {
    while (true) {
        if (!$db->jobIsRunning($jobId)) {
            exit(0);
        }
        $db->touchJob($jobId);
        if (!research_mission_active($folder, $slug)) {
            $db->loopWrite($jobId, $projectId, 'researcher', 'process', 'Research ' . $slug . ' turned off');
            if ($db->jobIsRunning($jobId)) {
                $db->finishJob($jobId);
            }
            exit(0);
        }
        $why = '';
        if ($db->foregroundWork()) {
            $why = 'paused while chat or a task is using the model';
        } else {
            $board = research_pass_board($folder, $slug);
            if ($board === null) {
                $why = 'board unavailable';
            } elseif (!empty($board['waiting'])) {
                $why = 'waiting';
            } elseif (!empty($board['idle'])) {
                $why = 'idle';
            }
        }
        if ($why !== '') {
            if ($hold !== $why) {
                $db->loopWrite($jobId, $projectId, 'researcher', 'process', 'Research ' . $slug . ' · ' . $why);
                $hold = $why;
            }
            sleep(20);
            continue;
        }
        $hold = '';
        try {
            $result = app_services()['subagents']->run('researcher', $folder, research_pass_instruction($slug), static function (): void {
            }, $projectId, function () use ($db, $jobId): bool {
                $db->touchJob($jobId);
                return !$db->jobIsRunning($jobId);
            }, true);
        } catch (ChatStopped $e) {
            if (!$db->jobIsRunning($jobId)) {
                exit(0);
            }
            sleep(15);
            continue;
        } catch (Throwable $e) {
            if (!$db->jobIsRunning($jobId)) {
                exit(0);
            }
            $db->loopWrite($jobId, $projectId, 'researcher', 'process', 'Research ' . $slug . ' · ' . $e->getMessage());
            sleep(str_contains($e->getMessage(), 'Only one subagent') ? 15 : 30);
            continue;
        }
        if (!$db->jobIsRunning($jobId)) {
            exit(0);
        }
        $passes++;
        $line = trim(preg_replace('/\s+/', ' ', $result) ?? '');
        if (strlen($line) > 180) {
            $line = substr($line, 0, 177) . '...';
        }
        $db->loopWrite($jobId, $projectId, 'researcher', 'process', 'Research ' . $slug . ' · ' . ($line !== '' ? $line : 'pass ' . $passes));
    }
} catch (Throwable $e) {
    $db->loopWrite($jobId, $projectId, 'researcher', 'process', 'Research stopped · ' . $e->getMessage());
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}

function research_mission_active(string $folder, string $slug): bool
{
    $path = Projects::root($folder) . '/database/research-' . $slug . '.db';
    if (!is_file($path)) {
        return false;
    }
    try {
        $pdo = new PDO('sqlite:' . $path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $have = [];
        foreach ($pdo->query('PRAGMA table_info(meta)') as $col) {
            $have[] = (string)$col['name'];
        }
        if (!in_array('active', $have, true)) {
            return false;
        }
        return (int)$pdo->query('SELECT active FROM meta WHERE id = 1')->fetchColumn() === 1;
    } catch (Throwable $e) {
        return false;
    }
}

function research_pass_board(string $folder, string $slug): ?array
{
    $result = app_services()['skills']->run('research_loop', ['action' => 'board', 'name' => $slug], $folder, false, false);
    $payload = json_decode((string)($result['stdout'] ?? ''), true);
    if (!is_array($payload) || (int)($result['exit_code'] ?? 1) !== 0) {
        return null;
    }
    return $payload;
}

function research_pass_instruction(string $slug): string
{
    return "Slug: {$slug}\n"
        . "This is one research pass. Do not create a task. Do not call tasklist. Do not write a wiki file. Do not open the database with sqlite_helper.\n"
        . "Call research_loop with name {$slug}. One call to next, then one commit, then stop.\n"
        . "1. Call action status.\n"
        . "2. If there are no hypotheses, call action commit, kind reflect. The list argument is hypotheses. It holds at most three roots. Each one is {\"statement\":\"one to four words\",\"status\":\"proposed\",\"parent_id\":0}. A root is a short title of that direction, not a sentence. Then stop.\n"
        . "3. If a hypothesis is still proposed and none are testing, stop. Do not ingest. A hypothesis the user kept is testing. Every testing hypothesis is explored. A proposed hypothesis that is still waiting does not stop those.\n"
        . "4. Otherwise call action next once. If the stage is idle or awaiting, stop. If the packet says the hypotheses are approved, add falsification queries for the testing hypotheses and do not add a hypothesis in that pass. If the stage is reflect, read constraints.lessons and priors first. Propose at most three children. A child is one full sentence, not a short title. The one-to-four-word title is only for the first roots. A child refines, corrects, or extends its parent. Each new item includes parent_id. Do not restate a lesson, a finding, the parent, or a sibling. To revisit a lesson, put counter #<id> in falsification. A hypothesis you set to supported, narrowed, or refuted includes insight, one or two sentences. If the packet is a falsification query, search it with arxiv first, then web_search. A paper weighs a little more than a web page when both bear on the claim. Read a useful paper with arxiv action read. Otherwise judge that one packet and call action commit. Do not take another packet.";
}
