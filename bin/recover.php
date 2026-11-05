<?php

require __DIR__ . '/../src/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Run this from the command line.\n");
    exit(1);
}

$error = trim((string)($argv[1] ?? ''));
$result = (new Recovery(Database::instance()))->repair($error);
$note = trim((string)($result['note'] ?? ''));
if ($note !== '') {
    fwrite(STDOUT, $note . "\n");
}
exit(!empty($result['retry']) ? 0 : 2);
