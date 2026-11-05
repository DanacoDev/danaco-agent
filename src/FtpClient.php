<?php

class FtpClient
{
    public static function run(array $account, array $args, string $projectFolder): string
    {
        if (!function_exists('ftp_connect')) {
            return 'FTP is not available in this PHP build.';
        }
        $action = strtolower(trim((string)($args['action'] ?? 'list')));
        $conn = self::connect($account);
        try {
            if ($action === 'list') {
                return self::listRemote($conn, (string)($args['path'] ?? '.'));
            }
            if ($action === 'download') {
                return self::download($conn, (string)($args['remote'] ?? ''), (string)($args['local'] ?? ''), $projectFolder);
            }
            if ($action === 'upload') {
                return self::upload($conn, (string)($args['local'] ?? ''), (string)($args['remote'] ?? ''), $projectFolder);
            }
            return 'FTP action must be list, download, or upload.';
        } finally {
            @ftp_close($conn);
        }
    }

    private static function connect(array $account)
    {
        $host = (string)$account['host'];
        $port = (int)$account['port'];
        $conn = ($account['security'] ?? '') === 'ftps'
            ? @ftp_ssl_connect($host, $port, 8)
            : @ftp_connect($host, $port, 8);
        if ($conn === false) {
            throw new RuntimeException('Could not connect to the FTP server.');
        }
        if (!@ftp_login($conn, (string)$account['username'], (string)$account['password'])) {
            @ftp_close($conn);
            throw new RuntimeException('The FTP server refused the login.');
        }
        @ftp_pasv($conn, true);
        return $conn;
    }

    private static function listRemote($conn, string $path): string
    {
        $path = self::remotePath($path === '' ? '.' : $path);
        $items = @ftp_nlist($conn, $path);
        if ($items === false) {
            return 'Could not list that folder.';
        }
        $items = array_values(array_filter($items, static fn($item): bool => is_string($item) && $item !== ''));
        if (!$items) {
            return 'That folder is empty.';
        }
        return implode("\n", array_slice($items, 0, 200));
    }

    private static function download($conn, string $remote, string $local, string $projectFolder): string
    {
        $remote = self::remotePath($remote);
        $local = trim($local);
        if ($local === '') {
            return 'A local project path is required.';
        }
        $tmp = tempnam(sys_get_temp_dir(), 'agentftp');
        if ($tmp === false) {
            return 'Could not store the download.';
        }
        try {
            if (!@ftp_get($conn, $tmp, $remote, FTP_BINARY)) {
                return 'Could not download that file.';
            }
            $size = filesize($tmp);
            if ($size === false || $size > 2000000) {
                return 'That file is too large to download.';
            }
            $data = file_get_contents($tmp);
            if ($data === false) {
                return 'Could not store the download.';
            }
            $written = (new SafetyInterceptor(app_config()['data_dir']))->writeFile($projectFolder, $local, $data);
            return 'Downloaded to ' . $written['path'];
        } finally {
            if (is_file($tmp)) {
                unlink($tmp);
            }
        }
    }

    private static function upload($conn, string $local, string $remote, string $projectFolder): string
    {
        $remote = self::remotePath($remote);
        $local = trim($local);
        if ($local === '') {
            return 'A local project path is required.';
        }
        try {
            $data = (new SafetyInterceptor(app_config()['data_dir']))->readFile($projectFolder, $local, 2000000);
        } catch (Throwable $e) {
            return $e->getMessage();
        }
        if (strlen($data) > 2000000) {
            return 'That file is too large to upload.';
        }
        $tmp = tempnam(sys_get_temp_dir(), 'agentftp');
        if ($tmp === false || file_put_contents($tmp, $data) === false) {
            return 'Could not read the local file.';
        }
        try {
            if (!@ftp_put($conn, $remote, $tmp, FTP_BINARY)) {
                return 'Could not upload that file.';
            }
            return 'Uploaded ' . $local . ' to ' . $remote;
        } finally {
            if (is_file($tmp)) {
                unlink($tmp);
            }
        }
    }

    private static function remotePath(string $path): string
    {
        $path = str_replace('\\', '/', trim($path));
        if ($path === '' || str_contains($path, "\0") || preg_match('#(^|/)\.\.(/|$)#', $path)) {
            throw new RuntimeException('That remote path is not allowed.');
        }
        return $path;
    }
}
