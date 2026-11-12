<?php
$args = json_decode(stream_get_contents(STDIN) ?: '', true);
if (!is_array($args)) {
    $args = [];
}
$file = str_replace('\\', '/', trim((string)($args['file'] ?? '')));
$lang = strtolower(trim((string)($args['lang'] ?? 'php')));
$root = realpath(getcwd() ?: '.');
if ($file === '' || str_contains($file, "\0") || preg_match('#(^|/)\.\.(/|$)#', $file)) {
    echo json_encode(['status' => 'error', 'message' => 'Name a file inside the project.']);
    exit(1);
}
$target = $root . '/' . ltrim($file, '/');
$real = realpath($target);
if ($root === false || $real === false || !is_file($real) || !str_starts_with($real, $root . DIRECTORY_SEPARATOR)) {
    echo json_encode(['status' => 'error', 'message' => 'File not found: ' . $file]);
    exit(1);
}
if ($lang === 'php') {
    $output = shell_exec('php -l ' . escapeshellarg($real) . ' 2>&1');
    $output = trim((string)$output);
    $passed = str_contains($output, 'No syntax errors detected');
    echo json_encode([
        'status' => $passed ? 'passed' : 'failed',
        'message' => $passed ? 'Syntax check clean.' : 'Syntax errors found.',
        'output' => $output,
    ]);
    exit($passed ? 0 : 1);
}
if ($lang === 'python') {
    $output = shell_exec('python3 -m py_compile ' . escapeshellarg($real) . ' 2>&1');
    $output = trim((string)$output);
    $passed = $output === '';
    echo json_encode([
        'status' => $passed ? 'passed' : 'failed',
        'message' => $passed ? 'Python compilation clean.' : 'Python syntax errors.',
        'output' => $output,
    ]);
    exit($passed ? 0 : 1);
}
echo json_encode(['status' => 'passed', 'message' => 'Language syntax check skipped for ' . $lang . '.']);
