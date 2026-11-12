<?php
$raw = stream_get_contents(STDIN);
$args = json_decode($raw ?: '', true);
if (!is_array($args)) {
    $args = [];
}
$note = trim((string)($args['note'] ?? ''));
if ($note === '') {
    fwrite(STDERR, "Missing note.\n");
    exit(1);
}
if (!is_dir('wiki') && !mkdir('wiki', 0775, true) && !is_dir('wiki')) {
    fwrite(STDERR, "Could not create the wiki folder.\n");
    exit(1);
}
$path = 'wiki/NOTES.md';
$legacy = 'wiki/notes.md';
if (!is_file($path) && is_file($legacy) && !@rename($legacy, $path)) {
    fwrite(STDERR, "Could not create the notes file.\n");
    exit(1);
}
if (!is_file($path) && file_put_contents($path, "# Notes\n\n") === false) {
    fwrite(STDERR, "Could not create the notes file.\n");
    exit(1);
}
if (file_put_contents($path, $note . PHP_EOL, FILE_APPEND) === false) {
    fwrite(STDERR, "Could not save the note.\n");
    exit(1);
}
echo "Saved note.\n";
