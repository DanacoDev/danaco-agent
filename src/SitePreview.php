<?php

class SitePreview
{
    private const MAX_FILE = 33554432;

    public function serve(string $uri): void
    {
        if (empty($_SESSION['authed'])) {
            $this->fail(401, 'Sign in to preview this page.');
            return;
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        if (!in_array($method, ['GET', 'HEAD', 'POST'], true)) {
            $this->fail(405, 'That request is not supported.');
            return;
        }
        try {
            $target = $this->target($uri);
        } catch (RuntimeException $e) {
            $message = $e->getMessage();
            $status = str_contains(strtolower($message), 'not found') ? 404 : 400;
            $this->fail($status, $message);
            return;
        }
        if (!is_file($target['file'])) {
            $this->fail(404, 'File not found.');
            return;
        }
        $this->sendStatic($target['file'], $method);
    }

    private function target(string $uri): array
    {
        $path = parse_url($uri, PHP_URL_PATH);
        if (!is_string($path) || !str_starts_with($path, '/preview/')) {
            throw new RuntimeException('Invalid path.');
        }
        $segments = explode('/', trim($path, '/'));
        array_shift($segments);
        $scopeSeg = array_shift($segments);
        $scope = rawurldecode(is_string($scopeSeg) ? $scopeSeg : '');
        $folder = '';
        if ($scope === 'project') {
            $folderSeg = array_shift($segments);
            $folder = rawurldecode(is_string($folderSeg) ? $folderSeg : '');
        } elseif ($scope !== 'wiki') {
            throw new RuntimeException('Unknown file location.');
        }
        $parts = [];
        foreach ($segments as $segment) {
            $segment = rawurldecode($segment);
            if ($segment === '' || $segment === '.' || $segment === '..' || str_contains($segment, '/') || str_contains($segment, '\\') || str_contains($segment, "\0")) {
                throw new RuntimeException('Invalid path.');
            }
            $parts[] = $segment;
        }
        $relative = implode('/', $parts);
        $safety = new SafetyInterceptor(app_config()['data_dir']);
        $located = $this->withPlace($safety->locateWorkspace($scope, $folder, $relative), $scope, $folder);
        if (is_dir($located['file'])) {
            foreach (['index.html', 'index.htm', 'index.php'] as $index) {
                $next = $relative === '' ? $index : $relative . '/' . $index;
                try {
                    $candidate = $this->withPlace($safety->locateWorkspace($scope, $folder, $next), $scope, $folder);
                } catch (RuntimeException $e) {
                    continue;
                }
                if (is_file($candidate['file'])) {
                    return $candidate;
                }
            }
            throw new RuntimeException('File not found.');
        }
        return $located;
    }

    private function withPlace(array $located, string $scope, string $folder): array
    {
        $located['scope'] = $scope;
        $located['folder'] = $folder;
        return $located;
    }

    private function sendStatic(string $file, string $method): void
    {
        $size = filesize($file);
        if ($size === false || $size > self::MAX_FILE) {
            $this->fail(413, 'That file is too large to preview.');
            return;
        }
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        $types = [
            'html' => 'text/html; charset=utf-8',
            'htm' => 'text/html; charset=utf-8',
            'php' => 'text/plain; charset=utf-8',
            'css' => 'text/css; charset=utf-8',
            'js' => 'text/javascript; charset=utf-8',
            'mjs' => 'text/javascript; charset=utf-8',
            'json' => 'application/json; charset=utf-8',
            'svg' => 'image/svg+xml',
            'png' => 'image/png',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'avif' => 'image/avif',
            'ico' => 'image/x-icon',
            'txt' => 'text/plain; charset=utf-8',
            'xml' => 'application/xml; charset=utf-8',
            'pdf' => 'application/pdf',
            'woff' => 'font/woff',
            'woff2' => 'font/woff2',
            'ttf' => 'font/ttf',
            'map' => 'application/json; charset=utf-8',
            'webmanifest' => 'application/manifest+json; charset=utf-8',
            'mp4' => 'video/mp4',
            'webm' => 'video/webm',
            'mp3' => 'audio/mpeg',
            'wasm' => 'application/wasm',
        ];
        header('Content-Type: ' . ($types[$ext] ?? 'application/octet-stream'));
        header('Content-Length: ' . (string)$size);
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        if ($method === 'HEAD') {
            return;
        }
        readfile($file);
    }

    private function fail(int $status, string $message): void
    {
        http_response_code($status);
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        echo $message;
    }
}
