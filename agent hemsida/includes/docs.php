<?php

require_once __DIR__ . '/bootstrap.php';

function docs_dir(): string
{
    return dirname(__DIR__) . '/docs/pages';
}

function docs_slug(string $title): string
{
    $slug = strtolower(trim($title));
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
    return trim($slug, '-');
}

function docs_href(string $slug): string
{
    if ($slug === 'index') {
        return site_href('/docs/');
    }
    return site_href('/docs/' . $slug . '.php');
}

function docs_inline(string $text): string
{
    $out = '';
    $offset = 0;
    if (preg_match_all('/\[([^\]]+)\]\(([^)]+)\)/', $text, $matches, PREG_OFFSET_CAPTURE)) {
        foreach ($matches[0] as $i => $full) {
            $out .= e(substr($text, $offset, $full[1] - $offset));
            $url = $matches[2][$i][0];
            if (!preg_match('#^(https?:)?//#', $url) && !str_starts_with($url, '#')) {
                $url = site_href('/docs/' . ltrim($url, '/'));
            }
            $out .= '<a class="text-link" href="' . e($url) . '">' . e($matches[1][$i][0]) . '</a>';
            $offset = $full[1] + strlen($full[0]);
        }
    }
    return $out . e(substr($text, $offset));
}

function docs_parse(string $raw): array
{
    $raw = str_replace('{{github_url}}', (string) site_config()['github_url'], $raw);
    $meta = [];
    $body = $raw;
    if (preg_match('/\A---\R(.*?)\R---\R(.*)\z/s', $raw, $match)) {
        foreach (preg_split('/\R/', $match[1]) ?: [] as $line) {
            if (str_contains($line, ':')) {
                [$key, $value] = explode(':', $line, 2);
                $meta[trim($key)] = trim($value);
            }
        }
        $body = $match[2];
    }
    $lines = preg_split('/\R/', $body) ?: [];
    $lede = '';
    $sections = [];
    $current = null;
    $para = [];
    $list = [];
    $code = null;
    $cards = null;
    $cardTitle = null;
    $cardText = [];

    $ensure = function () use (&$current): void {
        if ($current === null) {
            $current = ['id' => 'overview', 'title' => 'Overview', 'parts' => []];
        }
    };
    $flushPara = function () use (&$para, &$lede, &$current, $ensure): void {
        if ($para === []) {
            return;
        }
        $text = trim(implode(' ', $para));
        $para = [];
        if ($text === '') {
            return;
        }
        if ($current === null && $lede === '') {
            $lede = $text;
            return;
        }
        $ensure();
        $current['parts'][] = ['p', $text];
    };
    $flushList = function () use (&$list, &$current, $ensure): void {
        if ($list === []) {
            return;
        }
        $ensure();
        $current['parts'][] = ['ul', $list];
        $list = [];
    };
    $flushCard = function () use (&$cards, &$cardTitle, &$cardText): void {
        if ($cards === null || $cardTitle === null) {
            $cardTitle = null;
            $cardText = [];
            return;
        }
        $cards[] = [$cardTitle, trim(implode(' ', $cardText))];
        $cardTitle = null;
        $cardText = [];
    };
    $close = function () use (&$current, &$sections, $flushPara, $flushList, $flushCard, &$cards): void {
        $flushPara();
        $flushList();
        $flushCard();
        if ($cards !== null && $current !== null) {
            $current['parts'][] = ['cards', $cards];
            $cards = null;
        }
        if ($current !== null) {
            $sections[] = $current;
            $current = null;
        }
    };

    foreach ($lines as $line) {
        if ($code !== null) {
            if (trim($line) === '```') {
                $ensure();
                $current['parts'][] = ['code', rtrim(implode("\n", $code))];
                $code = null;
            } else {
                $code[] = $line;
            }
            continue;
        }
        if (trim($line) === '```') {
            $flushPara();
            $flushList();
            $code = [];
            continue;
        }
        if (trim($line) === ':::cards') {
            $flushPara();
            $flushList();
            $cards = [];
            continue;
        }
        if (trim($line) === ':::') {
            $flushCard();
            if ($cards !== null) {
                $ensure();
                $current['parts'][] = ['cards', $cards];
                $cards = null;
            }
            continue;
        }
        if ($cards !== null && str_starts_with($line, '#### ')) {
            $flushCard();
            $cardTitle = trim(substr($line, 5));
            continue;
        }
        if ($cards !== null) {
            if (trim($line) !== '') {
                $cardText[] = trim($line);
            }
            continue;
        }
        if ($cards === null && preg_match('/^!\[([^\]]*)\]\(([^)]+)\)$/', trim($line), $image)) {
            $flushPara();
            $flushList();
            $ensure();
            $current['parts'][] = ['img', $image[1], $image[2]];
            continue;
        }
        if (str_starts_with($line, '## ')) {
            $close();
            $title = trim(substr($line, 3));
            $current = ['id' => docs_slug($title), 'title' => $title, 'parts' => []];
            continue;
        }
        if (str_starts_with(ltrim($line), '- ')) {
            $flushPara();
            $list[] = trim(substr(ltrim($line), 2));
            continue;
        }
        if (trim($line) === '') {
            $flushPara();
            $flushList();
            continue;
        }
        $flushList();
        $para[] = trim($line);
    }
    $close();

    $slug = $meta['slug'] ?? 'index';
    return [
        'slug' => $slug,
        'nav' => $meta['nav'] ?? ($meta['title'] ?? 'Docs'),
        'title' => $meta['title'] ?? 'Docs',
        'lede' => $lede,
        'href' => docs_href($slug),
        'sections' => $sections,
    ];
}

function docs_pages(): array
{
    static $pages = null;
    if ($pages !== null) {
        return $pages;
    }
    $pages = [];
    $files = glob(docs_dir() . '/*.md') ?: [];
    sort($files);
    foreach ($files as $file) {
        $pages[] = docs_parse((string) file_get_contents($file));
    }
    return $pages;
}

function docs_page(string $slug): array
{
    foreach (docs_pages() as $page) {
        if ($page['slug'] === $slug) {
            return $page;
        }
    }
    return docs_pages()[0];
}

function docs_section_text(array $section): string
{
    $bits = [];
    foreach ($section['parts'] as $part) {
        if ($part[0] === 'p' || $part[0] === 'code' || $part[0] === 'img') {
            $bits[] = $part[1];
        } elseif ($part[0] === 'ul') {
            $bits[] = implode(' ', $part[1]);
        } elseif ($part[0] === 'cards') {
            foreach ($part[1] as $card) {
                $bits[] = $card[0] . ' ' . $card[1];
            }
        }
    }
    $plain = trim(preg_replace('/\s+/', ' ', implode(' ', $bits)) ?? '');
    if (strlen($plain) > 180) {
        $plain = rtrim(substr($plain, 0, 177)) . '...';
    }
    return $plain;
}

function docs_search_index(): array
{
    $items = [];
    foreach (docs_pages() as $page) {
        foreach ($page['sections'] as $section) {
            $items[] = [
                'page' => $page['nav'],
                'title' => $section['title'],
                'text' => docs_section_text($section),
                'url' => $page['href'] . '#' . $section['id'],
            ];
        }
    }
    return $items;
}

function docs_render_part(array $part): void
{
    if ($part[0] === 'p') {
        echo '<p>' . docs_inline($part[1]) . '</p>';
        return;
    }
    if ($part[0] === 'ul') {
        echo '<ul>';
        foreach ($part[1] as $item) {
            echo '<li>' . docs_inline($item) . '</li>';
        }
        echo '</ul>';
        return;
    }
    if ($part[0] === 'code') {
        echo '<pre class="code">' . e($part[1]) . '</pre>';
        return;
    }
    if ($part[0] === 'img') {
        $url = $part[2];
        if (str_starts_with($url, '/')) {
            $url = site_href($url);
        } elseif (!preg_match('#^(https?:)?//#', $url) && !str_starts_with($url, '#')) {
            $url = site_href('/docs/' . ltrim($url, '/'));
        }
        echo '<figure class="docs-figure"><img src="' . e($url) . '" alt="' . e($part[1]) . '"></figure>';
        return;
    }
    if ($part[0] === 'cards') {
        echo '<div class="docs-cards">';
        foreach ($part[1] as $card) {
            echo '<article class="docs-card"><h3>' . e($card[0]) . '</h3><p>' . docs_inline($card[1]) . '</p></article>';
        }
        echo '</div>';
    }
}

function render_docs(string $slug): void
{
    $pages = docs_pages();
    $page = docs_page($slug);
    $config = site_config();
    $index = json_encode(docs_search_index(), JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP);
    ?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="#ffffff">
<title><?php echo e($page['title'] . ' — ' . $config['site_title']); ?></title>
<meta name="description" content="<?php echo e($page['lede']); ?>">
<?php site_fonts(); ?>
<?php site_icons(); ?>
<link rel="stylesheet" href="<?php echo e(site_href('/assets/site.css')); ?>">
</head>
<body class="docs-body">
<header class="site-header docs-header">
    <div class="wrap">
        <a class="wordmark" href="<?php echo e(site_href('/')); ?>"><img class="mark" src="<?php echo e(site_href('/assets/logo.png')); ?>" alt=""><?php echo e((string) $config['site_title']); ?></a>
        <div class="search">
            <input id="docs-search" type="search" placeholder="Search docs" autocomplete="off" role="combobox" aria-expanded="false" aria-controls="docs-search-list" aria-autocomplete="list">
            <ul id="docs-search-list" class="search-list" role="listbox" hidden></ul>
        </div>
        <div class="docs-nav">
            <?php echo github_button('btn btn-ghost btn-small'); ?>
            <?php echo buy_button('btn btn-primary btn-small'); ?>
        </div>
    </div>
</header>
<div class="wrap docs-shell">
    <button class="btn btn-ghost side-toggle" type="button" data-side-toggle aria-expanded="false" aria-controls="docs-side">On this page</button>
    <aside class="docs-side" id="docs-side" data-side>
        <p>Docs</p>
        <?php foreach ($pages as $item): ?>
        <a class="<?php echo $item['slug'] === $page['slug'] ? 'is-page' : ''; ?>" href="<?php echo e($item['href']); ?>"><?php echo e($item['nav']); ?></a>
        <?php endforeach; ?>
        <p>On this page</p>
        <?php foreach ($page['sections'] as $section): ?>
        <a href="#<?php echo e($section['id']); ?>"><?php echo e($section['title']); ?></a>
        <?php endforeach; ?>
    </aside>
    <article class="docs-main">
        <h1><?php echo e($page['title']); ?></h1>
        <p class="lede"><?php echo e($page['lede']); ?></p>
        <?php foreach ($page['sections'] as $section): ?>
        <h2 id="<?php echo e($section['id']); ?>"><?php echo e($section['title']); ?></h2>
        <?php foreach ($section['parts'] as $part) {
            docs_render_part($part);
        } ?>
        <?php endforeach; ?>
    </article>
</div>
<script type="application/json" id="docs-index"><?php echo $index; ?></script>
<?php site_scripts(); ?>
</body>
</html>
<?php
}
