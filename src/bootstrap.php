<?php

ini_set('display_errors', '0');
ini_set('log_errors', '1');

$APP_CONFIG = require dirname(__DIR__) . '/config.php';

app_timezone();

function app_timezone(): void
{
    $configured = ini_get('date.timezone');
    if (is_string($configured) && $configured !== '' && strcasecmp($configured, 'UTC') !== 0) {
        date_default_timezone_set($configured);
        return;
    }
    $link = @readlink('/etc/localtime');
    if (is_string($link) && preg_match('#zoneinfo/(.+)$#', $link, $matches)) {
        date_default_timezone_set($matches[1]);
    }
}

function app_local_stamp(?string $stored = null): string
{
    $zone = new DateTimeZone(date_default_timezone_get());
    $stored = trim((string)$stored);
    try {
        $time = $stored !== ''
            ? new DateTimeImmutable($stored, new DateTimeZone('UTC'))
            : new DateTimeImmutable('now', $zone);
    } catch (Exception $e) {
        $time = new DateTimeImmutable('now', $zone);
    }
    return $time->setTimezone($zone)->format('l j F Y \a\t H:i');
}

function error_text(string $message): string
{
    $message = trim($message);
    if ($message === '') {
        return 'Error: Something went wrong.';
    }
    if (preg_match('/^Error:\s/', $message)) {
        return $message;
    }
    if (str_starts_with($message, 'Tool error: ')) {
        $message = substr($message, strlen('Tool error: '));
    }
    return 'Error: ' . $message;
}

function agent_error(string $agent, string $message): string
{
    $who = $agent === '' || $agent === 'orchestrator' ? 'Main agent' : ucfirst($agent);
    $message = trim($message);
    $message = preg_replace('/^Error:\s*/', '', $message) ?? $message;
    if (str_starts_with($message, 'Tool error: ')) {
        $message = substr($message, strlen('Tool error: '));
    }
    $named = $who . ': ';
    if (str_starts_with($message, $named)) {
        return 'Error: ' . $message;
    }
    return 'Error: ' . $named . $message;
}

spl_autoload_register(static function (string $class): void {
    foreach ([__DIR__ . '/' . $class . '.php', __DIR__ . '/Agents/' . $class . '.php'] as $path) {
        if (is_file($path)) {
            require $path;
            return;
        }
    }
});

if (!function_exists('mb_strlen')) {
    function mb_strlen($string, $encoding = null): int
    {
        return strlen((string)$string);
    }
}

if (!function_exists('mb_substr')) {
    function mb_substr($string, $start, $length = null, $encoding = null): string
    {
        $string = (string)$string;
        if ($length === null) {
            return substr($string, (int)$start);
        }
        return substr($string, (int)$start, (int)$length);
    }
}

function app_config(): array
{
    global $APP_CONFIG;
    return $APP_CONFIG;
}

function clip_text(string $text, int $max = 12000): string
{
    if (strlen($text) <= $max) {
        return $text;
    }
    return substr($text, 0, $max) . "\n…[truncated]";
}

function strip_chat_tags(string $text): string
{
    $clean = preg_replace('/<\/?chat\b[^>]*>/i', '', $text);
    if (!is_string($clean)) {
        return $text;
    }
    $clean = preg_replace('/<\/?chat\b[^>]*\z/i', '', $clean) ?? $clean;
    return $clean;
}

function app_boot_web(): void
{
    $config = app_config();
    $sessionDir = $config['data_dir'] . '/sessions';
    if (!is_dir($sessionDir) && !mkdir($sessionDir, 0770, true) && !is_dir($sessionDir)) {
        throw new RuntimeException('Could not create the session directory.');
    }
    session_save_path($sessionDir);
    session_name($config['session_name']);
    $secure = is_file($config['data_dir'] . '/tls/server.crt');
    if (!$secure) {
        $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    }
    session_set_cookie_params([
        'lifetime' => 60 * 60 * 24 * 14,
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure' => $secure,
    ]);
    session_start();
}

function app_services(): array
{
    static $services = null;
    if ($services !== null) {
        return $services;
    }
    $config = app_config();
    $db = Database::instance();
    $hardware = new HardwareMonitor($db);
    $llm = new LLMClient($db, $hardware);
    $safety = new SafetyInterceptor($config['data_dir']);
    $skills = new SkillEngine($db, $config['data_dir']);
    $memory = new MemoryManager($config['data_dir']);
    $runtime = new AgentRuntime($llm, $db);
    $subagents = new SubagentRunner($runtime, $db, $memory, $skills, $safety);
    $orchestrator = new Orchestrator($runtime, $subagents, $db, $memory, $skills);
    $services = [
        'db' => $db,
        'hardware' => $hardware,
        'llm' => $llm,
        'safety' => $safety,
        'skills' => $skills,
        'memory' => $memory,
        'runtime' => $runtime,
        'subagents' => $subagents,
        'orchestrator' => $orchestrator,
    ];
    return $services;
}
