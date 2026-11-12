<?php
$args = json_decode(stream_get_contents(STDIN) ?: '', true);
if (!is_array($args)) {
    $args = [];
}

$rules = <<<'TXT'
Large download. Use this for a file over 100 MB so the chat is not held up. A small page fetch does not need it.
Pick the tool in this order: aria2c, then wget, then curl. Keep resume and retries on, and run it in the background.
If it stops early, call this skill again with the same url and path. Do not start a second copy or a fresh file.
When it finishes, the file should exist and the size should look right. Pass sha256 when the source published one.

TXT;

$url = trim((string)($args['url'] ?? ''));
$path = str_replace('\\', '/', trim((string)($args['path'] ?? '')));
$sha = strtolower(trim((string)($args['sha256'] ?? '')));
if ($url === '' && $path === '') {
    echo $rules;
    exit(0);
}
if ($url === '' || $path === '') {
    fwrite(STDERR, "Pass url and path.\n");
    exit(1);
}
if ($sha !== '' && !preg_match('/^[a-f0-9]{64}$/', $sha)) {
    fwrite(STDERR, "sha256 must be 64 hex characters.\n");
    exit(1);
}

$parts = parse_url($url);
$scheme = strtolower((string)($parts['scheme'] ?? ''));
$host = strtolower((string)($parts['host'] ?? ''));
if (!in_array($scheme, ['http', 'https'], true) || $host === '' || $host === 'localhost' || str_ends_with($host, '.local')) {
    fwrite(STDERR, "Only a public http or https URL is allowed.\n");
    exit(1);
}
$ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);
if ($ips === []) {
    fwrite(STDERR, "Could not resolve that host.\n");
    exit(1);
}
foreach ($ips as $ip) {
    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
        fwrite(STDERR, "That address is not a public host.\n");
        exit(1);
    }
}
if ($path === '' || str_contains($path, "\0") || preg_match('#(^|/)\.\.(/|$)#', $path) || str_starts_with($path, 'wiki/')) {
    fwrite(STDERR, "Name a file inside the project, outside the wiki.\n");
    exit(1);
}
$name = basename($path);
if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,120}$/', $name)) {
    fwrite(STDERR, "Use a plain file name.\n");
    exit(1);
}

$root = realpath(getcwd() ?: '.');
$dir = dirname($path);
if ($dir === '.' || $dir === '') {
    $parent = $root;
} else {
    $parent = ($root ?: '') . '/' . $dir;
    if (!is_dir($parent) && !mkdir($parent, 0775, true) && !is_dir($parent)) {
        fwrite(STDERR, "Could not create the folder.\n");
        exit(1);
    }
    $parent = realpath($parent) ?: '';
}
if ($root === false || $parent === '' || ($parent !== $root && !str_starts_with($parent, $root . DIRECTORY_SEPARATOR))) {
    fwrite(STDERR, "Name a file inside the project, outside the wiki.\n");
    exit(1);
}
$dest = $parent . '/' . $name;
$rel = ltrim(substr($dest, strlen($root)), '/');
$pidFile = $dest . '.download.pid';
$exitFile = $dest . '.download.exit';
$logRel = $rel . '.download.log';

function download_alive(int $pid): bool
{
    if ($pid <= 1) {
        return false;
    }
    if (function_exists('posix_kill')) {
        return posix_kill($pid, 0);
    }
    $out = trim((string)shell_exec('ps -p ' . $pid . ' -o pid='));
    return $out !== '';
}

function download_tool(): string
{
    foreach (['aria2c', 'wget', 'curl'] as $name) {
        $found = trim((string)shell_exec('command -v ' . escapeshellarg($name) . ' 2>/dev/null'));
        if ($found !== '') {
            return $name;
        }
    }
    return '';
}

$pid = is_file($pidFile) ? (int)trim((string)file_get_contents($pidFile)) : 0;
$running = download_alive($pid);
$size = is_file($dest) ? (int)filesize($dest) : 0;
$finished = is_file($exitFile) && trim((string)file_get_contents($exitFile)) === '0';
echo $rules;
if ($running) {
    echo "Still running with pid {$pid}.\nPath: {$rel}\nSize: {$size} bytes\nLog: {$logRel}\n";
    exit(0);
}
if ($finished && is_file($dest) && $sha === '') {
    echo "Download finished.\nPath: {$rel}\nSize: {$size} bytes\n";
    exit(0);
}
if ($sha !== '' && is_file($dest) && $size > 0) {
    if ($size > 2147483648) {
        echo "File is {$size} bytes. It is too large to hash in this turn.\nPath: {$rel}\n";
        exit(0);
    }
    $actual = hash_file('sha256', $dest);
    if ($actual === $sha) {
        echo "Checksum matches.\nPath: {$rel}\nSize: {$size} bytes\n";
        exit(0);
    }
    echo "Checksum does not match.\nPath: {$rel}\nSize: {$size} bytes\n";
    exit(1);
}

$tool = download_tool();
if ($tool === '') {
    fwrite(STDERR, "No downloader found. Install aria2c, wget, or curl.\n");
    exit(1);
}
if ($tool === 'aria2c') {
    $cmd = 'aria2c -x 8 -s 8 -k 1M --file-allocation=none --continue=true --retry-wait=5 --max-tries=10 --summary-interval=30 -d '
        . escapeshellarg($parent) . ' -o ' . escapeshellarg($name) . ' ' . escapeshellarg($url);
} elseif ($tool === 'wget') {
    $cmd = 'wget -c --tries=10 --timeout=30 --waitretry=5 --read-timeout=30 -O ' . escapeshellarg($dest) . ' ' . escapeshellarg($url);
} else {
    $retryAll = str_contains((string)shell_exec('curl --help all 2>&1'), 'retry-all-errors') ? ' --retry-all-errors' : '';
    $cmd = 'curl -L --retry 10' . $retryAll . ' --connect-timeout 30 --continue-at - -o ' . escapeshellarg($dest) . ' ' . escapeshellarg($url);
}
@unlink($exitFile);
$script = $cmd . '; echo $? > ' . escapeshellarg($exitFile);
$launch = 'nohup bash -c ' . escapeshellarg($script) . ' >> ' . escapeshellarg($dest . '.download.log') . ' 2>&1 & echo $!';
$started = trim((string)shell_exec($launch));
if (!preg_match('/^\d+$/', $started) || !download_alive((int)$started)) {
    fwrite(STDERR, "Could not start the download.\n");
    exit(1);
}
file_put_contents($pidFile, $started);
echo "Started with {$tool}.\nPath: {$rel}\nPid: {$started}\nLog: {$logRel}\nCall again with the same url and path to see progress.\n";
