<?php

function site_config(): array
{
    static $config = null;
    if ($config === null) {
        $config = require dirname(__DIR__) . '/config.php';
    }
    return $config;
}

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function site_base(): string
{
    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    $pos = strpos($script, '/www/');
    if ($pos !== false) {
        return rtrim(substr($script, 0, $pos) . '/www', '/');
    }
    return '';
}

function site_href(string $path = '/'): string
{
    $base = site_base();
    if ($path === '' || $path === '/') {
        return $base === '' ? '/' : $base . '/';
    }
    return $base . '/' . ltrim($path, '/');
}

function buy_button(string $class = 'btn btn-primary'): string
{
    return '<a class="' . e($class) . '" href="#" onclick="alert(\'Coming soon!\'); return false;">Buy installer $20</a>';
}

function github_button(string $class = 'btn btn-ghost'): string
{
    $url = (string) site_config()['github_url'];
    return '<a class="' . e($class) . '" href="' . e($url) . '" target="_blank" rel="noopener noreferrer">Source on Github</a>';
}

function site_fonts(): void
{
    echo '<link rel="preconnect" href="https://fonts.googleapis.com">' . "\n";
    echo '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>' . "\n";
    echo '<link href="https://fonts.googleapis.com/css2?family=Geist:wght@400;500;600;700&family=Geist+Mono:wght@400;500&display=swap" rel="stylesheet">' . "\n";
}

function site_icons(): void
{
    echo '<link rel="icon" href="' . e(site_href('/favicon.ico')) . '" sizes="any">' . "\n";
    echo '<link rel="icon" href="' . e(site_href('/assets/icon-32.png')) . '" type="image/png" sizes="32x32">' . "\n";
    echo '<link rel="apple-touch-icon" href="' . e(site_href('/assets/apple-touch-icon.png')) . '">' . "\n";
}

function site_scripts(): void
{
    echo '<script src="' . e(site_href('/assets/site.js')) . '" defer></script>' . "\n";
}
