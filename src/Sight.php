<?php

class Sight
{
    public static function look(SafetyInterceptor $safety, string $folder, string $path, string $question, ?callable $shouldStop = null): string
    {
        $path = trim($path);
        if ($path === '') {
            return 'Name the image path.';
        }
        if (preg_match('/\.pdf$/i', $path)) {
            return PdfFile::readProject($safety, $folder, $path, $question, $shouldStop);
        }
        if (!preg_match('/\.(?:png|jpe?g|gif|webp)$/i', $path)) {
            if (preg_match('/\.svg$/i', $path)) {
                return 'An svg is text. Use filesystem with action read.';
            }
            return 'read_image looks at a png, jpg, gif, or webp.';
        }
        try {
            $file = $safety->readBytes($folder, $path, 4 * 1024 * 1024);
        } catch (RuntimeException $e) {
            return $e->getMessage();
        }
        return app_services()['llm']->seeImage(trim($question), $file['bytes'], $shouldStop);
    }

    public static function shot(SafetyInterceptor $safety, string $folder, array $args, ?callable $shouldStop = null): string
    {
        if (!preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $folder)) {
            return 'Open a project before taking a screenshot.';
        }
        $path = trim((string)($args['path'] ?? ''));
        $url = trim((string)($args['url'] ?? ''));
        if ($path !== '' && $url !== '') {
            return 'Pass path or url, not both.';
        }
        if ($path === '' && $url === '') {
            return 'Pass path for a page in this project, or url for a public page.';
        }
        try {
            if ($shouldStop && $shouldStop()) {
                return 'Stopped.';
            }
        } catch (Throwable $e) {
            return 'Stopped.';
        }
        $page = null;
        if ($url !== '') {
            if (Database::instance()->getSetting('headless_browser', '0') !== '1') {
                return 'The headless browser is off, so a public page cannot be screenshotted. A page in this project can still be screenshotted with path.';
            }
            $rejected = self::rejectPublicUrl($url);
            if ($rejected !== '') {
                return $rejected;
            }
            $label = $url;
        } else {
            try {
                $page = self::projectPage($folder, $path);
            } catch (RuntimeException $e) {
                return $e->getMessage();
            }
            $label = $page['relative'];
        }
        $save = trim((string)($args['save'] ?? ''));
        if ($save === '') {
            $save = self::defaultSave($label);
        }
        if (!preg_match('/^(?:[A-Za-z0-9][A-Za-z0-9._-]{0,40}\/)*[A-Za-z0-9][A-Za-z0-9._-]{0,80}\.png$/', $save)) {
            return 'save must be a png path in the project, such as screenshots/page.png.';
        }
        $browser = self::browser();
        if ($browser === '') {
            return 'Chromium is not installed, so a screenshot cannot be taken.';
        }
        $width = (int)($args['width'] ?? 0);
        $height = (int)($args['height'] ?? 0);
        if ($width <= 0) {
            $width = 1280;
        }
        if ($height <= 0) {
            $height = 900;
        }
        $width = max(320, min(1920, $width));
        $height = max(320, min(2400, $height));
        $dir = sys_get_temp_dir() . '/danaco-shot-' . bin2hex(random_bytes(4));
        if (!mkdir($dir, 0700, true) && !is_dir($dir)) {
            return 'Could not prepare a screenshot.';
        }
        if (!mkdir($dir . '/profile', 0700, true) && !is_dir($dir . '/profile')) {
            self::removeDir($dir);
            return 'Could not prepare a screenshot.';
        }
        $server = null;
        $pipes = [];
        $png = '';
        try {
            $targetUrl = $url;
            if ($page !== null) {
                $port = self::freePort();
                $server = self::startServer($page['root'], $port, $dir, $pipes);
                if (!is_resource($server) || !self::waitPort($port, $server)) {
                    return 'The page could not be opened for a screenshot.';
                }
                $targetUrl = 'http://127.0.0.1:' . $port . '/' . $page['url'];
            }
            $png = self::capture($browser, $targetUrl, $dir, $width, $height);
        } catch (RuntimeException $e) {
            return $e->getMessage();
        } finally {
            self::stopServer($server, $pipes);
            self::removeDir($dir);
        }
        if (!str_starts_with($png, "\x89PNG\r\n\x1a\n") || strlen($png) < 800) {
            return 'The screenshot came back empty.';
        }
        if (strlen($png) > 8 * 1024 * 1024) {
            return 'The screenshot is too large to save.';
        }
        try {
            $written = $safety->writeFile($folder, $save, $png);
        } catch (RuntimeException $e) {
            return $e->getMessage();
        }
        $line = 'Saved ' . $written['path'] . ($written['backed_up'] ? ' (previous version backed up).' : '.');
        return $line . "\nCite that path alone on a line when the user should see it. The chat shows the picture.\nCall read_image on it when you need to see the page yourself.";
    }

    private static function projectPage(string $folder, string $relative): array
    {
        $root = realpath(Projects::root($folder));
        if ($root === false || !is_dir($root)) {
            throw new RuntimeException('Project folder not found.');
        }
        $relative = str_replace('\\', '/', trim($relative));
        $relative = trim($relative, '/');
        if ($relative === '' || str_contains($relative, "\0")) {
            throw new RuntimeException('Name a page in this project.');
        }
        $parts = [];
        foreach (explode('/', $relative) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                throw new RuntimeException('Invalid path.');
            }
            $parts[] = $part;
        }
        if ($parts === []) {
            throw new RuntimeException('Name a page in this project.');
        }
        $relative = implode('/', $parts);
        $full = $root . '/' . $relative;
        $target = realpath($full);
        if ($target === false || ($target !== $root && !str_starts_with($target, $root . '/'))) {
            throw new RuntimeException('File not found.');
        }
        if (is_dir($target)) {
            $found = false;
            foreach (['index.html', 'index.htm', 'index.php'] as $name) {
                if (is_file($target . '/' . $name)) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                throw new RuntimeException('That folder has no index.html, index.htm, or index.php.');
            }
            $url = implode('/', array_map('rawurlencode', $parts)) . '/';
        } elseif (is_file($target)) {
            if (!preg_match('/\.(?:html|htm|php)$/i', $target)) {
                throw new RuntimeException('screenshot needs an html, htm, or php page, or a folder with an index page.');
            }
            $url = implode('/', array_map('rawurlencode', $parts));
        } else {
            throw new RuntimeException('File not found.');
        }
        return ['root' => $root, 'relative' => $relative, 'url' => $url];
    }

    private static function defaultSave(string $label): string
    {
        $base = strtolower($label);
        $base = preg_replace('#^https?://#', '', $base) ?? $base;
        $base = preg_replace('/[^a-z0-9]+/', '-', $base) ?? 'page';
        $base = trim($base, '-');
        if ($base === '') {
            $base = 'page';
        }
        return 'screenshots/' . substr($base, 0, 60) . '.png';
    }

    private static function rejectPublicUrl(string $url): string
    {
        if (strlen($url) > 2000) {
            return 'That address is too long.';
        }
        $parts = parse_url($url);
        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        $host = (string)($parts['host'] ?? '');
        if (!is_array($parts) || !in_array($scheme, ['http', 'https'], true) || $host === '' || !empty($parts['user']) || !empty($parts['pass'])) {
            return 'Only public http and https URLs are allowed.';
        }
        if (!self::hostIsPublic($host)) {
            return 'That address is not a public host.';
        }
        return '';
    }

    private static function hostIsPublic(string $host): bool
    {
        $host = trim($host, '[]');
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return self::ipIsPublic($host);
        }
        if (in_array(strtolower($host), ['localhost', 'localhost.localdomain'], true) || str_ends_with(strtolower($host), '.local')) {
            return false;
        }
        $ips = [];
        foreach (dns_get_record($host, DNS_A + DNS_AAAA) ?: [] as $record) {
            if (!empty($record['ip'])) {
                $ips[] = $record['ip'];
            }
            if (!empty($record['ipv6'])) {
                $ips[] = $record['ipv6'];
            }
        }
        if (!$ips) {
            $ips = gethostbynamel($host) ?: [];
        }
        if (!$ips) {
            return false;
        }
        foreach ($ips as $ip) {
            if (!self::ipIsPublic($ip)) {
                return false;
            }
        }
        return true;
    }

    private static function ipIsPublic(string $ip): bool
    {
        return (bool)filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
    }

    public static function page(string $url): string
    {
        $rejected = self::rejectPublicUrl($url);
        if ($rejected !== '') {
            return $rejected;
        }
        $browser = self::browser();
        if ($browser === '') {
            return 'Chromium is not installed, so that page cannot be opened.';
        }
        $dir = sys_get_temp_dir() . '/danaco-browse-' . bin2hex(random_bytes(4));
        if (!self::prepareDir($dir)) {
            return 'Could not open that page.';
        }
        try {
            $html = self::dump($browser, $url, $dir);
        } catch (RuntimeException $e) {
            return $e->getMessage();
        } finally {
            self::removeDir($dir);
        }
        $html = trim($html);
        if ($html === '' || !str_contains($html, '<')) {
            return 'The page returned nothing.';
        }
        if (strlen($html) > 1500000) {
            $html = substr($html, 0, 1500000);
        }
        return $html;
    }

    private static function browser(): string
    {
        $fixed = [
            '/usr/bin/chromium',
            '/usr/bin/chromium-browser',
            '/usr/bin/google-chrome-stable',
            '/usr/bin/google-chrome',
            '/snap/bin/chromium',
        ];
        if (PHP_OS_FAMILY === 'Darwin') {
            $fixed = [
                '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
                '/Applications/Chromium.app/Contents/MacOS/Chromium',
                '/usr/bin/chromium',
                '/opt/homebrew/bin/chromium',
            ];
        }
        $found = [];
        foreach ($fixed as $path) {
            if (is_executable($path)) {
                $found[] = $path;
            }
        }
        foreach (['chromium', 'chromium-browser', 'google-chrome-stable', 'google-chrome'] as $name) {
            $which = Process::run(['/bin/sh', '-c', 'command -v ' . $name], null, null, 5);
            $path = trim($which['stdout']);
            if ($which['code'] === 0 && $path !== '' && !str_contains($path, "\n") && is_executable($path)) {
                $found[] = $path;
            }
        }
        foreach ($found as $path) {
            if (!str_contains($path, '/snap/')) {
                return $path;
            }
        }
        return $found[0] ?? '';
    }

    private static function freePort(): int
    {
        $sock = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if (!is_resource($sock)) {
            throw new RuntimeException('Could not open a local port for the screenshot.');
        }
        $name = stream_socket_get_name($sock, false);
        fclose($sock);
        if (!is_string($name) || !preg_match('/:(\d+)$/', $name, $match)) {
            throw new RuntimeException('Could not open a local port for the screenshot.');
        }
        return (int)$match[1];
    }

    private static function startServer(string $root, int $port, string $dir, array &$pipes)
    {
        $php = PHP_BINARY !== '' ? PHP_BINARY : 'php';
        $proc = proc_open(
            [$php, '-S', '127.0.0.1:' . $port, '-t', $root],
            [
                0 => ['pipe', 'r'],
                1 => ['file', $dir . '/server.log', 'a'],
                2 => ['file', $dir . '/server.log', 'a'],
            ],
            $pipes,
            $root
        );
        if (is_resource($proc) && isset($pipes[0]) && is_resource($pipes[0])) {
            fclose($pipes[0]);
            unset($pipes[0]);
        }
        return $proc;
    }

    private static function waitPort(int $port, $proc): bool
    {
        $deadline = microtime(true) + 5;
        while (microtime(true) < $deadline) {
            $status = proc_get_status($proc);
            if (!$status['running']) {
                return false;
            }
            $fp = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
            if (is_resource($fp)) {
                fclose($fp);
                return true;
            }
            usleep(100000);
        }
        return false;
    }

    private static function stopServer($proc, array $pipes): void
    {
        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        if (!is_resource($proc)) {
            return;
        }
        proc_terminate($proc);
        $deadline = microtime(true) + 2;
        while (microtime(true) < $deadline) {
            $status = proc_get_status($proc);
            if (!$status['running']) {
                break;
            }
            usleep(50000);
        }
        $status = proc_get_status($proc);
        if (!empty($status['running'])) {
            proc_terminate($proc, 9);
        }
        proc_close($proc);
    }

    private static function capture(string $browser, string $url, string $dir, int $width, int $height): string
    {
        self::prepareDir($dir);
        $shot = $dir . '/shot.png';
        $ran = self::runBrowser($browser, $dir, [
            '--window-size=' . $width . ',' . $height,
            '--virtual-time-budget=10000',
            '--screenshot=' . $shot,
            $url,
        ], static function () use ($dir, $shot): bool {
            return is_file($shot) || is_file($dir . '/screenshot.png');
        });
        if (!is_file($shot) && is_file($dir . '/screenshot.png')) {
            $shot = $dir . '/screenshot.png';
        }
        if (!is_file($shot)) {
            if ($ran['error'] !== '') {
                throw new RuntimeException('The screenshot failed. ' . clip_text($ran['error'], 300));
            }
            return '';
        }
        $png = file_get_contents($shot);
        return is_string($png) ? $png : '';
    }

    private static function dump(string $browser, string $url, string $dir): string
    {
        self::prepareDir($dir);
        $ran = self::runBrowser($browser, $dir, [
            '--virtual-time-budget=10000',
            '--dump-dom',
            $url,
        ], static function (array $last): bool {
            return str_contains($last['stdout'], '<');
        });
        if (!str_contains($ran['stdout'], '<')) {
            if ($ran['error'] !== '') {
                throw new RuntimeException('The page could not be opened. ' . clip_text($ran['error'], 300));
            }
            return '';
        }
        return $ran['stdout'];
    }

    private static function runBrowser(string $browser, string $dir, array $tail, callable $done): array
    {
        $off = (PHP_OS_FAMILY === 'Linux' && function_exists('posix_geteuid') && posix_geteuid() === 0) || str_contains($browser, '/snap/');
        $plans = [['--headless=new', $off]];
        if (PHP_OS_FAMILY === 'Linux' && !$off) {
            $plans[] = ['--headless=new', true];
        }
        $last = ['stdout' => '', 'error' => '', 'timed_out' => false];
        $seen = [];
        while ($plans !== []) {
            $plan = array_shift($plans);
            $key = $plan[0] . ($plan[1] ? '1' : '0');
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $command = array_merge(self::baseArgs($browser, $dir, $plan[0], $plan[1]), $tail);
            $last = self::launch($browser, $command, $dir);
            if ($done($last)) {
                return $last;
            }
            if ($last['timed_out']) {
                return $last;
            }
            if (preg_match('/headless/i', $last['error']) && preg_match('/unknown|unrecognized|bad option|not supported/i', $last['error'])) {
                $plans[] = ['--headless', true];
            }
        }
        return $last;
    }

    private static function baseArgs(string $browser, string $dir, string $headless, bool $noSandbox): array
    {
        $args = [
            $browser,
            $headless,
            '--disable-gpu',
            '--hide-scrollbars',
            '--force-device-scale-factor=1',
            '--no-first-run',
            '--no-default-browser-check',
            '--disable-extensions',
            '--disable-dev-shm-usage',
            '--password-store=basic',
            '--user-data-dir=' . $dir . '/profile',
        ];
        if (PHP_OS_FAMILY === 'Darwin') {
            $args[] = '--use-mock-keychain';
        }
        if ($noSandbox) {
            $args[] = '--no-sandbox';
            $args[] = '--disable-setuid-sandbox';
        }
        return $args;
    }

    private static function launch(string $browser, array $command, string $dir): array
    {
        $run = Process::run($command, $dir, null, 30, self::chromeEnv($dir, $browser));
        $stdout = is_string($run['stdout'] ?? null) ? $run['stdout'] : '';
        $err = trim((string)($run['stderr'] ?? ''));
        if ($err === '' && !str_contains($stdout, '<')) {
            $err = trim($stdout);
        }
        $err = preg_replace('/\s+/', ' ', $err) ?? $err;
        return [
            'stdout' => $stdout,
            'error' => $err,
            'timed_out' => (int)($run['code'] ?? 1) === 124,
        ];
    }

    private static function chromeEnv(string $dir, string $browser): array
    {
        $path = getenv('PATH');
        if (!is_string($path) || $path === '') {
            $path = '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin:/snap/bin';
        } elseif (!str_contains($path, '/snap/bin')) {
            $path .= ':/snap/bin';
        }
        $home = $dir;
        if (str_contains($browser, '/snap/')) {
            $real = getenv('HOME');
            if (is_string($real) && $real !== '') {
                $home = $real;
            }
        }
        $lang = getenv('LANG');
        if (!is_string($lang) || $lang === '') {
            $lang = 'C.UTF-8';
        }
        return [
            'PATH' => $path,
            'HOME' => $home,
            'TMPDIR' => $dir,
            'LANG' => $lang,
            'XDG_CONFIG_HOME' => $dir . '/config',
            'XDG_CACHE_HOME' => $dir . '/cache',
            'DBUS_SESSION_BUS_ADDRESS' => 'disabled:',
        ];
    }

    private static function prepareDir(string $dir): bool
    {
        foreach ([$dir, $dir . '/profile', $dir . '/config', $dir . '/cache'] as $path) {
            if (!is_dir($path) && !mkdir($path, 0700, true) && !is_dir($path)) {
                return false;
            }
        }
        return true;
    }

    private static function removeDir(string $dir): void
    {
        $temp = realpath(sys_get_temp_dir());
        $real = realpath($dir);
        if ($temp === false || $real === false || $real === $temp || !str_starts_with($real, $temp . '/')) {
            return;
        }
        foreach (scandir($real) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $full = $real . '/' . $name;
            if (is_dir($full) && !is_link($full)) {
                self::removeDir($full);
            } else {
                @unlink($full);
            }
        }
        @rmdir($real);
    }
}
