<?php

class McpClient
{
    public static function listTools(array $server): array
    {
        $result = self::request($server, 'tools/list', new stdClass());
        $tools = [];
        foreach (array_slice(is_array($result['tools'] ?? null) ? $result['tools'] : [], 0, 40) as $tool) {
            if (!is_array($tool)) {
                continue;
            }
            $name = trim((string)($tool['name'] ?? ''));
            if ($name === '' || strlen($name) > 120) {
                continue;
            }
            $tools[] = [
                'name' => $name,
                'description' => clip_text(trim((string)($tool['description'] ?? '')), 240),
            ];
        }
        return $tools;
    }

    public static function callTool(array $server, string $name, array $arguments): string
    {
        $name = trim($name);
        if ($name === '' || !preg_match('/^[A-Za-z0-9_.:\/-]{1,120}$/', $name)) {
            return 'Name the tool on that MCP server.';
        }
        if ($arguments !== [] && function_exists('array_is_list') && array_is_list($arguments)) {
            return 'Tool arguments must be named fields, such as {"title":"Buy milk"}.';
        }
        $result = self::request($server, 'tools/call', [
            'name' => $name,
            'arguments' => $arguments === [] ? new stdClass() : $arguments,
        ]);
        $lines = [];
        foreach (is_array($result['content'] ?? null) ? $result['content'] : [] as $part) {
            if (is_array($part) && isset($part['text'])) {
                $lines[] = (string)$part['text'];
            }
        }
        $text = trim(implode("\n", $lines));
        if ($text === '' && isset($result['text'])) {
            $text = trim((string)$result['text']);
        }
        if ($text === '') {
            $text = 'The MCP server returned no text.';
        }
        if (!empty($result['isError'])) {
            $text = 'The server reported an error. ' . $text;
        }
        return clip_text($text, 8000);
    }

    public static function parseTarget(string $target): array
    {
        $target = trim($target);
        if ($target === '' || strlen($target) > 500 || preg_match('/[\r\n]/', $target)) {
            throw new InvalidArgumentException('Command or URL is required.');
        }
        if (preg_match('#^https?://#i', $target)) {
            $parts = parse_url($target);
            $scheme = strtolower((string)($parts['scheme'] ?? ''));
            $host = strtolower((string)($parts['host'] ?? ''));
            if (!is_array($parts) || $host === '' || !in_array($scheme, ['http', 'https'], true)) {
                throw new InvalidArgumentException('The MCP address is not valid.');
            }
            if ($scheme === 'http' && !in_array($host, ['127.0.0.1', 'localhost'], true)) {
                throw new InvalidArgumentException('A remote MCP address must use https.');
            }
            return ['kind' => 'http', 'url' => $target];
        }
        if (preg_match('/[|&;<>`$]/', $target)) {
            throw new InvalidArgumentException('The command cannot use a shell. Give the program and its arguments.');
        }
        return ['kind' => 'stdio', 'argv' => self::argv($target)];
    }

    public static function parseEnv(string $raw): array
    {
        $raw = str_replace("\r\n", "\n", trim($raw));
        if (strlen($raw) > 4000) {
            throw new InvalidArgumentException('Environment is too long.');
        }
        if ($raw === '') {
            return [];
        }
        $env = [];
        foreach (explode("\n", $raw) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (!preg_match('/^([A-Za-z_][A-Za-z0-9_]*)=(.*)$/', $line, $match)) {
                throw new InvalidArgumentException('Environment lines must look like KEY=value.');
            }
            if (strlen($match[2]) > 2000) {
                throw new InvalidArgumentException('An environment value is too long.');
            }
            $env[$match[1]] = $match[2];
            if (count($env) > 30) {
                throw new InvalidArgumentException('Too many environment lines.');
            }
        }
        return $env;
    }

    private static function request(array $server, string $method, array|stdClass $params): array
    {
        $target = self::parseTarget((string)($server['target'] ?? ''));
        $env = is_array($server['env'] ?? null) ? $server['env'] : [];
        $init = [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => '2024-11-05',
                'capabilities' => new stdClass(),
                'clientInfo' => ['name' => 'danaco', 'version' => '1'],
            ],
        ];
        $ready = ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'];
        $call = ['jsonrpc' => '2.0', 'id' => 2, 'method' => $method, 'params' => $params];
        try {
            if ($target['kind'] === 'http') {
                return self::httpSession($target['url'], $env, $init, $ready, $call);
            }
            return self::stdioSession($target['argv'], $env, $init, $ready, $call);
        } catch (InvalidArgumentException $e) {
            throw $e;
        } catch (RuntimeException $e) {
            throw $e;
        }
    }

    private static function argv(string $command): array
    {
        $parts = [];
        $length = strlen($command);
        $i = 0;
        while ($i < $length) {
            while ($i < $length && $command[$i] === ' ') {
                $i++;
            }
            if ($i >= $length) {
                break;
            }
            $quote = '';
            $token = '';
            while ($i < $length) {
                $char = $command[$i];
                if ($quote !== '') {
                    if ($char === $quote) {
                        $quote = '';
                        $i++;
                        continue;
                    }
                    $token .= $char;
                    $i++;
                    continue;
                }
                if ($char === ' ') {
                    break;
                }
                if ($char === '"' || $char === "'") {
                    $quote = $char;
                    $i++;
                    continue;
                }
                $token .= $char;
                $i++;
            }
            if ($quote !== '') {
                throw new InvalidArgumentException('The command has an unfinished quote.');
            }
            if ($token === '' || !preg_match('#^[A-Za-z0-9._+:/@=-]+$#', $token)) {
                throw new InvalidArgumentException('The command has an argument this app will not run.');
            }
            $parts[] = $token;
        }
        if (!$parts) {
            throw new InvalidArgumentException('Command or URL is required.');
        }
        return $parts;
    }

    private static function stdioSession(array $argv, array $env, array $init, array $ready, array $call): array
    {
        $proc = proc_open($argv, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, self::processEnv($env), ['bypass_shell' => true]);
        if (!is_resource($proc)) {
            throw new RuntimeException('Could not start the MCP server.');
        }
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $deadline = microtime(true) + 25;
        try {
            self::writeFrame($pipes[0], $init);
            self::readResponse($pipes[1], $pipes[2], $proc, 1, $deadline);
            self::writeFrame($pipes[0], $ready);
            self::writeFrame($pipes[0], $call);
            $result = self::readResponse($pipes[1], $pipes[2], $proc, 2, $deadline);
        } finally {
            fclose($pipes[0]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_terminate($proc);
            proc_close($proc);
        }
        return $result;
    }

    private static function writeFrame($pipe, array $message): void
    {
        $json = json_encode($message, JSON_UNESCAPED_SLASHES);
        if (!is_string($json) || fwrite($pipe, 'Content-Length: ' . strlen($json) . "\r\n\r\n" . $json) === false) {
            throw new RuntimeException('Could not talk to the MCP server.');
        }
    }

    private static function readResponse($stdout, $stderr, $proc, int $id, float $deadline): array
    {
        $buffer = '';
        while (microtime(true) <= $deadline) {
            $buffer .= (string)stream_get_contents($stdout);
            stream_get_contents($stderr);
            $frame = self::takeFrame($buffer);
            if ($frame !== null) {
                $buffer = $frame['rest'];
                $message = $frame['message'];
                if ((int)($message['id'] ?? 0) !== $id) {
                    continue;
                }
                if (isset($message['error'])) {
                    $text = is_array($message['error']) ? (string)($message['error']['message'] ?? '') : '';
                    throw new RuntimeException($text !== '' ? $text : 'The MCP server returned an error.');
                }
                return is_array($message['result'] ?? null) ? $message['result'] : [];
            }
            $status = proc_get_status($proc);
            if (!$status['running'] && $buffer === '') {
                throw new RuntimeException('The MCP server stopped.');
            }
            usleep(20000);
        }
        throw new RuntimeException('The MCP server timed out.');
    }

    private static function takeFrame(string &$buffer): ?array
    {
        $trim = ltrim($buffer, "\r\n");
        if ($trim === '') {
            $buffer = '';
            return null;
        }
        if ($trim[0] === '{') {
            $end = strpos($trim, "\n");
            if ($end === false) {
                $buffer = $trim;
                return null;
            }
            $decoded = json_decode(substr($trim, 0, $end), true);
            if (!is_array($decoded)) {
                throw new RuntimeException('The MCP server sent an unreadable reply.');
            }
            return ['message' => $decoded, 'rest' => substr($trim, $end + 1)];
        }
        if (!preg_match('/Content-Length:\s*(\d+)\s*\r\n\r\n/i', $trim, $match, PREG_OFFSET_CAPTURE)) {
            $buffer = $trim;
            return null;
        }
        $start = $match[0][1] + strlen($match[0][0]);
        $length = (int)$match[1][0];
        if ($length > 2000000 || strlen($trim) < $start + $length) {
            $buffer = $trim;
            return null;
        }
        $decoded = json_decode(substr($trim, $start, $length), true);
        if (!is_array($decoded)) {
            throw new RuntimeException('The MCP server sent an unreadable reply.');
        }
        return ['message' => $decoded, 'rest' => substr($trim, $start + $length)];
    }

    private static function httpSession(string $url, array $env, array $init, array $ready, array $call): array
    {
        $session = '';
        self::httpPost($url, $env, $init, $session);
        self::httpPost($url, $env, $ready, $session);
        return self::httpPost($url, $env, $call, $session);
    }

    private static function httpPost(string $url, array $env, array $message, string &$session): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('Could not reach the MCP server.');
        }
        $headers = ['Content-Type: application/json', 'Accept: application/json, text/event-stream'];
        if ($session !== '') {
            $headers[] = 'Mcp-Session-Id: ' . $session;
        }
        if (!empty($env['AUTHORIZATION'])) {
            $headers[] = 'Authorization: ' . $env['AUTHORIZATION'];
        }
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($message, JSON_UNESCAPED_SLASHES),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_TIMEOUT => 25,
            CURLOPT_CONNECTTIMEOUT => 8,
        ]);
        $raw = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        unset($ch);
        if (!is_string($raw)) {
            throw new RuntimeException('Could not reach the MCP server.');
        }
        $header = substr($raw, 0, $headerSize);
        $body = substr($raw, $headerSize);
        if (preg_match('/^Mcp-Session-Id:\s*(\S+)/mi', $header, $match)) {
            $session = trim($match[1]);
        }
        if (!isset($message['id'])) {
            return [];
        }
        if ($status >= 400) {
            throw new RuntimeException('The MCP server returned HTTP ' . $status . '.');
        }
        $decoded = self::decodeHttpBody($body);
        if (isset($decoded['error'])) {
            $text = is_array($decoded['error']) ? (string)($decoded['error']['message'] ?? '') : '';
            throw new RuntimeException($text !== '' ? $text : 'The MCP server returned an error.');
        }
        return is_array($decoded['result'] ?? null) ? $decoded['result'] : [];
    }

    private static function decodeHttpBody(string $body): array
    {
        $body = trim($body);
        if ($body === '') {
            return [];
        }
        if (str_starts_with($body, '{')) {
            $decoded = json_decode($body, true);
            return is_array($decoded) ? $decoded : [];
        }
        $last = [];
        foreach (preg_split("/\r\n|\n|\r/", $body) ?: [] as $line) {
            if (!str_starts_with($line, 'data:')) {
                continue;
            }
            $json = trim(substr($line, 5));
            if ($json === '' || $json === '[DONE]') {
                continue;
            }
            $decoded = json_decode($json, true);
            if (is_array($decoded)) {
                $last = $decoded;
            }
        }
        return $last;
    }

    private static function processEnv(array $extra): ?array
    {
        if (!$extra) {
            return null;
        }
        $env = $extra;
        foreach (['PATH', 'HOME', 'USER', 'LOGNAME', 'LANG', 'LC_ALL', 'TMPDIR', 'TMP', 'TEMP'] as $key) {
            if (isset($env[$key])) {
                continue;
            }
            $value = getenv($key);
            if (is_string($value) && $value !== '') {
                $env[$key] = $value;
            }
        }
        return $env;
    }
}
