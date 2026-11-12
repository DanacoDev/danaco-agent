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
        if (in_array($folder, ['wiki', 'skills', 'sessions', 'projects', self::generalFolder()], true) || str_contains($folder, 'trashcan') || str_contains($folder, 'backups')) {
            throw new InvalidArgumentException('That folder name is reserved.');
        }
        return $folder;
    }

    public static function generalFolder(): string
    {
        return 'general';
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
        return 'PROJECT.md';
    }

    public static function notesFile(): string
    {
        return 'NOTES.md';
    }

    public static function folderFromName(string $name): string
    {
        $folder = strtolower(trim($name));
        $folder = preg_replace('/[^a-z0-9]+/', '-', $folder) ?? '';
        $folder = trim($folder, '-');
        if (strlen($folder) > 64) {
            $folder = rtrim(substr($folder, 0, 64), '-');
        }
        return self::sanitizeFolder($folder);
    }

    public static function renameLine(array $project): string
    {
        $from = trim((string)($project['former_name'] ?? ''));
        $to = trim((string)($project['name'] ?? ''));
        if ($from === '' || $to === '' || $from === $to) {
            return '';
        }
        $folder = trim((string)($project['folder_name'] ?? ''));
        $line = 'This project was renamed from ' . $from . ' to ' . $to . '.';
        if ($folder !== '') {
            $line .= ' The folder is ' . $folder . ' and the description file is wiki/' . self::descFile($folder) . '.';
        }
        return $line . ' Do not use the old name.';
    }

    public static function wikiRelative(string $folder, string $name): string
    {
        $name = basename(str_replace('\\', '/', trim($name)));
        if ($name === '' || preg_match('/_desc\.md$/i', $name) || strcasecmp($name, 'PROJECT.md') === 0) {
            $name = self::descFile($folder);
        } elseif (strcasecmp($name, 'notes.md') === 0) {
            $name = self::notesFile();
        }
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,80}\.md$/', $name)) {
            throw new InvalidArgumentException('Wiki file name must be a markdown filename.');
        }
        return 'wiki/' . $name;
    }

    public static function wikiRelativeFromArgs(string $folder, array $args): string
    {
        $name = '';
        foreach (['name', 'path', 'file', 'filepath'] as $key) {
            $value = trim((string)($args[$key] ?? ''));
            if ($value !== '') {
                $name = $value;
                break;
            }
        }
        return self::wikiRelative($folder, $name);
    }

    private static function descBody(string $name, string $description, string $goals, string $limitations, string $role): string
    {
        $sections = [
            'Description' => $description,
            'Intended goals and outcomes' => $goals,
            'Limitations' => $limitations,
            'Agent role' => $role,
        ];
        $body = '# ' . $name . "\n";
        foreach ($sections as $heading => $text) {
            if ($text === '') {
                continue;
            }
            $body .= "\n## " . $heading . "\n\n" . $text . "\n";
        }
        if ($body === '# ' . $name . "\n") {
            $body .= "\n";
        }
        return $body;
    }

    public static function create(Database $db, string $name, string $folder, string $purpose, string $type, string $goals = '', string $limitations = '', string $role = ''): array
    {
        $name = trim($name);
        if ($name === '') {
            throw new InvalidArgumentException('Project name is required.');
        }
        if (mb_strlen($name) > 25) {
            throw new InvalidArgumentException('Project name must be 25 characters or fewer.');
        }
        $purpose = trim($purpose);
        $goals = trim($goals);
        $limitations = trim($limitations);
        $role = trim($role);
        $folder = trim($folder);
        if ($folder === '') {
            try {
                $folder = self::folderFromName($name);
            } catch (InvalidArgumentException $e) {
                $message = $e->getMessage();
                if (str_contains($message, 'reserved')) {
                    throw new InvalidArgumentException('That project name is reserved.');
                }
                throw new InvalidArgumentException('Project name needs a letter or number so a folder can be created.');
            }
        } else {
            $folder = self::sanitizeFolder($folder);
        }
        if ($db->projectByFolder($folder)) {
            throw new InvalidArgumentException('A project with that name already exists.');
        }
        $root = self::root($folder);
        $wiki = $root . '/wiki';
        if (!is_dir($wiki) && !mkdir($wiki, 0775, true) && !is_dir($wiki)) {
            throw new RuntimeException('Could not create the project folder.');
        }
        $desc = $wiki . '/' . self::descFile($folder);
        if (!is_file($desc)) {
            $body = self::descBody($name, $purpose, $goals, $limitations, $role);
            if (file_put_contents($desc, $body) === false) {
                throw new RuntimeException('Could not create the project description.');
            }
        }
        $notes = $wiki . '/' . self::notesFile();
        if (!is_file($notes) && file_put_contents($notes, "# Notes\n\n") === false) {
            throw new RuntimeException('Could not create the project notes.');
        }
        $project = $db->insertProject($name, $folder, trim($purpose), trim($type));
        self::appendIndex($project);
        try {
            app_services()['memory']->reindex();
        } catch (Throwable $e) {
        }
        return $project;
    }

    public static function rename(Database $db, int $id, string $name): ?array
    {
        $name = trim($name);
        if ($name === '' || strlen($name) > 120) {
            throw new InvalidArgumentException('Project name is required.');
        }
        $project = $db->project($id);
        if (!$project) {
            return null;
        }
        $oldName = (string)$project['name'];
        $oldFolder = (string)$project['folder_name'];
        $newFolder = self::folderFromName($name);
        $moved = false;
        if ($newFolder !== $oldFolder) {
            $other = $db->projectByFolder($newFolder);
            if ($other && (int)$other['id'] !== $id) {
                throw new InvalidArgumentException('A project with that folder already exists.');
            }
            $from = self::root($oldFolder);
            $to = self::root($newFolder);
            if (!is_dir($from)) {
                $newFolder = $oldFolder;
            } elseif (file_exists($to)) {
                throw new InvalidArgumentException('A project with that folder already exists.');
            } elseif (!rename($from, $to)) {
                throw new RuntimeException('Could not rename the project folder.');
            } else {
                $moved = true;
            }
        }
        $former = null;
        if ($name !== $oldName) {
            $former = $oldName;
        } elseif ($moved) {
            $heading = self::descTitle($newFolder);
            if ($heading !== '' && strcasecmp($heading, $name) !== 0) {
                $former = $heading;
            }
        }
        try {
            $updated = $db->renameProject($id, $name, $moved ? $newFolder : null, $former);
        } catch (PDOException $e) {
            self::rollbackMove($oldFolder, $newFolder);
            throw new RuntimeException('Could not rename the project.');
        }
        if (!$updated) {
            self::rollbackMove($oldFolder, $newFolder);
            return null;
        }
        self::retitleDesc($updated['folder_name'], $name, [$oldName, (string)$former, $oldFolder, ucfirst($oldFolder)]);
        if ($moved) {
            self::removeIndex($oldFolder);
        }
        self::syncIndexName($updated);
        try {
            app_services()['memory']->reindex();
        } catch (Throwable $e) {
        }
        return $updated;
    }

    private static function rollbackMove(string $oldFolder, string $newFolder): void
    {
        if ($oldFolder === $newFolder) {
            return;
        }
        $from = self::root($newFolder);
        $to = self::root($oldFolder);
        if (!is_dir($from) || file_exists($to)) {
            return;
        }
        @rename($from, $to);
    }

    private static function descTitle(string $folder): string
    {
        $path = self::root($folder) . '/wiki/' . self::descFile($folder);
        if (!is_file($path)) {
            return '';
        }
        $text = (string)file_get_contents($path);
        $parts = preg_split('/\R/', $text, 2);
        $line = trim((string)(is_array($parts) ? $parts[0] : ''));
        if (!str_starts_with($line, '# ')) {
            return '';
        }
        $title = trim(substr($line, 2));
        if ($title === '' || strcasecmp($title, 'Project wiki') === 0) {
            return '';
        }
        return $title;
    }

    private static function retitleDesc(string $folder, string $newName, array $previous): void
    {
        $path = self::root($folder) . '/wiki/' . self::descFile($folder);
        if (!is_file($path)) {
            return;
        }
        $text = (string)file_get_contents($path);
        foreach ($previous as $old) {
            $old = trim((string)$old);
            if ($old === '' || $old === $newName) {
                continue;
            }
            $heading = '# ' . $old;
            if ($text !== $heading && !str_starts_with($text, $heading . "\n") && !str_starts_with($text, $heading . "\r\n")) {
                continue;
            }
            file_put_contents($path, '# ' . $newName . substr($text, strlen($heading)));
            return;
        }
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
            if (!preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $name) || in_array($name, ['wiki', 'skills', 'sessions', 'projects', self::generalFolder()], true)) {
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
        self::adoptWikiFile($wiki, self::descFile($folder), [$folder . '_desc.md']);
        if (!self::exactWikiFile($wiki, self::descFile($folder))) {
            $legacy = [];
            foreach (scandir($wiki) ?: [] as $item) {
                if (preg_match('/_desc\.md$/i', $item)) {
                    $legacy[] = $item;
                }
            }
            if (count($legacy) === 1) {
                self::adoptWikiFile($wiki, self::descFile($folder), $legacy);
            }
            self::adoptWikiFile($wiki, self::descFile($folder), ['PROJECT.md']);
        }
        self::adoptWikiFile($wiki, self::notesFile(), ['notes.md']);
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
        $notes = $wiki . '/' . self::notesFile();
        if (!self::exactWikiFile($wiki, self::notesFile())) {
            file_put_contents($notes, "# Notes\n\n");
        }
    }

    private static function exactWikiFile(string $dir, string $name): bool
    {
        foreach (scandir($dir) ?: [] as $item) {
            if ($item === $name) {
                return is_file($dir . '/' . $item);
            }
        }
        return false;
    }

    private static function adoptWikiFile(string $dir, string $to, array $fromNames): void
    {
        if (self::exactWikiFile($dir, $to) || $fromNames === []) {
            return;
        }
        $actual = '';
        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..' || !is_file($dir . '/' . $item)) {
                continue;
            }
            foreach ($fromNames as $from) {
                if ($item === $from || strcasecmp($item, $from) === 0) {
                    $actual = $item;
                    break 2;
                }
            }
        }
        if ($actual === '') {
            return;
        }
        $source = $dir . '/' . $actual;
        $tmp = $dir . '/.' . $to . '.adopting';
        if (!@rename($source, $tmp)) {
            return;
        }
        if (!@rename($tmp, $dir . '/' . $to)) {
            @rename($tmp, $source);
        }
    }

    public static function promptContext(string $folder, bool $files = false): string
    {
        if (!preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $folder)) {
            return '';
        }
        $root = self::root($folder);
        $notes = self::readProjectNotes($root);
        if ($notes === '') {
            $block = 'Notes for project ' . $folder . ', from wiki/NOTES.md:' . "\n" . 'No notes yet.';
        } else {
            $block = 'Notes for project ' . $folder . ', from wiki/NOTES.md:' . "\n" . $notes;
        }
        if (!$files) {
            return $block;
        }
        $found = [];
        $cut = false;
        self::collectProjectFiles($root, $root, 0, $found, $cut);
        sort($found, SORT_NATURAL | SORT_FLAG_CASE);
        if ($found === []) {
            $fileBlock = 'No files in project ' . $folder . ' yet, outside the wiki.';
        } else {
            $fileBlock = 'Files already in project ' . $folder . ', outside the wiki. Use this list to narrow the task before you delegate. Do not create a file that is already here, and do not overwrite one unless the task is to change that file:' . "\n- " . implode("\n- ", $found);
            if ($cut) {
                $fileBlock .= "\n- …more files exist. Call list_files before creating one.";
            }
        }
        return $block . "\n\n" . $fileBlock;
    }

    private static function readProjectNotes(string $root): string
    {
        $path = $root . '/wiki/' . self::notesFile();
        if (!is_file($path) || is_link($path)) {
            return '';
        }
        $raw = @file_get_contents($path, false, null, 0, 6500);
        if ($raw === false) {
            return '';
        }
        $notes = trim($raw);
        if ($notes === '') {
            return '';
        }
        $text = clip_text($notes, 6000);
        $size = filesize($path);
        if ($size !== false && $size > 6500 && !str_ends_with($text, '…[truncated]')) {
            $text .= "\n…[truncated]";
        }
        return $text;
    }

    private static function collectProjectFiles(string $dir, string $root, int $depth, array &$files, bool &$cut): void
    {
        if ($cut || $depth > 6 || !is_dir($dir) || is_link($dir)) {
            return;
        }
        $names = scandir($dir) ?: [];
        sort($names, SORT_NATURAL | SORT_FLAG_CASE);
        foreach ($names as $name) {
            if ($name === '.' || $name === '..' || str_starts_with($name, '.')) {
                continue;
            }
            if (count($files) >= 80) {
                $cut = true;
                return;
            }
            $full = $dir . '/' . $name;
            if (is_link($full)) {
                continue;
            }
            $relative = ltrim(substr($full, strlen($root)), '/');
            if ($relative === 'wiki' || str_starts_with($relative, 'wiki/')) {
                continue;
            }
            if (is_dir($full)) {
                self::collectProjectFiles($full, $root, $depth + 1, $files, $cut);
                continue;
            }
            if (is_file($full)) {
                $files[] = $relative;
            }
        }
    }
}
