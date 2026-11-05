<?php
/*
---
name: research_pipeline
description: Appends a research log to its own wiki file, wiki/research.md by default. Never writes the project description. args query, notes, and optional name for a different wiki markdown file.
---
*/
$args = json_decode(stream_get_contents(STDIN) ?: '', true);
if (!is_array($args)) {
    $args = [];
}
$query = trim((string)($args['query'] ?? ''));
$notes = trim((string)($args['notes'] ?? ''));
if ($query === '') {
    echo json_encode(['status' => 'error', 'message' => 'Pass a query.']);
    exit(1);
}
$name = basename(str_replace('\\', '/', trim((string)($args['name'] ?? 'research.md'))));
if ($name === '') {
    $name = 'research.md';
}
if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,80}\.md$/', $name) || preg_match('/_desc\.md$/', $name)) {
    echo json_encode(['status' => 'error', 'message' => 'Research belongs in its own wiki markdown file, not the project description.']);
    exit(1);
}
$wikiDir = 'wiki';
if (!is_dir($wikiDir) && !mkdir($wikiDir, 0775, true) && !is_dir($wikiDir)) {
    echo json_encode(['status' => 'error', 'message' => 'Could not create the wiki folder.']);
    exit(1);
}
$wiki = $wikiDir . '/' . $name;
$entry = "\n\n### Research log [" . date('Y-m-d H:i') . "]\n- **Query:** " . $query . "\n";
if ($notes !== '') {
    $entry .= "- **Findings:** " . $notes . "\n";
}
if (!is_file($wiki)) {
    $entry = '# ' . pathinfo($name, PATHINFO_FILENAME) . "\n" . $entry;
}
if (file_put_contents($wiki, $entry, FILE_APPEND) === false) {
    echo json_encode(['status' => 'error', 'message' => 'Could not write the research file.']);
    exit(1);
}
echo json_encode(['status' => 'success', 'message' => 'Research log appended to wiki/' . $name . '.']);
