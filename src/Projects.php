<?php

class Projects
{
    public static function sanitizeFolder(string $folder): string
    {
        $folder = strtolower(trim($folder));
        $folder = preg_replace('/[^a-z0-9_-]+/', '-', $folder) ?? '';
        $folder = trim($folder, '-');
        if ($folder === '' || !preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $folder)) {
            throw new InvalidArgumentException('Folder name must use lowercase letters, numbers, dashes, or underscores.');
        }
        if (in_array($folder, ['wiki', 'skills', 'sessions', 'projects'], true) || str_contains($folder, 'trashcan') || str_contains($folder, 'backups')) {
            throw new InvalidArgumentException('That folder name is reserved.');
        }
        return $folder;
    }

    public static function root(string $folder): string
    {
        if (!preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $folder)) {
            throw new RuntimeException('Invalid project folder.');
        }
        return rtrim(app_config()['data_dir'], '/') . '/' . $folder;
    }

    public static function descFile(string $folder): string
    {
        return $folder . '_desc.md';
    }

    public static function wikiRelative(string $folder, string $name): string
    {
        $name = basename(str_replace('\\', '/', trim($name)));
        if ($name === '') {
            $name = self::descFile($folder);
        }
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,80}\.md$/', $name)) {
            throw new InvalidArgumentException('Wiki file name must be a markdown filename.');
        }
        return 'wiki/' . $name;
    }

    public static function soulPath(string $folder): string
    {
        return self::root($folder) . '/soul.md';
    }

    public static function soulHeader(): string
    {
        return '# Optional agent persona only. Add a short line when lasting how-you-act habits are needed (voice, always/never). Not project facts — those go in the wiki. Use the soul tool to add or remove lines below.';
    }

    public static function soulLines(string $folder): array
    {
        $path = self::soulPath($folder);
        if (!is_file($path)) {
            return [];
        }
        return self::parseSoul((string)file_get_contents($path));
    }

    public static function soulPrompt(string $folder): string
    {
        $lines = self::soulLines($folder);
        if ($lines === []) {
            return '';
        }
        return "Your persona for this project (soul.md), added to your normal rules:\n" . implode("\n", $lines);
    }

    public static function addSoulLine(string $folder, string $text): string
    {
        $line = self::soulLine($text, false);
        if ($line === null) {
            return 'Keep that behaviour to one short line.';
        }
        if ($line === '') {
            return 'A short behaviour line is required.';
        }
        $lines = self::soulLines($folder);
        foreach ($lines as $existing) {
            if (strcasecmp($existing, $line) === 0) {
                return 'That behaviour is already saved.';
            }
        }
        if (count($lines) >= 40) {
            return 'This project already has 40 behaviour lines. Remove one first.';
        }
        $lines[] = $line;
        if (!self::writeSoul($folder, $lines)) {
            return 'Could not save that behaviour.';
        }
        return 'Saved.';
    }

    public static function removeSoulLine(string $folder, string $text): string
    {
        $line = self::soulLine($text, true);
        if ($line === null || $line === '') {
            return 'Say which line to remove.';
        }
        $lines = self::soulLines($folder);
        $next = [];
        $found = false;
        foreach ($lines as $existing) {
            if (!$found && strcasecmp($existing, $line) === 0) {
                $found = true;
                continue;
            }
            $next[] = $existing;
        }
        if (!$found) {
            return 'That line is not in the soul file.';
        }
        if (!self::writeSoul($folder, $next)) {
            return 'Could not update the soul file.';
        }
        return 'Removed.';
    }

    public static function ensureSoul(string $folder): void
    {
        $path = self::soulPath($folder);
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            return;
        }
        if (!is_file($path)) {
            file_put_contents($path, self::soulHeader() . "\n");
            return;
        }
        $raw = (string)file_get_contents($path);
        $trimmed = ltrim($raw);
        if ($trimmed === '' || !str_starts_with($trimmed, self::soulHeader())) {
            self::writeSoul($folder, self::parseSoul($raw));
        }
    }

    private static function soulLine(string $text, bool $clip): ?string
    {
        $text = trim(preg_replace('/\s+/', ' ', $text) ?? '');
        $text = trim((string)preg_replace('/^[-*]+\s+/', '', $text));
        if ($text === '' || str_starts_with($text, '#')) {
            return '';
        }
        if (strcasecmp($text, 'How to act in this project, added to your normal rules:') === 0
            || strcasecmp($text, 'Your persona for this project (soul.md), added to your normal rules:') === 0) {
            return '';
        }
        if (mb_strlen($text) > 240) {
            if (!$clip) {
                return null;
            }
            $text = rtrim(mb_substr($text, 0, 240));
        }
        return $text;
    }

    private static function parseSoul(string $text): array
    {
        $lines = [];
        foreach (preg_split('/\r\n|\n|\r/', $text) ?: [] as $raw) {
            $line = self::soulLine($raw, true);
            if ($line === null || $line === '') {
                continue;
            }
            $known = false;
            foreach ($lines as $existing) {
                if (strcasecmp($existing, $line) === 0) {
                    $known = true;
                    break;
                }
            }
            if ($known) {
                continue;
            }
            $lines[] = $line;
            if (count($lines) >= 40) {
                break;
            }
        }
        return $lines;
    }

    private static function writeSoul(string $folder, array $lines): bool
    {
        $root = self::root($folder);
        if (!is_dir($root) && !mkdir($root, 0775, true) && !is_dir($root)) {
            return false;
        }
        $body = self::soulHeader();
        if ($lines !== []) {
            $body .= "\n" . implode("\n", $lines);
        }
        $body .= "\n";
        return file_put_contents(self::soulPath($folder), $body) !== false;
    }

    public static function create(Database $db, string $name, string $folder, string $purpose, string $type): array
    {
        $name = trim($name);
        if ($name === '') {
            throw new InvalidArgumentException('Project name is required.');
        }
        if (mb_strlen($name) > 25) {
            throw new InvalidArgumentException('Project name must be 25 characters or fewer.');
        }
        $folder = self::sanitizeFolder($folder);
        if ($db->projectByFolder($folder)) {
            throw new InvalidArgumentException('A project with that folder already exists.');
        }
        $root = self::root($folder);
        $wiki = $root . '/wiki';
        if (!is_dir($wiki) && !mkdir($wiki, 0775, true) && !is_dir($wiki)) {
            throw new RuntimeException('Could not create the project folder.');
        }
        $desc = $wiki . '/' . self::descFile($folder);
        if (!is_file($desc)) {
            $text = trim($purpose);
            $body = '# ' . $name . "\n\n" . ($text !== '' ? $text . "\n" : '');
            if (file_put_contents($desc, $body) === false) {
                throw new RuntimeException('Could not create the project description.');
            }
        }
        $notes = $wiki . '/notes.md';
        if (!is_file($notes) && file_put_contents($notes, "# Notes\n\n") === false) {
            throw new RuntimeException('Could not create the project notes.');
        }
        self::ensureSoul($folder);
        $project = $db->insertProject($name, $folder, trim($purpose), trim($type));
        self::appendIndex($project);
        try {
            app_services()['memory']->reindex();
        } catch (Throwable $e) {
        }
        return $project;
    }

    public static function appendIndex(array $project): void
    {
        $path = app_config()['data_dir'] . '/wiki/projects_index.md';
        $line = '- ' . $project['folder_name'] . ' — ' . $project['name'];
        $purpose = trim(preg_replace('/\s+/', ' ', (string)($project['purpose'] ?? '')) ?? '');
        if ($purpose !== '') {
            $line .= ': ' . $purpose;
        }
        $existing = is_file($path) ? (string)file_get_contents($path) : "# Main wiki\n\n";
        if (!str_contains($existing, '- ' . $project['folder_name'] . ' —')) {
            if ($existing !== '' && !str_ends_with($existing, "\n")) {
                $existing .= "\n";
            }
            file_put_contents($path, $existing . $line . "\n");
        }
    }

    public static function syncIndexName(array $project): void
    {
        $path = app_config()['data_dir'] . '/wiki/projects_index.md';
        $folder = (string)($project['folder_name'] ?? '');
        if ($folder === '') {
            return;
        }
        $line = '- ' . $folder . ' — ' . $project['name'];
        $purpose = trim(preg_replace('/\s+/', ' ', (string)($project['purpose'] ?? '')) ?? '');
        if ($purpose !== '') {
            $line .= ': ' . $purpose;
        }
        $existing = is_file($path) ? (string)file_get_contents($path) : "# Main wiki\n\n";
        $pattern = '/^- ' . preg_quote($folder, '/') . ' —.*$/m';
        if (preg_match($pattern, $existing)) {
            $existing = (string)preg_replace($pattern, $line, $existing, 1);
        } else {
            if ($existing !== '' && !str_ends_with($existing, "\n")) {
                $existing .= "\n";
            }
            $existing .= $line . "\n";
        }
        file_put_contents($path, $existing);
    }

    public static function removeIndex(string $folder): void
    {
        $path = app_config()['data_dir'] . '/wiki/projects_index.md';
        if (!is_file($path) || !preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $folder)) {
            return;
        }
        $existing = (string)file_get_contents($path);
        $updated = (string)preg_replace('/^- ' . preg_quote($folder, '/') . ' —.*(?:\r?\n|$)/m', '', $existing, 1);
        if ($updated !== $existing) {
            file_put_contents($path, $updated);
            try {
                app_services()['memory']->reindex();
            } catch (Throwable $e) {
            }
        }
    }

    public static function deleteFolder(string $folder): void
    {
        if (!preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $folder)) {
            throw new RuntimeException('Invalid project folder.');
        }
        $root = self::root($folder);
        if (!is_dir($root)) {
            return;
        }
        $real = realpath($root);
        $data = realpath(rtrim(app_config()['data_dir'], '/'));
        if ($real === false || $data === false || $real === $data || !str_starts_with($real, $data . DIRECTORY_SEPARATOR) || basename($real) !== $folder) {
            throw new RuntimeException('Could not delete that project folder.');
        }
        self::removeTree($real);
    }

    private static function removeTree(string $dir): void
    {
        if (is_link($dir) || !is_dir($dir)) {
            @unlink($dir);
            return;
        }
        foreach (scandir($dir) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $full = $dir . '/' . $name;
            if (is_dir($full) && !is_link($full)) {
                self::removeTree($full);
            } else {
                @unlink($full);
            }
        }
        @rmdir($dir);
    }

    public static function migrateLayout(array $projects): void
    {
        $data = rtrim(app_config()['data_dir'], '/');
        $known = [];
        foreach ($projects as $project) {
            if (!is_array($project)) {
                continue;
            }
            $name = (string)($project['folder_name'] ?? '');
            if (!preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $name) || in_array($name, ['wiki', 'skills', 'sessions', 'projects'], true)) {
                continue;
            }
            $known[$name] = $project;
        }
        $legacy = $data . '/projects';
        if (is_dir($legacy) && !is_link($legacy)) {
            foreach (array_keys($known) as $name) {
                $from = $legacy . '/' . $name;
                $to = $data . '/' . $name;
                if (!is_dir($from) || is_link($from) || is_file($to) || is_link($to) || is_dir($to)) {
                    continue;
                }
                @rename($from, $to);
            }
            $left = array_diff(scandir($legacy) ?: [], ['.', '..']);
            if ($left === []) {
                @rmdir($legacy);
            }
        }
        foreach ($known as $name => $project) {
            $root = $data . '/' . $name;
            if (is_dir($root) && !is_link($root)) {
                self::ensureWiki($name, $project);
            }
        }
    }

    private static function ensureWiki(string $folder, array $project): void
    {
        $root = self::root($folder);
        $wiki = $root . '/wiki';
        if (!is_dir($wiki) && !mkdir($wiki, 0775, true) && !is_dir($wiki)) {
            return;
        }
        foreach (scandir($root) ?: [] as $name) {
            if (!str_ends_with(strtolower($name), '.md') || strtolower($name) === 'soul.md') {
                continue;
            }
            $from = $root . '/' . $name;
            if (!is_file($from) || is_link($from)) {
                continue;
            }
            $destName = $name === 'project_wiki.md' ? self::descFile($folder) : $name;
            $to = $wiki . '/' . $destName;
            if (!is_file($to)) {
                @rename($from, $to);
            }
        }
        $desc = $wiki . '/' . self::descFile($folder);
        $title = trim((string)($project['name'] ?? ''));
        if ($title === '') {
            $title = $folder;
        }
        $purpose = trim((string)($project['purpose'] ?? ''));
        $body = '# ' . $title . "\n\n" . ($purpose !== '' ? $purpose . "\n" : '');
        $current = is_file($desc) ? (string)file_get_contents($desc) : '';
        $stub = "# Project wiki\n\nLocal notes for {$title}.\n";
        if ($current === '' || trim($current) === trim($stub)) {
            file_put_contents($desc, $body);
        }
        $notes = $wiki . '/notes.md';
        if (!is_file($notes)) {
            file_put_contents($notes, "# Notes\n\n");
        }
        self::ensureSoul($folder);
    }
}
