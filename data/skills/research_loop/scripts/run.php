<?php

$args = json_decode(stream_get_contents(STDIN) ?: '', true);
if (!is_array($args)) {
    $args = [];
}

const RESEARCH_SCHEMA = 1;

const RESEARCH_TABLES = [
    'meta' => ['id', 'schema_version', 'title', 'question', 'scope', 'cycle', 'last_reflect_cycle', 'last_lint_cycle', 'ingest_since_reflect'],
    'sources' => ['id', 'title', 'year', 'authors', 'path', 'url', 'kind', 'status', 'skip_reason', 'dedupe'],
    'chunks' => ['id', 'source_id', 'section', 'text', 'position'],
    'topics' => ['id', 'title', 'title_key', 'synthesis', 'status', 'updated_cycle'],
    'claims' => ['id', 'text', 'norm', 'status', 'topic_id', 'chunk_ids', 'superseded_by', 'cycle'],
    'hypotheses' => ['id', 'statement', 'status', 'confidence', 'claim_ids', 'falsification', 'needs_review'],
    'contradictions' => ['id', 'summary', 'claim_ids', 'chunk_ids', 'settle_note', 'status', 'topic_id'],
    'deltas' => ['id', 'chunk_id', 'kind', 'claim_ids', 'cycle', 'note'],
    'queue' => ['id', 'kind', 'priority', 'payload', 'status', 'leased_at', 'ref'],
];

foreach (['sql', 'table', 'columns', 'database', 'filename'] as $banned) {
    if (array_key_exists($banned, $args)) {
        research_out(['message' => 'research_loop does not take SQL, a table, a column list, or a filename. Pass action and name.'], 1);
    }
}

$action = strtolower(trim((string)($args['action'] ?? '')));
if (!in_array($action, ['mission', 'add', 'next', 'commit', 'review', 'status'], true)) {
    research_out(['message' => 'action is mission, add, next, commit, review, or status.'], 1);
}

if ($action === 'mission') {
    research_mission($args);
}
if ($action === 'status' && trim((string)($args['name'] ?? '')) === '') {
    $slugs = research_slugs();
    if (count($slugs) !== 1) {
        research_out(research_slug_list($slugs), count($slugs) === 0 ? 1 : 0);
    }
    $args['name'] = $slugs[0];
}

$slug = research_slug_arg($args);
$path = research_db_path($slug);
if (!is_file($path)) {
    research_out(['message' => 'No mission named ' . $slug . '. Call mission first. Do not create the database yourself.'], 1);
}
$db = research_connect($path);
research_assert_schema($db);

if ($action === 'add') {
    research_add($db, $slug, $args);
}
if ($action === 'next') {
    research_next($db, $slug);
}
if ($action === 'commit') {
    research_commit($db, $slug, $args);
}
if ($action === 'review') {
    research_review($db, $slug, $args);
}
research_out(['name' => $slug, 'message' => research_status_text($db, $slug), 'view' => research_status_view($db)]);

function research_out(array $data, int $code = 0): void
{
    if (!isset($data['status'])) {
        $data['status'] = $code === 0 ? 'success' : 'error';
    }
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit($code);
}

function research_mission(array $args): void
{
    $title = trim((string)($args['title'] ?? ''));
    $question = trim((string)($args['question'] ?? ''));
    $scope = trim((string)($args['scope'] ?? ''));
    if ($title === '') {
        $title = $question;
    }
    if ($question === '') {
        $question = $title;
    }
    if ($title === '' || $question === '') {
        research_out(['message' => 'Pass question, or title and question.'], 1);
    }
    $slug = research_slugify($title);
    $path = research_db_path($slug);
    if (is_file($path)) {
        $db = research_connect($path);
        research_assert_schema($db);
        $meta = research_meta($db);
        if (research_key($meta['title']) !== research_key($title)) {
            research_out(['message' => 'That slug is already ' . $meta['title'] . '. Pass a more specific title. Do not add a number.'], 1);
        }
        research_out([
            'name' => $slug,
            'existing' => true,
            'message' => 'Mission ' . $slug . ' already exists. Use this slug. Do not create another file or another task.',
        ]);
    }
    $dir = dirname($path);
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        research_out(['message' => 'Could not create the database folder.'], 1);
    }
    $db = research_connect($path);
    research_create_schema($db);
    $insert = $db->prepare('INSERT INTO meta (id, schema_version, title, question, scope) VALUES (1, ?, ?, ?, ?)');
    $insert->execute([RESEARCH_SCHEMA, $title, $question, $scope]);
    research_out([
        'name' => $slug,
        'existing' => false,
        'message' => 'Mission ' . $slug . ' is database/research-' . $slug . '.db. The task is created with this call. Do not create a task and do not write steps.',
    ]);
}

function research_add(PDO $db, string $slug, array $args): void
{
    $title = trim((string)($args['title'] ?? ''));
    $path = trim((string)($args['path'] ?? ''));
    $url = trim((string)($args['url'] ?? ''));
    $text = trim((string)($args['text'] ?? ''));
    $year = trim((string)($args['year'] ?? ''));
    $authors = trim((string)($args['authors'] ?? ''));
    if ($title === '') {
        $title = $path !== '' ? basename($path) : ($url !== '' ? $url : 'Untitled source');
    }
    $kind = 'text';
    $dedupe = '';
    $skip = '';
    if ($path !== '') {
        $kind = 'file';
        $full = research_project_file($path);
        $dedupe = 'path:' . ltrim(str_replace('\\', '/', $path), '/');
        if ($text === '') {
            $text = research_read_source($full, $skip);
        }
    } elseif ($url !== '') {
        $kind = 'url';
        $dedupe = 'url:' . $url;
    } elseif ($text !== '') {
        $dedupe = 'text:' . hash('sha256', $title . "\n" . $text);
    } else {
        research_out(['message' => 'Pass path, url, or text.'], 1);
    }
    $found = $db->prepare('SELECT * FROM sources WHERE dedupe = ?');
    $found->execute([$dedupe]);
    $existing = $found->fetch(PDO::FETCH_ASSOC);
    if ($existing) {
        $count = $db->prepare('SELECT COUNT(*) FROM chunks WHERE source_id = ?');
        $count->execute([(int)$existing['id']]);
        if ((int)$count->fetchColumn() > 0 || $text === '') {
            research_out([
                'name' => $slug,
                'source_id' => (int)$existing['id'],
                'existing' => true,
                'message' => 'Source already registered as #' . (int)$existing['id'] . '.',
            ]);
        }
        $sourceId = (int)$existing['id'];
    } else {
        $status = $text === '' && $skip !== '' ? 'skipped' : 'queued';
        $insert = $db->prepare('INSERT INTO sources (title, year, authors, path, url, kind, status, skip_reason, dedupe) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $insert->execute([$title, $year, $authors, $path, $url, $kind, $status, $skip, $dedupe]);
        $sourceId = (int)$db->lastInsertId();
        if ($status === 'skipped') {
            research_out([
                'name' => $slug,
                'source_id' => $sourceId,
                'message' => 'Source #' . $sourceId . ' skipped. ' . $skip,
            ]);
        }
    }
    if ($text === '') {
        research_enqueue($db, 'ingest', 20, ['source_id' => $sourceId, 'url' => $url], 'ingest:source:' . $sourceId);
        research_out([
            'name' => $slug,
            'source_id' => $sourceId,
            'message' => 'Source #' . $sourceId . ' is queued to fetch. The next pass returns that url.',
        ]);
    }
    $chunks = research_split($text);
    if ($chunks === []) {
        $db->prepare("UPDATE sources SET status = 'skipped', skip_reason = ? WHERE id = ?")->execute(['No text could be read.', $sourceId]);
        research_out(['name' => $slug, 'source_id' => $sourceId, 'message' => 'Source #' . $sourceId . ' skipped. No text could be read.']);
    }
    $ins = $db->prepare('INSERT INTO chunks (source_id, section, text, position) VALUES (?, ?, ?, ?)');
    $n = 0;
    foreach ($chunks as $index => $chunk) {
        $ins->execute([$sourceId, $chunk['section'], $chunk['text'], $index + 1]);
        $chunkId = (int)$db->lastInsertId();
        research_enqueue($db, 'ingest', 20, ['chunk_id' => $chunkId, 'source_id' => $sourceId], 'ingest:chunk:' . $chunkId);
        $n++;
    }
    $db->prepare("UPDATE sources SET status = 'chunked', skip_reason = '' WHERE id = ?")->execute([$sourceId]);
    research_out([
        'name' => $slug,
        'source_id' => $sourceId,
        'chunks' => $n,
        'message' => 'Source #' . $sourceId . ' split into ' . $n . ' chunks.',
    ]);
}

function research_next(PDO $db, string $slug): void
{
    research_reclaim($db);
    $view = research_status_view($db);
    if ((int)$view['hypotheses'] === 0) {
        research_out([
            'name' => $slug,
            'stage' => 'propose',
            'message' => 'No hypotheses yet. Commit kind reflect with hypotheses, a list of at most three. Each item is {"statement":"one sentence","status":"proposed"}. Do not ingest.',
        ]);
    }
    if ((int)$view['proposed'] > 0 && (int)$view['approved'] === 0) {
        research_out([
            'name' => $slug,
            'stage' => 'awaiting',
            'message' => 'Awaiting approval. Do not ingest. The result line is awaiting approval.',
            'view' => $view,
        ]);
    }
    $row = research_queue_row($db, 'reevaluate');
    if ($row) {
        research_lease($db, (int)$row['id']);
        research_out(research_packet($db, $slug, 'reevaluate', $row));
    }
    $meta = research_meta($db);
    $cycle = (int)$meta['cycle'];
    if ($cycle > 0 && $cycle % 50 === 0 && (int)$meta['last_lint_cycle'] < $cycle) {
        research_out(research_lint_packet($db, $slug));
    }
    if ((int)$meta['ingest_since_reflect'] >= 10) {
        research_out(research_reflect_packet($db, $slug));
    }
    $row = research_queue_row($db, 'ingest');
    if ($row) {
        research_lease($db, (int)$row['id']);
        $payload = json_decode((string)$row['payload'], true);
        $stage = is_array($payload) && !empty($payload['url']) && empty($payload['chunk_id']) ? 'fetch' : 'ingest';
        research_out(research_packet($db, $slug, $stage, $row));
    }
    if ((int)$meta['ingest_since_reflect'] > 0) {
        research_out(research_reflect_packet($db, $slug));
    }
    research_out([
        'name' => $slug,
        'stage' => 'idle',
        'message' => 'Idle. No chunks and no reevaluations are waiting.',
        'view' => $view,
    ]);
}

function research_commit(PDO $db, string $slug, array $args): void
{
    $kind = strtolower(trim((string)($args['kind'] ?? '')));
    if (!in_array($kind, ['delta', 'topic', 'reflect', 'lint'], true)) {
        research_out(['message' => 'commit kind is delta, topic, reflect, or lint.'], 1);
    }
    $db->beginTransaction();
    try {
        if ($kind === 'delta') {
            $result = research_commit_delta($db, $args);
        } elseif ($kind === 'topic') {
            $result = research_commit_topic($db, $args);
        } elseif ($kind === 'reflect') {
            $result = research_commit_reflect($db, $args);
        } else {
            $result = research_commit_lint($db);
        }
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        research_out(['message' => $e->getMessage()], 1);
    }
    $result['name'] = $slug;
    $result['view'] = research_status_view($db);
    research_out($result);
}

function research_review(PDO $db, string $slug, array $args): void
{
    $id = (int)($args['id'] ?? 0);
    $decision = strtolower(trim((string)($args['decision'] ?? '')));
    $statement = trim((string)($args['statement'] ?? ''));
    if ($id <= 0) {
        research_out(['message' => 'Pass the hypothesis id.'], 1);
    }
    if (!in_array($decision, ['testing', 'parked', ''], true) || ($decision === '' && $statement === '')) {
        research_out(['message' => 'decision is testing or parked, or pass statement for an edited wording.'], 1);
    }
    $row = research_one($db, 'SELECT * FROM hypotheses WHERE id = ?', [$id]);
    if (!$row) {
        research_out(['message' => 'No hypothesis #' . $id . '.'], 1);
    }
    $status = $decision !== '' ? $decision : (string)$row['status'];
    $text = $statement !== '' ? $statement : (string)$row['statement'];
    if (strlen($text) > 400) {
        research_out(['message' => 'The hypothesis statement is too long.'], 1);
    }
    $db->prepare('UPDATE hypotheses SET status = ?, statement = ?, needs_review = 0 WHERE id = ?')->execute([$status, $text, $id]);
    research_out([
        'name' => $slug,
        'id' => $id,
        'message' => 'Hypothesis #' . $id . ' is ' . $status . '.',
        'view' => research_status_view($db),
    ]);
}

function research_commit_delta(PDO $db, array $args): array
{
    $chunkId = (int)($args['chunk_id'] ?? 0);
    if ($chunkId <= 0) {
        throw new InvalidArgumentException('Pass chunk_id.');
    }
    $chunk = research_one($db, 'SELECT * FROM chunks WHERE id = ?', [$chunkId]);
    if (!$chunk) {
        throw new InvalidArgumentException('No chunk #' . $chunkId . '.');
    }
    $items = $args['items'] ?? null;
    if (!is_array($items) || $items === []) {
        throw new InvalidArgumentException('Pass items, a list of claims judged against this chunk.');
    }
    $meta = research_meta($db);
    $cycle = (int)$meta['cycle'] + 1;
    $touched = [];
    $review = false;
    foreach ($items as $item) {
        if (!is_array($item)) {
            throw new InvalidArgumentException('Each item is an object with kind and text.');
        }
        $kind = strtolower(trim((string)($item['kind'] ?? '')));
        if (!in_array($kind, ['reinforces', 'extends', 'contradicts', 'temporally_invalidates', 'novel'], true)) {
            throw new InvalidArgumentException('Item kind is reinforces, extends, contradicts, temporally_invalidates, or novel.');
        }
        $claimId = (int)($item['claim_id'] ?? 0);
        $text = trim((string)($item['text'] ?? ''));
        if (in_array($kind, ['reinforces', 'contradicts', 'temporally_invalidates'], true)) {
            if ($claimId <= 0) {
                throw new InvalidArgumentException('That kind names claim_id.');
            }
            $claim = research_one($db, 'SELECT * FROM claims WHERE id = ?', [$claimId]);
            if (!$claim) {
                throw new InvalidArgumentException('No claim #' . $claimId . '.');
            }
            if ($kind === 'reinforces') {
                $touched[] = $claimId;
            } else {
                $db->prepare("UPDATE claims SET status = 'contested' WHERE id = ? AND status != 'superseded'")->execute([$claimId]);
                $topicId = (int)$claim['topic_id'];
                $summary = trim((string)($item['summary'] ?? $text));
                if ($summary === '') {
                    $summary = 'Contested by chunk:' . $chunkId;
                }
                $db->prepare('INSERT INTO contradictions (summary, claim_ids, chunk_ids, settle_note, status, topic_id) VALUES (?, ?, ?, ?, ?, ?)')
                    ->execute([$summary, (string)$claimId, (string)$chunkId, trim((string)($item['settle_note'] ?? '')), 'open', $topicId > 0 ? $topicId : null]);
                if ($topicId > 0) {
                    research_enqueue($db, 'reevaluate', 10, [
                        'topic_id' => $topicId,
                        'chunk_id' => $chunkId,
                        'claim_ids' => [$claimId],
                    ], 'reevaluate:topic:' . $topicId . ':chunk:' . $chunkId);
                }
                $touched[] = $claimId;
            }
        } else {
            if ($text === '' || strlen($text) > 500) {
                throw new InvalidArgumentException('A new claim is one sentence, at most 500 characters.');
            }
            $norm = research_key($text);
            $same = research_one($db, 'SELECT id FROM claims WHERE norm = ?', [$norm]);
            if ($same) {
                throw new InvalidArgumentException('That claim already exists as #' . (int)$same['id'] . '. Use reinforces, extends, or contradicts.');
            }
            $topicId = research_topic_id($db, trim((string)($item['topic_title'] ?? 'General')), $cycle);
            $db->prepare('INSERT INTO claims (text, norm, status, topic_id, chunk_ids, superseded_by, cycle) VALUES (?, ?, ?, ?, ?, ?, ?)')
                ->execute([$text, $norm, 'confirmed', $topicId, (string)$chunkId, '', $cycle]);
            $touched[] = (int)$db->lastInsertId();
        }
        $db->prepare('INSERT INTO deltas (chunk_id, kind, claim_ids, cycle, note) VALUES (?, ?, ?, ?, ?)')
            ->execute([$chunkId, $kind, implode(',', $touched), $cycle, trim((string)($item['text'] ?? ''))]);
    }
    research_finish_ref($db, 'ingest:chunk:' . $chunkId);
    $sourceId = (int)$chunk['source_id'];
    $waiting = 0;
    $open = $db->prepare("SELECT payload FROM queue WHERE status != 'done' AND kind = 'ingest'");
    $open->execute();
    foreach ($open->fetchAll(PDO::FETCH_ASSOC) as $queued) {
        $payload = json_decode((string)$queued['payload'], true);
        if (is_array($payload) && (int)($payload['source_id'] ?? 0) === $sourceId) {
            $waiting++;
        }
        if (is_array($payload) && (int)($payload['chunk_id'] ?? 0) > 0) {
            $owner = research_one($db, 'SELECT source_id FROM chunks WHERE id = ?', [(int)$payload['chunk_id']]);
            if ($owner && (int)$owner['source_id'] === $sourceId) {
                $waiting++;
            }
        }
    }
    $db->prepare("UPDATE sources SET status = ? WHERE id = ?")->execute([$waiting > 0 ? 'chunked' : 'done', $sourceId]);
    $db->prepare('UPDATE meta SET cycle = ?, ingest_since_reflect = ingest_since_reflect + 1 WHERE id = 1')->execute([$cycle]);
    return [
        'kind' => 'delta',
        'chunk_id' => $chunkId,
        'claim_ids' => $touched,
        'needs_review' => $review,
        'message' => 'Chunk ' . $chunkId . ' recorded. Claims ' . implode(', ', $touched) . '.',
    ];
}

function research_commit_topic(PDO $db, array $args): array
{
    $topicId = (int)($args['topic_id'] ?? 0);
    $topic = $topicId > 0 ? research_one($db, 'SELECT * FROM topics WHERE id = ?', [$topicId]) : null;
    if (!$topic) {
        throw new InvalidArgumentException('Pass topic_id.');
    }
    $synthesis = trim((string)($args['synthesis'] ?? ''));
    if ($synthesis === '' || strlen($synthesis) > 3200) {
        throw new InvalidArgumentException('Synthesis is required and must stay within 800 tokens.');
    }
    $sent = $args['claims'] ?? null;
    if (!is_array($sent)) {
        throw new InvalidArgumentException('Pass every claim on this topic. A missing claim is rejected.');
    }
    $have = $db->prepare('SELECT * FROM claims WHERE topic_id = ? ORDER BY id');
    $have->execute([$topicId]);
    $rows = $have->fetchAll(PDO::FETCH_ASSOC);
    $byId = [];
    foreach ($sent as $item) {
        if (!is_array($item) || (int)($item['id'] ?? 0) <= 0) {
            throw new InvalidArgumentException('Each claim needs id and status.');
        }
        $byId[(int)$item['id']] = $item;
    }
    foreach ($rows as $row) {
        $id = (int)$row['id'];
        if (!isset($byId[$id])) {
            throw new InvalidArgumentException('Claim #' . $id . ' is missing. Do not drop it.');
        }
    }
    $meta = research_meta($db);
    $cycle = (int)$meta['cycle'] + 1;
    foreach ($byId as $id => $item) {
        $known = false;
        foreach ($rows as $row) {
            if ((int)$row['id'] === $id) {
                $known = true;
                break;
            }
        }
        if (!$known) {
            throw new InvalidArgumentException('Claim #' . $id . ' is not on this topic.');
        }
        $status = strtolower(trim((string)($item['status'] ?? '')));
        if (!in_array($status, ['confirmed', 'contested', 'superseded'], true)) {
            throw new InvalidArgumentException('Claim status is confirmed, contested, or superseded.');
        }
        $by = trim((string)($item['superseded_by'] ?? ''));
        if ($status === 'superseded' && preg_match('/^chunk:\d+$/', $by) !== 1) {
            throw new InvalidArgumentException('A superseded claim needs superseded_by chunk:<id>.');
        }
        if ($status !== 'superseded') {
            $by = '';
        }
        $db->prepare('UPDATE claims SET status = ?, superseded_by = ? WHERE id = ?')->execute([$status, $by, $id]);
    }
    $db->prepare('UPDATE topics SET synthesis = ?, updated_cycle = ?, status = ? WHERE id = ?')
        ->execute([$synthesis, $cycle, 'active', $topicId]);
    $others = $args['topic_ids'] ?? [];
    if (is_array($others)) {
        foreach ($others as $other) {
            $otherId = (int)$other;
            if ($otherId <= 0 || $otherId === $topicId) {
                continue;
            }
            if (!research_one($db, 'SELECT id FROM topics WHERE id = ?', [$otherId])) {
                continue;
            }
            research_enqueue($db, 'reevaluate', 10, ['topic_id' => $otherId, 'chunk_id' => (int)($args['chunk_id'] ?? 0)], 'reevaluate:topic:' . $otherId . ':follow:' . $topicId);
        }
    }
    $chunkId = (int)($args['chunk_id'] ?? 0);
    if ($chunkId > 0) {
        research_finish_ref($db, 'reevaluate:topic:' . $topicId . ':chunk:' . $chunkId);
    }
    research_finish_ref($db, 'reevaluate:shorten:topic:' . $topicId);
    $db->prepare("UPDATE queue SET status = 'done' WHERE kind = 'reevaluate' AND status = 'leased' AND payload LIKE ?")
        ->execute(['%"topic_id":' . $topicId . '%']);
    $db->prepare('UPDATE meta SET cycle = ? WHERE id = 1')->execute([$cycle]);
    return [
        'kind' => 'topic',
        'topic_id' => $topicId,
        'needs_review' => false,
        'message' => 'Topic #' . $topicId . ' updated.',
    ];
}

function research_hypothesis_text(array $item): string
{
    foreach (['statement', 'claim', 'text', 'hypothesis'] as $key) {
        $text = trim((string)($item[$key] ?? ''));
        if ($text !== '') {
            return $text;
        }
    }
    return '';
}

function research_commit_reflect(PDO $db, array $args): array
{
    $items = $args['hypotheses'] ?? $args['hypothesis'] ?? null;
    if (is_string($items)) {
        $decoded = json_decode($items, true);
        $items = is_array($decoded) ? $decoded : null;
    }
    if (is_array($items) && !array_is_list($items)) {
        $items = [$items];
    }
    if (!is_array($items)) {
        throw new InvalidArgumentException('Pass hypotheses, a list. Each item is {"statement":"one sentence","status":"proposed"}.');
    }
    if (count($items) > 3 && research_count($db, 'hypotheses') === 0) {
        throw new InvalidArgumentException('The first pass proposes at most three hypotheses.');
    }
    $review = false;
    foreach ($items as $item) {
        if (!is_array($item)) {
            throw new InvalidArgumentException('Each hypothesis is an object.');
        }
        $statement = research_hypothesis_text($item);
        $status = strtolower(trim((string)($item['status'] ?? 'proposed')));
        $confidence = strtolower(trim((string)($item['confidence'] ?? 'low')));
        if (!in_array($status, ['proposed', 'testing', 'supported', 'narrowed', 'refuted', 'parked'], true)) {
            throw new InvalidArgumentException('Hypothesis status is proposed, testing, supported, narrowed, refuted, or parked.');
        }
        if (!in_array($confidence, ['low', 'moderate', 'high'], true)) {
            throw new InvalidArgumentException('Confidence is low, moderate, or high.');
        }
        if ($statement === '') {
            throw new InvalidArgumentException('Pass statement, one sentence. Each hypothesis is {"statement":"one sentence","status":"proposed"}.');
        }
        if (strlen($statement) > 400) {
            throw new InvalidArgumentException('Shorten the statement to 400 characters.');
        }
        $claimIds = research_id_list($item['claim_ids'] ?? []);
        $falseText = trim((string)($item['falsification'] ?? ''));
        $id = (int)($item['id'] ?? 0);
        if ($id <= 0) {
            if ($status !== 'proposed') {
                throw new InvalidArgumentException('A new hypothesis is proposed. Review sets it to testing.');
            }
            $db->prepare('INSERT INTO hypotheses (statement, status, confidence, claim_ids, falsification, needs_review) VALUES (?, ?, ?, ?, ?, 1)')
                ->execute([$statement, 'proposed', $confidence, $claimIds, $falseText]);
            $review = true;
            continue;
        }
        $current = research_one($db, 'SELECT * FROM hypotheses WHERE id = ?', [$id]);
        if (!$current) {
            throw new InvalidArgumentException('No hypothesis #' . $id . '.');
        }
        $flag = (int)$current['needs_review'];
        if ((string)$current['status'] === 'testing' && in_array($status, ['refuted', 'narrowed'], true)) {
            $flag = 1;
            $review = true;
        }
        $db->prepare('UPDATE hypotheses SET statement = ?, status = ?, confidence = ?, claim_ids = ?, falsification = ?, needs_review = ? WHERE id = ?')
            ->execute([$statement, $status, $confidence, $claimIds, $falseText, $flag, $id]);
    }
    $queries = $args['queries'] ?? [];
    $added = 0;
    if (is_array($queries)) {
        foreach (array_slice($queries, 0, 5) as $query) {
            $query = trim((string)$query);
            if ($query === '' || strlen($query) > 300) {
                continue;
            }
            $dedupe = 'query:' . hash('sha256', $query);
            if (research_one($db, 'SELECT id FROM sources WHERE dedupe = ?', [$dedupe])) {
                continue;
            }
            $db->prepare('INSERT INTO sources (title, year, authors, path, url, kind, status, skip_reason, dedupe) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([$query, '', '', '', '', 'query', 'queued', '', $dedupe]);
            $sourceId = (int)$db->lastInsertId();
            $db->prepare('INSERT INTO chunks (source_id, section, text, position) VALUES (?, ?, ?, ?)')
                ->execute([$sourceId, 'falsification query', $query, 1]);
            $chunkId = (int)$db->lastInsertId();
            research_enqueue($db, 'ingest', 20, ['chunk_id' => $chunkId, 'source_id' => $sourceId, 'query' => $query], 'ingest:chunk:' . $chunkId);
            $added++;
        }
    }
    $meta = research_meta($db);
    $db->prepare('UPDATE meta SET cycle = ?, ingest_since_reflect = 0, last_reflect_cycle = ? WHERE id = 1')
        ->execute([(int)$meta['cycle'] + 1, (int)$meta['cycle'] + 1]);
    return [
        'kind' => 'reflect',
        'needs_review' => $review,
        'queries' => $added,
        'message' => $review ? 'Hypotheses recorded. needs_review.' : 'Hypotheses recorded.',
    ];
}

function research_commit_lint(PDO $db): array
{
    $meta = research_meta($db);
    $cycle = (int)$meta['cycle'];
    $topics = $db->query('SELECT id, synthesis FROM topics')->fetchAll(PDO::FETCH_ASSOC);
    $flagged = 0;
    foreach ($topics as $topic) {
        if (strlen((string)$topic['synthesis']) <= 3200) {
            continue;
        }
        research_enqueue($db, 'reevaluate', 10, ['topic_id' => (int)$topic['id'], 'shorten' => true], 'reevaluate:shorten:topic:' . (int)$topic['id']);
        $flagged++;
    }
    $db->prepare('UPDATE meta SET last_lint_cycle = ? WHERE id = 1')->execute([$cycle]);
    return [
        'kind' => 'lint',
        'needs_review' => false,
        'message' => 'Lint recorded. ' . $flagged . ' topics queued to be shortened. Resolved contradictions stay.',
    ];
}

function research_packet(PDO $db, string $slug, string $stage, array $row): array
{
    $payload = json_decode((string)$row['payload'], true);
    if (!is_array($payload)) {
        $payload = [];
    }
    $packet = [
        'name' => $slug,
        'stage' => $stage,
        'queue_id' => (int)$row['id'],
    ];
    if (!empty($payload['url']) && empty($payload['chunk_id'])) {
        $source = research_one($db, 'SELECT id, title, year, url FROM sources WHERE id = ?', [(int)($payload['source_id'] ?? 0)]);
        $packet['source'] = $source ?: [];
        $packet['message'] = 'Fetch this url, then call add with name, url, title, and text. Do not judge an empty page.';
        return $packet;
    }
    $chunkId = (int)($payload['chunk_id'] ?? 0);
    $chunk = $chunkId > 0 ? research_one($db, 'SELECT c.*, s.title AS source_title, s.year AS source_year, s.id AS source_id FROM chunks c JOIN sources s ON s.id = c.source_id WHERE c.id = ?', [$chunkId]) : null;
    if ($stage === 'reevaluate') {
        $topicId = (int)($payload['topic_id'] ?? 0);
        $topic = research_one($db, 'SELECT id, title, synthesis, status FROM topics WHERE id = ?', [$topicId]);
        $claims = $db->prepare('SELECT id, text, status, chunk_ids, superseded_by FROM claims WHERE topic_id = ? ORDER BY id');
        $claims->execute([$topicId]);
        $packet['topic'] = $topic ?: [];
        $packet['claims'] = $claims->fetchAll(PDO::FETCH_ASSOC);
        $packet['chunk'] = $chunk ? research_public_chunk($chunk) : null;
        $packet['cited_chunks'] = research_cited_chunks($db, $packet['claims'], $chunkId);
        $packet['shorten'] = !empty($payload['shorten']) || ($topic && strlen((string)$topic['synthesis']) > 3200);
        $packet['message'] = 'Reevaluate topic #' . $topicId . ' only. Then commit kind topic.';
        return $packet;
    }
    $packet['chunk_id'] = $chunkId;
    $packet['text'] = $chunk ? (string)$chunk['text'] : '';
    $packet['section'] = $chunk ? (string)$chunk['section'] : '';
    $packet['source'] = $chunk ? ['id' => (int)$chunk['source_id'], 'title' => (string)$chunk['source_title'], 'year' => (string)$chunk['source_year']] : [];
    $packet['claims'] = $chunk ? research_overlap($db, (string)$chunk['text']) : [];
    $packet['topics'] = research_topic_lines($db, (string)($packet['text'] ?? ''));
    $packet['message'] = $packet['section'] === 'falsification query'
        ? 'This chunk is a falsification query. Search it, then commit kind delta for this chunk_id.'
        : 'Judge this chunk only. Then commit kind delta for this chunk_id.';
    return $packet;
}

function research_reflect_packet(PDO $db, string $slug): array
{
    $view = research_status_view($db);
    return [
        'name' => $slug,
        'stage' => 'reflect',
        'hypotheses' => $view['lines'],
        'contradictions' => $view['open_contradictions'],
        'counts' => [
            'sources_left' => $view['ingest'],
            'open_contradictions' => count($view['open_contradictions']),
            'cycle' => $view['cycle'],
        ],
        'message' => 'Reflect on the hypotheses only. Commit kind reflect. Do not read a paper in this pass.',
    ];
}

function research_lint_packet(PDO $db, string $slug): array
{
    $over = [];
    foreach ($db->query('SELECT id, title, synthesis FROM topics') as $topic) {
        if (strlen((string)$topic['synthesis']) > 3200) {
            $over[] = ['id' => (int)$topic['id'], 'title' => (string)$topic['title']];
        }
    }
    $resolved = $db->query("SELECT id, summary FROM contradictions WHERE status = 'resolved'")->fetchAll(PDO::FETCH_ASSOC);
    return [
        'name' => $slug,
        'stage' => 'lint',
        'over_cap' => $over,
        'resolved' => $resolved,
        'message' => 'Commit kind lint. The skill merges a duplicate title and leaves resolved contradictions in place. A long synthesis is queued to be shortened.',
    ];
}

function research_status_text(PDO $db, string $slug): string
{
    $view = research_status_view($db);
    $lines = ['Mission: ' . $view['title'], 'Question: ' . $view['question'], 'Slug: ' . $slug];
    if ($view['lines'] === []) {
        $lines[] = 'Hypotheses: none yet. The researcher commits kind reflect. Each item is {"statement":"one sentence","status":"proposed"}. At most three.';
    } else {
        $lines[] = 'Hypotheses:';
        foreach ($view['lines'] as $line) {
            $lines[] = '- #' . $line['id'] . ' [' . $line['status'] . '] ' . $line['statement'];
        }
    }
    $lines[] = 'Open contradictions: ' . count($view['open_contradictions']);
    $lines[] = 'Queue: ingest ' . $view['ingest'] . ', reevaluate ' . $view['reevaluate'];
    $lines[] = 'Cycle: ' . $view['cycle'];
    if ((int)$view['proposed'] > 0 && (int)$view['approved'] === 0) {
        $lines[] = 'awaiting approval';
    }
    if ((int)$view['needs_review'] > 0) {
        $lines[] = 'needs_review';
    }
    return implode("\n", $lines);
}

function research_status_view(PDO $db): array
{
    $meta = research_meta($db);
    $lines = $db->query('SELECT id, statement, status, confidence, needs_review FROM hypotheses ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    $open = $db->query("SELECT id, summary, claim_ids, chunk_ids, topic_id FROM contradictions WHERE status = 'open' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    $proposed = 0;
    $approved = 0;
    $needs = 0;
    foreach ($lines as $line) {
        if ((string)$line['status'] === 'proposed') {
            $proposed++;
        }
        if (in_array((string)$line['status'], ['testing', 'supported', 'narrowed', 'refuted'], true)) {
            $approved++;
        }
        if ((int)$line['needs_review'] === 1) {
            $needs++;
        }
    }
    return [
        'title' => (string)$meta['title'],
        'question' => (string)$meta['question'],
        'scope' => (string)$meta['scope'],
        'cycle' => (int)$meta['cycle'],
        'lines' => $lines,
        'open_contradictions' => $open,
        'hypotheses' => count($lines),
        'proposed' => $proposed,
        'approved' => $approved,
        'needs_review' => $needs,
        'ingest' => research_count_where($db, "SELECT COUNT(*) FROM queue WHERE kind = 'ingest' AND status != 'done'"),
        'reevaluate' => research_count_where($db, "SELECT COUNT(*) FROM queue WHERE kind = 'reevaluate' AND status != 'done'"),
    ];
}

function research_slug_list(array $slugs): array
{
    if ($slugs === []) {
        return ['message' => 'No mission yet. Call mission with the question.'];
    }
    return [
        'names' => $slugs,
        'message' => "Several missions. Pass name.\n- " . implode("\n- ", $slugs),
    ];
}

function research_slugs(): array
{
    $dir = getcwd() . '/database';
    if (!is_dir($dir)) {
        return [];
    }
    $out = [];
    foreach (scandir($dir) ?: [] as $name) {
        if (preg_match('/^research-([a-z0-9-]{1,40})\.db$/', $name, $match) === 1) {
            $out[] = $match[1];
        }
    }
    sort($out);
    return $out;
}

function research_slug_arg(array $args): string
{
    $name = strtolower(trim((string)($args['name'] ?? '')));
    if (str_contains($name, '/') || str_contains($name, '.')) {
        research_out(['message' => 'Pass name as the slug, such as uap-propulsion. Do not pass a filename.'], 1);
    }
    $name = preg_replace('/^research-/', '', $name) ?? $name;
    if (preg_match('/^[a-z0-9-]{1,40}$/', $name) !== 1) {
        research_out(['message' => 'Pass name as the slug, such as uap-propulsion. Do not pass a filename.'], 1);
    }
    return $name;
}

function research_slugify(string $title): string
{
    $slug = strtolower($title);
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
    $slug = trim($slug, '-');
    if (strlen($slug) > 40) {
        $slug = rtrim(substr($slug, 0, 40), '-');
    }
    if ($slug === '' || preg_match('/^[a-z0-9-]{1,40}$/', $slug) !== 1) {
        research_out(['message' => 'The mission title needs a letter or a digit.'], 1);
    }
    return $slug;
}

function research_db_path(string $slug): string
{
    return getcwd() . '/database/research-' . $slug . '.db';
}

function research_connect(string $path): PDO
{
    $db = new PDO('sqlite:' . $path, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    $db->exec('PRAGMA foreign_keys = ON');
    return $db;
}

function research_create_schema(PDO $db): void
{
    $db->exec('CREATE TABLE meta (
        id INTEGER PRIMARY KEY CHECK (id = 1),
        schema_version INTEGER NOT NULL,
        title TEXT NOT NULL,
        question TEXT NOT NULL,
        scope TEXT NOT NULL DEFAULT \'\',
        cycle INTEGER NOT NULL DEFAULT 0,
        last_reflect_cycle INTEGER NOT NULL DEFAULT 0,
        last_lint_cycle INTEGER NOT NULL DEFAULT 0,
        ingest_since_reflect INTEGER NOT NULL DEFAULT 0
    )');
    $db->exec("CREATE TABLE sources (
        id INTEGER PRIMARY KEY,
        title TEXT NOT NULL,
        year TEXT NOT NULL DEFAULT '',
        authors TEXT NOT NULL DEFAULT '',
        path TEXT NOT NULL DEFAULT '',
        url TEXT NOT NULL DEFAULT '',
        kind TEXT NOT NULL DEFAULT 'file',
        status TEXT NOT NULL DEFAULT 'queued',
        skip_reason TEXT NOT NULL DEFAULT '',
        dedupe TEXT NOT NULL UNIQUE
    )");
    $db->exec("CREATE TABLE chunks (
        id INTEGER PRIMARY KEY,
        source_id INTEGER NOT NULL,
        section TEXT NOT NULL DEFAULT '',
        text TEXT NOT NULL,
        position INTEGER NOT NULL
    )");
    $db->exec("CREATE TABLE topics (
        id INTEGER PRIMARY KEY,
        title TEXT NOT NULL,
        title_key TEXT NOT NULL UNIQUE,
        synthesis TEXT NOT NULL DEFAULT '',
        status TEXT NOT NULL DEFAULT 'active',
        updated_cycle INTEGER NOT NULL DEFAULT 0
    )");
    $db->exec("CREATE TABLE claims (
        id INTEGER PRIMARY KEY,
        text TEXT NOT NULL,
        norm TEXT NOT NULL,
        status TEXT NOT NULL,
        topic_id INTEGER,
        chunk_ids TEXT NOT NULL DEFAULT '',
        superseded_by TEXT NOT NULL DEFAULT '',
        cycle INTEGER NOT NULL
    )");
    $db->exec("CREATE TABLE hypotheses (
        id INTEGER PRIMARY KEY,
        statement TEXT NOT NULL,
        status TEXT NOT NULL,
        confidence TEXT NOT NULL DEFAULT 'low',
        claim_ids TEXT NOT NULL DEFAULT '',
        falsification TEXT NOT NULL DEFAULT '',
        needs_review INTEGER NOT NULL DEFAULT 0
    )");
    $db->exec("CREATE TABLE contradictions (
        id INTEGER PRIMARY KEY,
        summary TEXT NOT NULL,
        claim_ids TEXT NOT NULL DEFAULT '',
        chunk_ids TEXT NOT NULL DEFAULT '',
        settle_note TEXT NOT NULL DEFAULT '',
        status TEXT NOT NULL DEFAULT 'open',
        topic_id INTEGER
    )");
    $db->exec("CREATE TABLE deltas (
        id INTEGER PRIMARY KEY,
        chunk_id INTEGER NOT NULL,
        kind TEXT NOT NULL,
        claim_ids TEXT NOT NULL DEFAULT '',
        cycle INTEGER NOT NULL,
        note TEXT NOT NULL DEFAULT ''
    )");
    $db->exec("CREATE TABLE queue (
        id INTEGER PRIMARY KEY,
        kind TEXT NOT NULL,
        priority INTEGER NOT NULL,
        payload TEXT NOT NULL,
        status TEXT NOT NULL DEFAULT 'queued',
        leased_at TEXT,
        ref TEXT NOT NULL
    )");
    $db->exec('CREATE INDEX idx_queue_status ON queue(status, priority, id)');
    $db->exec('CREATE INDEX idx_claims_norm ON claims(norm)');
}

function research_assert_schema(PDO $db): void
{
    $tables = [];
    foreach ($db->query("SELECT name FROM sqlite_master WHERE type = 'table'") as $row) {
        $name = (string)$row['name'];
        if (str_starts_with($name, 'sqlite_')) {
            continue;
        }
        $tables[] = $name;
    }
    foreach ($tables as $name) {
        if (!isset(RESEARCH_TABLES[$name])) {
            research_out(['message' => 'research database schema does not match. Unexpected table ' . $name . '. It was not changed.'], 1);
        }
    }
    foreach (RESEARCH_TABLES as $name => $columns) {
        if (!in_array($name, $tables, true)) {
            research_out(['message' => 'research database schema does not match. Missing table ' . $name . '. It was not changed.'], 1);
        }
        $have = [];
        $info = $db->query('PRAGMA table_info(' . $name . ')');
        foreach ($info as $col) {
            $have[] = (string)$col['name'];
        }
        $extra = array_values(array_diff($have, $columns));
        $missing = array_values(array_diff($columns, $have));
        if ($extra !== []) {
            research_out(['message' => 'research database schema does not match. Unexpected column ' . $extra[0] . ' on ' . $name . '. It was not dropped.'], 1);
        }
        if ($missing !== []) {
            research_out(['message' => 'research database schema does not match. Missing column ' . $missing[0] . ' on ' . $name . '. It was not changed.'], 1);
        }
    }
    $version = (int)$db->query('SELECT schema_version FROM meta WHERE id = 1')->fetchColumn();
    if ($version !== RESEARCH_SCHEMA) {
        research_out(['message' => 'research database schema version does not match. It was not changed.'], 1);
    }
}

function research_meta(PDO $db): array
{
    $row = research_one($db, 'SELECT * FROM meta WHERE id = 1', []);
    if (!$row) {
        research_out(['message' => 'The mission row is missing. The database was not changed.'], 1);
    }
    return $row;
}

function research_one(PDO $db, string $sql, array $params): ?array
{
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function research_count(PDO $db, string $table): int
{
    if (!isset(RESEARCH_TABLES[$table])) {
        return 0;
    }
    return (int)$db->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn();
}

function research_count_where(PDO $db, string $sql): int
{
    return (int)$db->query($sql)->fetchColumn();
}

function research_enqueue(PDO $db, string $kind, int $priority, array $payload, string $ref): void
{
    $stmt = $db->prepare("SELECT id FROM queue WHERE ref = ? AND status != 'done'");
    $stmt->execute([$ref]);
    if ($stmt->fetch()) {
        return;
    }
    $insert = $db->prepare('INSERT INTO queue (kind, priority, payload, status, ref) VALUES (?, ?, ?, ?, ?)');
    $insert->execute([$kind, $priority, json_encode($payload, JSON_UNESCAPED_UNICODE), 'queued', $ref]);
}

function research_reclaim(PDO $db): void
{
    $db->exec("UPDATE queue SET status = 'queued', leased_at = NULL WHERE status = 'leased' AND leased_at IS NOT NULL AND leased_at <= datetime('now', '-20 minutes')");
}

function research_queue_row(PDO $db, string $kind): ?array
{
    return research_one($db, "SELECT * FROM queue WHERE kind = ? AND status = 'queued' ORDER BY priority, id LIMIT 1", [$kind]);
}

function research_lease(PDO $db, int $id): void
{
    $db->prepare("UPDATE queue SET status = 'leased', leased_at = datetime('now') WHERE id = ?")->execute([$id]);
}

function research_finish_ref(PDO $db, string $ref): void
{
    $db->prepare("UPDATE queue SET status = 'done' WHERE ref = ? AND status != 'done'")->execute([$ref]);
}

function research_topic_id(PDO $db, string $title, int $cycle): int
{
    if ($title === '') {
        $title = 'General';
    }
    $key = research_key($title);
    $row = research_one($db, 'SELECT id FROM topics WHERE title_key = ?', [$key]);
    if ($row) {
        return (int)$row['id'];
    }
    $db->prepare('INSERT INTO topics (title, title_key, synthesis, status, updated_cycle) VALUES (?, ?, ?, ?, ?)')
        ->execute([$title, $key, '', 'active', $cycle]);
    return (int)$db->lastInsertId();
}

function research_key(string $text): string
{
    $text = strtolower($text);
    $text = preg_replace('/[^a-z0-9]+/', ' ', $text) ?? '';
    $text = trim(preg_replace('/\s+/', ' ', $text) ?? '');
    return $text;
}

function research_id_list(mixed $value): string
{
    if (!is_array($value)) {
        $value = $value === null || $value === '' ? [] : explode(',', (string)$value);
    }
    $ids = [];
    foreach ($value as $id) {
        $id = (int)$id;
        if ($id > 0) {
            $ids[] = (string)$id;
        }
    }
    return implode(',', $ids);
}

function research_split(string $text): array
{
    $text = trim(str_replace("\r\n", "\n", $text));
    if ($text === '') {
        return [];
    }
    $max = 4800;
    $overlap = 600;
    if (strlen($text) <= $max) {
        return [['section' => '', 'text' => $text]];
    }
    $parts = preg_split("/\n\s*\n/", $text) ?: [$text];
    $chunks = [];
    $buf = '';
    $section = '';
    $flush = function () use (&$chunks, &$buf, &$section, $overlap): void {
        $buf = trim($buf);
        if ($buf === '') {
            return;
        }
        $chunks[] = ['section' => $section, 'text' => $buf];
        $buf = substr($buf, -$overlap);
    };
    foreach ($parts as $part) {
        $part = trim($part);
        if ($part === '') {
            continue;
        }
        if (preg_match('/^#{1,6}\s+(.+)/', $part, $match) === 1) {
            $section = trim($match[1]);
        }
        while (strlen($part) > $max) {
            if ($buf !== '') {
                $flush();
            }
            $chunks[] = ['section' => $section, 'text' => substr($part, 0, $max)];
            $part = substr($part, $max - $overlap);
        }
        if ($buf !== '' && strlen($buf) + strlen($part) + 2 > $max) {
            $flush();
        }
        $buf = $buf === '' ? $part : $buf . "\n\n" . $part;
    }
    $flush = null;
    $buf = trim($buf);
    if ($buf !== '') {
        $chunks[] = ['section' => $section, 'text' => $buf];
    }
    return $chunks;
}

function research_read_source(string $path, string &$skip): string
{
    $skip = '';
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    if ($ext === 'pdf') {
        $text = research_pdftotext($path);
        if ($text === '') {
            $skip = 'No text could be read. Pass text from read_pdf on add.';
        }
        return $text;
    }
    if (!in_array($ext, ['txt', 'md', 'html', 'htm'], true)) {
        $skip = 'This source is not text or a pdf. Pass text on add.';
        return '';
    }
    $text = file_get_contents($path);
    if (!is_string($text) || trim($text) === '') {
        $skip = 'No text could be read.';
        return '';
    }
    return trim($text);
}

function research_pdftotext(string $path): string
{
    $bin = trim((string)shell_exec('command -v pdftotext 2>/dev/null'));
    if ($bin === '') {
        return '';
    }
    $cmd = escapeshellarg($bin) . ' -q -enc UTF-8 -layout ' . escapeshellarg($path) . ' -';
    $text = shell_exec($cmd);
    return is_string($text) ? trim($text) : '';
}

function research_project_file(string $path): string
{
    $path = str_replace('\\', '/', $path);
    if ($path === '' || str_contains($path, "\0") || preg_match('#(^|/)\.\.(/|$)#', $path) === 1) {
        research_out(['message' => 'Path must stay inside the project.'], 1);
    }
    $root = realpath(getcwd() ?: '.');
    if ($root === false) {
        research_out(['message' => 'The project folder is not available.'], 1);
    }
    $full = $root . '/' . ltrim($path, '/');
    $real = realpath($full);
    if ($real === false || !str_starts_with($real, $root . DIRECTORY_SEPARATOR) && $real !== $root) {
        research_out(['message' => 'Path must stay inside the project.'], 1);
    }
    if (!is_file($real)) {
        research_out(['message' => 'File not found.'], 1);
    }
    return $real;
}

function research_overlap(PDO $db, string $text): array
{
    $words = research_words($text);
    if ($words === []) {
        return [];
    }
    $scored = [];
    foreach ($db->query('SELECT id, text, status, topic_id FROM claims') as $claim) {
        $theirs = research_words((string)$claim['text']);
        $score = count(array_intersect($words, $theirs));
        if ($score < 2) {
            continue;
        }
        $scored[] = ['score' => $score, 'row' => $claim];
    }
    usort($scored, static fn(array $a, array $b): int => $b['score'] <=> $a['score']);
    $out = [];
    foreach (array_slice($scored, 0, 8) as $hit) {
        $row = $hit['row'];
        $out[] = [
            'id' => (int)$row['id'],
            'status' => (string)$row['status'],
            'topic_id' => (int)$row['topic_id'],
            'text' => (string)$row['text'],
        ];
    }
    return $out;
}

function research_topic_lines(PDO $db, string $text): array
{
    $words = research_words($text);
    $scored = [];
    foreach ($db->query('SELECT id, title, synthesis FROM topics') as $topic) {
        $theirs = research_words((string)$topic['title'] . ' ' . (string)$topic['synthesis']);
        $score = count(array_intersect($words, $theirs));
        if ($score < 1) {
            continue;
        }
        $scored[] = ['score' => $score, 'row' => $topic];
    }
    usort($scored, static fn(array $a, array $b): int => $b['score'] <=> $a['score']);
    $out = [];
    foreach (array_slice($scored, 0, 8) as $hit) {
        $row = $hit['row'];
        $synthesis = (string)$row['synthesis'];
        if (strlen($synthesis) > 240) {
            $synthesis = substr($synthesis, 0, 240);
        }
        $out[] = ['id' => (int)$row['id'], 'title' => (string)$row['title'], 'synthesis' => $synthesis];
    }
    return $out;
}

function research_words(string $text): array
{
    preg_match_all('/[a-z0-9]{4,}/i', strtolower($text), $match);
    return array_values(array_unique($match[0] ?? []));
}

function research_public_chunk(array $chunk): array
{
    return [
        'id' => (int)$chunk['id'],
        'section' => (string)$chunk['section'],
        'text' => (string)$chunk['text'],
        'source_title' => (string)($chunk['source_title'] ?? ''),
        'source_year' => (string)($chunk['source_year'] ?? ''),
    ];
}

function research_cited_chunks(PDO $db, array $claims, int $except): array
{
    $ids = [];
    foreach ($claims as $claim) {
        foreach (explode(',', (string)($claim['chunk_ids'] ?? '')) as $id) {
            $id = (int)$id;
            if ($id > 0 && $id !== $except) {
                $ids[$id] = true;
            }
        }
    }
    $out = [];
    foreach (array_slice(array_keys($ids), 0, 3) as $id) {
        $row = research_one($db, 'SELECT id, section, text FROM chunks WHERE id = ?', [$id]);
        if (!$row) {
            continue;
        }
        $text = (string)$row['text'];
        if (strlen($text) > 2000) {
            $text = substr($text, 0, 2000);
        }
        $out[] = ['id' => (int)$row['id'], 'section' => (string)$row['section'], 'text' => $text];
    }
    return $out;
}
