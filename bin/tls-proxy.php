<?php

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Run this from the command line.\n");
    exit(1);
}
if (!extension_loaded('openssl')) {
    fwrite(STDERR, "PHP openssl is required for the encrypted address.\n");
    exit(1);
}

$bind = $argv[1] ?? '';
$port = (int)($argv[2] ?? 0);
$cert = $argv[3] ?? '';
$key = $argv[4] ?? '';
$backendHost = $argv[5] ?? '127.0.0.1';
$backendPort = (int)($argv[6] ?? 0);
if (!in_array($bind, ['127.0.0.1', '0.0.0.0'], true) || $port < 1 || $port > 65535 || $backendPort < 1 || $backendPort > 65535) {
    fwrite(STDERR, "Could not start the encrypted address.\n");
    exit(1);
}
if (!is_file($cert) || !is_file($key)) {
    fwrite(STDERR, "The local certificate is missing.\n");
    exit(1);
}

$context = stream_context_create([
    'ssl' => [
        'local_cert' => $cert,
        'local_pk' => $key,
        'verify_peer' => false,
        'verify_peer_name' => false,
        'allow_self_signed' => true,
        'disable_compression' => true,
        'security_level' => 1,
    ],
]);
$errno = 0;
$errstr = '';
$server = stream_socket_server('tcp://' . $bind . ':' . $port, $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context);
if ($server === false) {
    fwrite(STDERR, "Could not listen on {$bind}:{$port}. {$errstr}\n");
    exit(1);
}
stream_set_blocking($server, false);

$crypto = STREAM_CRYPTO_METHOD_TLS_SERVER;
if (defined('STREAM_CRYPTO_METHOD_TLSv1_2_SERVER')) {
    $crypto |= STREAM_CRYPTO_METHOD_TLSv1_2_SERVER;
}
if (defined('STREAM_CRYPTO_METHOD_TLSv1_3_SERVER')) {
    $crypto |= STREAM_CRYPTO_METHOD_TLSv1_3_SERVER;
}

$pairs = [];
$nextId = 1;

$writeAll = static function ($socket, string $data): bool {
    $left = $data;
    $waits = 0;
    while ($left !== '') {
        $written = @fwrite($socket, $left);
        if ($written === false) {
            $meta = stream_get_meta_data($socket);
            if (!empty($meta['eof'])) {
                return false;
            }
            $written = 0;
        }
        if ($written > 0) {
            $waits = 0;
            $left = substr($left, $written);
            continue;
        }
        $waits++;
        if ($waits > 50) {
            return false;
        }
        $read = null;
        $write = [$socket];
        $except = null;
        @stream_select($read, $write, $except, 0, 200000);
    }
    return true;
};

$pump = static function ($from, $to) use ($writeAll): string {
    $data = @fread($from, 65536);
    if ($data === false || $data === '') {
        $meta = stream_get_meta_data($from);
        if (!empty($meta['timed_out'])) {
            stream_set_timeout($from, 7200);
        }
        return !empty($meta['eof']) ? 'closed' : 'idle';
    }
    return $writeAll($to, $data) ? 'data' : 'closed';
};

$socketId = static function ($socket): int {
    return is_object($socket) ? spl_object_id($socket) : (int)$socket;
};

while (true) {
    $read = [$server];
    foreach ($pairs as $pair) {
        $read[] = $pair['client'];
        $read[] = $pair['backend'];
    }
    $write = null;
    $except = null;
    if (@stream_select($read, $write, $except, 1) === false) {
        continue;
    }
    if (in_array($server, $read, true)) {
        $client = @stream_socket_accept($server, 0);
        if (is_resource($client)) {
            stream_set_blocking($client, true);
            stream_set_timeout($client, 10);
            $handshook = @stream_socket_enable_crypto($client, true, $crypto);
            if ($handshook !== true) {
                fclose($client);
            } else {
                $upErrno = 0;
                $upErr = '';
                $backend = @stream_socket_client(
                    'tcp://' . $backendHost . ':' . $backendPort,
                    $upErrno,
                    $upErr,
                    5
                );
                if (!is_resource($backend)) {
                    fclose($client);
                } else {
                    stream_set_blocking($client, false);
                    stream_set_blocking($backend, false);
                    stream_set_timeout($client, 7200);
                    stream_set_timeout($backend, 7200);
                    $pairs[$nextId] = ['client' => $client, 'backend' => $backend];
                    $nextId++;
                }
            }
        }
    }
    $ready = [];
    foreach ($read as $socket) {
        $ready[$socketId($socket)] = true;
    }
    foreach ($pairs as $id => $pair) {
        $clientState = isset($ready[$socketId($pair['client'])]) ? $pump($pair['client'], $pair['backend']) : 'idle';
        $backendState = 'idle';
        if ($clientState !== 'closed' && isset($ready[$socketId($pair['backend'])])) {
            $backendState = $pump($pair['backend'], $pair['client']);
        }
        if ($clientState === 'closed' || $backendState === 'closed') {
            fclose($pair['client']);
            fclose($pair['backend']);
            unset($pairs[$id]);
        }
    }
}
