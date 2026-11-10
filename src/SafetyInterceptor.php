<?php

class SafetyInterceptor
{
    public function __construct(private string $dataDir)
    {
        $this->dataDir = rtrim($dataDir, '/');
    }

    public function writeFile(string $projectFolder, string $relativePath, string $content): array
    {
        if ($this->machinePath($relativePath)) {
            return $this->writeMachine($relativePath, $content);
        }
        $relativePath = $this->descriptionPath($projectFolder, $this->projectPath($projectFolder, $relativePath));
        $this->rejectProjectSkill($relativePath);
        if (!str_starts_with($relativePath, 'uploads/')) {
            $this->assertMarkdownLocation($relativePath);
        }
        $projectRoot = $this->projectRoot($projectFolder);
        $target = $this->resolveInside($projectRoot, $relativePath, true);
        $dir = dirname($target);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Could not create directory.');
        }
        $backedUp = false;
        if (is_file($target)) {
            $this->backupExisting($projectFolder, $relativePath, $target);
            $backedUp = true;
        }
        if (file_put_contents($target, $content) === false) {
            throw new RuntimeException('Could not write file.');
        }
        if ($this->isMarkdown($relativePath)) {
            $this->reindexWiki();
        }
        return ['path' => $this->cleanRelative($relativePath), 'backed_up' => $backedUp];
    }

    public function fileExists(string $projectFolder, string $relativePath): bool
    {
        if ($projectFolder === Projects::generalFolder() && !is_dir(Projects::root($projectFolder))) {
            return false;
        }
        $relativePath = $this->projectPath($projectFolder, $relativePath);
        try {
            $target = $this->resolveInside($this->projectRoot($projectFolder), $relativePath, false);
        } catch (RuntimeException $e) {
            if ($e->getMessage() === 'Path not found.') {
                return false;
            }
            throw $e;
        }
        return is_file($target);
    }

    public function deleteFile(string $projectFolder, string $relativePath): array
    {
        if ($this->machinePath($relativePath)) {
            return $this->deleteMachine($relativePath);
        }
        $relativePath = $this->projectPath($projectFolder, $relativePath);
        $projectRoot = $this->projectRoot($projectFolder);
        $target = $this->resolveInside($projectRoot, $relativePath, false);
        $isDir = is_dir($target) && !is_link($target);
        if (!$isDir && !is_file($target)) {
            throw new RuntimeException('File not found.');
        }
        $trashDir = $this->dataDir . '/.trashcan';
        if (!is_dir($trashDir)) {
            mkdir($trashDir, 0775, true);
        }
        $base = $this->uniqueName($trashDir, basename($target));
        $dest = $trashDir . '/' . $base;
        if (!@rename($target, $dest)) {
            if ($isDir || !@copy($target, $dest) || !@unlink($target)) {
                throw new RuntimeException($isDir ? 'Could not move that folder to trash.' : 'Could not move file to trash.');
            }
        }
        $meta = [
            'original' => $projectFolder . '/' . $relativePath,
            'project_folder' => $projectFolder,
            'relative_path' => $relativePath,
            'deleted_at' => date('c'),
            'filename' => basename($target),
            'stored_as' => $base,
            'is_dir' => $isDir,
        ];
        file_put_contents($trashDir . '/' . $base . '.json', json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        if ($isDir || $this->isMarkdown($relativePath)) {
            $this->reindexWiki();
        }
        return $meta;
    }

    public function moveFile(string $projectFolder, string $from, string $to): array
    {
        if ($this->machinePath($from) || $this->machinePath($to)) {
            if (!$this->machinePath($from) || !$this->machinePath($to)) {
                throw new RuntimeException('Move both paths on the computer, or both inside the project.');
            }
            return $this->moveMachine($from, $to);
        }
        $from = $this->projectPath($projectFolder, $from);
        $to = $this->projectPath($projectFolder, $to);
        if ($from === $to || (is_dir($this->projectRoot($projectFolder) . '/' . $from) && str_starts_with($to, $from . '/'))) {
            throw new RuntimeException('Cannot move a folder into itself.');
        }
        $this->rejectProjectSkill($to);
        if ($this->isMarkdown($to)) {
            $this->assertMarkdownLocation($to);
        }
        $root = $this->projectRoot($projectFolder);
        $source = $this->resolveInside($root, $from, false);
        if (!file_exists($source) || is_link($source)) {
            throw new RuntimeException('File not found.');
        }
        $dest = $this->resolveInside($root, $to, true);
        if (file_exists($dest)) {
            throw new RuntimeException('Something already exists at that path.');
        }
        $dir = dirname($dest);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Could not create directory.');
        }
        if (!@rename($source, $dest)) {
            throw new RuntimeException('Could not move that file.');
        }
        if ($this->isMarkdown($from) || $this->isMarkdown($to) || is_dir($dest)) {
            $this->reindexWiki();
        }
        return ['from' => $from, 'to' => $to];
    }

    public function readFile(string $projectFolder, string $relativePath, int $max = 20000): string
    {
        if ($this->machinePath($relativePath)) {
            $target = $this->resolveMachine($relativePath, false);
            if (!is_file($target)) {
                throw new RuntimeException('File not found.');
            }
            return $this->readText($target, $max);
        }
        $relativePath = $this->descriptionPath($projectFolder, $this->projectPath($projectFolder, $relativePath));
        $target = $this->resolveInside($this->projectRoot($projectFolder), $relativePath, false);
        if (!is_file($target)) {
            throw new RuntimeException('File not found.');
        }
        return $this->readText($target, $max);
    }

    public function readBytes(string $projectFolder, string $relativePath, int $max = 8388608): array
    {
        if ($this->machinePath($relativePath)) {
            $target = $this->resolveMachine($relativePath, false);
            if (!is_file($target)) {
                throw new RuntimeException('File not found.');
            }
            $size = filesize($target);
            if ($size === false || $size > $max) {
                throw new RuntimeException('That file is too large.');
            }
            $data = file_get_contents($target);
            if ($data === false) {
                throw new RuntimeException('Could not read file.');
            }
            return ['path' => $target, 'bytes' => $data];
        }
        $relativePath = $this->projectPath($projectFolder, $relativePath);
        $target = $this->resolveInside($this->projectRoot($projectFolder), $relativePath, false);
        if (!is_file($target)) {
            throw new RuntimeException('File not found.');
        }
        $size = filesize($target);
        if ($size === false || $size > $max) {
            throw new RuntimeException('That file is too large.');
        }
        $data = file_get_contents($target);
        if ($data === false) {
            throw new RuntimeException('Could not read file.');
        }
        return ['path' => $this->cleanRelative($relativePath), 'bytes' => $data];
    }

    public function listWorkspace(array $projects): array
    {
        $nodes = [];
        $wiki = $this->dataDir . '/wiki';
        $children = [];
        if (is_dir($wiki)) {
            $real = realpath($wiki);
            if ($real !== false) {
                $children = array_values(array_filter(
                    $this->tag($this->walk($real, $real, 0), 'wiki', '', 'Main'),
                    static fn(array $node): bool => strtolower((string)($node['name'] ?? '')) !== 'tools.md'
                ));
            }
        }
        $nodes[] = [
            'name' => 'Main',
            'path' => '',
            'type' => 'dir',
            'scope' => 'wiki',
            'folder' => '',
            'place' => 'Main',
            'children' => $children,
        ];
        $generalFolder = Projects::generalFolder();
        $generalRoot = Projects::root($generalFolder);
        if (is_dir($generalRoot) && !is_link($generalRoot)) {
            $real = realpath($generalRoot);
            if ($real !== false) {
                $nodes[] = [
                    'name' => 'General chat',
                    'path' => '',
                    'type' => 'dir',
                    'scope' => 'project',
                    'folder' => $generalFolder,
                    'place' => 'General chat',
                    'children' => $this->tag($this->walk($real, $real, 0), 'project', $generalFolder, 'General chat'),
                ];
            }
        }
        foreach ($projects as $project) {
            $folder = (string)($project['folder_name'] ?? '');
            if (!preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $folder)) {
                continue;
            }
            $root = Projects::root($folder);
            if (!is_dir($root)) {
                continue;
            }
            $real = realpath($root);
            if ($real === false) {
                continue;
            }
            $nodes[] = [
                'name' => $folder,
                'path' => '',
                'type' => 'dir',
                'scope' => 'project',
                'folder' => $folder,
                'place' => $folder,
                'children' => $this->tag($this->walk($real, $real, 0), 'project', $folder, $folder),
            ];
        }
        return $nodes;
    }

    public function readWorkspace(string $scope, string $folder, string $path, int $max = 100000): array
    {
        if ($scope === 'wiki') {
            $this->rejectToolNotes($path);
            $root = realpath($this->dataDir . '/wiki');
            if ($root === false) {
                throw new RuntimeException('Main wiki not found.');
            }
            $this->assertAgentPathAllowed($path);
            $target = $this->resolveInside($root, $path, false);
            $relative = $this->cleanRelative($path);
            return ['path' => 'Main/' . $relative, 'content' => $this->readText($target, $max)];
        }
        if ($scope !== 'project') {
            throw new RuntimeException('Unknown file location.');
        }
        $content = $this->readFile($folder, $path, $max);
        return ['path' => $folder . '/' . $this->cleanRelative($path), 'content' => $content];
    }

    public function readWorkspaceBytes(string $scope, string $folder, string $path, int $max = 8388608): array
    {
        if ($scope === 'wiki') {
            $this->rejectToolNotes($path);
            $root = realpath($this->dataDir . '/wiki');
            if ($root === false) {
                throw new RuntimeException('Main wiki not found.');
            }
            $this->assertAgentPathAllowed($path);
            $target = $this->resolveInside($root, $path, false);
            if (!is_file($target)) {
                throw new RuntimeException('File not found.');
            }
            $size = filesize($target);
            if ($size === false || $size > $max) {
                throw new RuntimeException('That file is too large.');
            }
            $data = file_get_contents($target);
            if ($data === false) {
                throw new RuntimeException('Could not read file.');
            }
            return ['path' => 'Main/' . $this->cleanRelative($path), 'bytes' => $data];
        }
        if ($scope !== 'project') {
            throw new RuntimeException('Unknown file location.');
        }
        return $this->readBytes($folder, $path, $max);
    }

    public function locateWorkspace(string $scope, string $folder, string $path): array
    {
        $root = $this->workspaceRoot($scope, $folder);
        if ($path === '') {
            return ['root' => $root, 'file' => $root, 'relative' => ''];
        }
        if ($scope === 'wiki') {
            $this->rejectToolNotes($path);
        }
        $this->assertAgentPathAllowed($path);
        $relative = $this->cleanRelative($path);
        return [
            'root' => $root,
            'file' => $this->resolveInside($root, $relative, false),
            'relative' => $relative,
        ];
    }

    public function writeWorkspace(string $scope, string $folder, string $path, string $content): array
    {
        if ($scope === 'wiki') {
            $this->rejectToolNotes($path);
            $root = realpath($this->dataDir . '/wiki');
            if ($root === false) {
                throw new RuntimeException('Main wiki not found.');
            }
            $this->assertAgentPathAllowed($path);
            $relative = $this->cleanRelative($path);
            if (!$this->isMarkdown($relative)) {
                throw new RuntimeException('Only markdown can be edited in the Main wiki.');
            }
            $target = $this->resolveInside($root, $relative, false);
            if (file_put_contents($target, $content) === false) {
                throw new RuntimeException('Could not write file.');
            }
            $this->reindexWiki();
            return ['path' => 'Main/' . $relative];
        }
        if ($scope !== 'project') {
            throw new RuntimeException('Unknown file location.');
        }
        $written = $this->writeFile($folder, $path, $content);
        return ['path' => $folder . '/' . $written['path']];
    }

    public function deleteWorkspace(string $scope, string $folder, string $path): array
    {
        if ($scope === 'project') {
            return $this->deleteFile($folder, $path);
        }
        if ($scope !== 'wiki') {
            throw new RuntimeException('Unknown file location.');
        }
        $this->rejectToolNotes($path);
        $root = realpath($this->dataDir . '/wiki');
        if ($root === false) {
            throw new RuntimeException('Main wiki not found.');
        }
        $this->assertAgentPathAllowed($path);
        $relative = $this->cleanRelative($path);
        $target = $this->resolveInside($root, $relative, false);
        $isDir = is_dir($target) && !is_link($target);
        if (!$isDir && !is_file($target)) {
            throw new RuntimeException('File not found.');
        }
        $trashDir = $this->dataDir . '/.trashcan';
        if (!is_dir($trashDir)) {
            mkdir($trashDir, 0775, true);
        }
        $base = $this->uniqueName($trashDir, basename($target));
        $dest = $trashDir . '/' . $base;
        if (!@rename($target, $dest)) {
            if ($isDir || !@copy($target, $dest) || !@unlink($target)) {
                throw new RuntimeException($isDir ? 'Could not move that folder to trash.' : 'Could not move file to trash.');
            }
        }
        $meta = [
            'original' => 'Main/' . $relative,
            'project_folder' => 'wiki',
            'relative_path' => $relative,
            'deleted_at' => date('c'),
            'filename' => basename($target),
            'stored_as' => $base,
            'is_dir' => $isDir,
        ];
        file_put_contents($trashDir . '/' . $base . '.json', json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $this->reindexWiki();
        return $meta;
    }

    public function moveWorkspace(string $scope, string $folder, string $path, string $destScope, string $destFolder, string $destPath): array
    {
        if ($path === '') {
            throw new RuntimeException('Top-level folders cannot be moved.');
        }
        $source = $this->locateWorkspace($scope, $folder, $path);
        $sourceReal = realpath($source['file']);
        if ($sourceReal === false || is_link($source['file'])) {
            throw new RuntimeException('File not found.');
        }
        $isDir = is_dir($sourceReal) && !is_link($sourceReal);
        if (!$isDir && !is_file($sourceReal)) {
            throw new RuntimeException('File not found.');
        }
        $dest = $this->locateWorkspace($destScope, $destFolder, $destPath);
        $destReal = realpath($dest['file']);
        if ($destReal === false || !is_dir($destReal) || is_link($dest['file'])) {
            throw new RuntimeException('Drop onto a folder.');
        }
        $sourceRelative = $source['relative'];
        $slash = strrpos($sourceRelative, '/');
        $parent = $slash === false ? '' : substr($sourceRelative, 0, $slash);
        if ($source['root'] === $dest['root'] && $parent === $dest['relative']) {
            return [
                'scope' => $scope,
                'folder' => $folder,
                'path' => $sourceRelative,
                'from' => $sourceRelative,
                'to' => $sourceRelative,
            ];
        }
        if ($destReal === $sourceReal || ($isDir && str_starts_with($destReal, $sourceReal . DIRECTORY_SEPARATOR))) {
            throw new RuntimeException('Cannot move a folder into itself.');
        }
        $name = basename($sourceRelative);
        $targetRelative = $dest['relative'] === '' ? $name : $dest['relative'] . '/' . $name;
        if ($destScope === 'wiki') {
            $this->rejectToolNotes($targetRelative);
        }
        $this->assertAgentPathAllowed($targetRelative);
        if ($destScope === 'project') {
            $this->assertMarkdownLocation($targetRelative);
        }
        $target = $this->resolveInside($dest['root'], $targetRelative, true);
        if (file_exists($target)) {
            throw new RuntimeException('Something already exists at that path.');
        }
        if (!@rename($sourceReal, $target)) {
            throw new RuntimeException($isDir ? 'Could not move that folder.' : 'Could not move that file.');
        }
        if ($scope === 'wiki' || $destScope === 'wiki' || $isDir || $this->isMarkdown($sourceRelative) || $this->isMarkdown($targetRelative)) {
            $this->reindexWiki();
        }
        return [
            'scope' => $destScope,
            'folder' => $destFolder,
            'path' => $targetRelative,
            'from' => $sourceRelative,
            'to' => $targetRelative,
        ];
    }

    public function searchWorkspace(string $query, array $projects): array
    {
        $query = trim($query);
        if ($query === '') {
            throw new InvalidArgumentException('Enter a search.');
        }
        $roots = [['root' => $this->dataDir . '/wiki', 'scope' => 'wiki', 'folder' => '', 'place' => 'Main']];
        $generalFolder = Projects::generalFolder();
        $generalRoot = Projects::root($generalFolder);
        if (is_dir($generalRoot) && !is_link($generalRoot)) {
            $roots[] = [
                'root' => $generalRoot,
                'scope' => 'project',
                'folder' => $generalFolder,
                'place' => 'General chat',
            ];
        }
        foreach ($projects as $project) {
            $folder = (string)($project['folder_name'] ?? '');
            if (!preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $folder)) {
                continue;
            }
            $roots[] = [
                'root' => Projects::root($folder),
                'scope' => 'project',
                'folder' => $folder,
                'place' => $folder,
            ];
        }
        $hits = [];
        foreach ($roots as $root) {
            if (!is_dir($root['root'])) {
                continue;
            }
            $real = realpath($root['root']);
            if ($real === false) {
                continue;
            }
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($real, FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if (!$file->isFile() || is_link($file->getPathname())) {
                    continue;
                }
                $name = $file->getFilename();
                if ($root['scope'] === 'wiki' && strtolower($name) === 'tools.md') {
                    continue;
                }
                $full = $file->getPathname();
                $rel = ltrim(substr($full, strlen($real)), '/\\');
                $rel = str_replace('\\', '/', $rel);
                $nameHit = stripos($name, $query) !== false || stripos($rel, $query) !== false;
                $snippet = '';
                $contentHit = false;
                if ($file->getSize() <= 1000000) {
                    $text = (string)@file_get_contents($full, false, null, 0, 200000);
                    if ($text !== '' && !str_contains(substr($text, 0, 8000), "\0")) {
                        $pos = stripos($text, $query);
                        if ($pos !== false) {
                            $contentHit = true;
                            $start = max(0, $pos - 60);
                            $snippet = trim(preg_replace('/\s+/', ' ', substr($text, $start, 180)) ?? '');
                        }
                    }
                }
                if (!$nameHit && !$contentHit) {
                    continue;
                }
                $hits[] = [
                    'name' => $name,
                    'path' => $rel,
                    'scope' => $root['scope'],
                    'folder' => $root['folder'],
                    'place' => $root['place'],
                    'snippet' => $snippet,
                    'score' => ($nameHit ? 2 : 0) + ($contentHit ? 1 : 0),
                ];
                if (count($hits) >= 80) {
                    break 2;
                }
            }
        }
        usort($hits, static fn(array $a, array $b): int => $b['score'] <=> $a['score']);
        return array_slice($hits, 0, 40);
    }

    public function listFiles(string $projectFolder, string $relativePath = ''): array
    {
        if ($relativePath !== '' && $this->machinePath($relativePath)) {
            return $this->listMachine($relativePath);
        }
        $relativePath = $this->listRelativePath($projectFolder, $relativePath);
        $root = $this->projectRoot($projectFolder);
        $dir = $relativePath === '' ? $root : $this->resolveInside($root, $relativePath, false);
        if (!is_dir($dir)) {
            throw new RuntimeException('Directory not found.');
        }
        return $this->walk($dir, $root, 0);
    }

    public function readVault(string $kind, string $storedAs, int $max = 20000): string
    {
        $storedAs = $this->vaultName($storedAs);
        $file = $this->vaultDir($kind) . '/' . $storedAs;
        if (!is_file($file)) {
            throw new RuntimeException('File not found.');
        }
        return $this->readText($file, $max);
    }

    public function vaultListing(): string
    {
        $lines = [];
        foreach (array_merge($this->listBackups(), $this->listTrash()) as $item) {
            $when = (string)($item['deleted_at'] ?? $item['backed_up_at'] ?? '');
            $storedAs = (string)($item['stored_as'] ?? '');
            $size = $storedAs !== '' ? $this->vaultSize((string)($item['kind'] ?? ''), $storedAs) : 0;
            $lines[] = (string)($item['kind'] ?? '') . ' ' . $storedAs . ' ' . (string)($item['original'] ?? '') . ' size_bytes=' . $size . ($when !== '' ? ' ' . $when : '');
        }
        return $lines ? implode("\n", $lines) : 'No backups or trash.';
    }

    public function emptyOldTrash(int $days = 10): int
    {
        $dir = $this->dataDir . '/.trashcan';
        if (!is_dir($dir)) {
            return 0;
        }
        $cutoff = time() - ($days * 86400);
        $removed = 0;
        foreach (scandir($dir) ?: [] as $name) {
            if ($name === '.' || $name === '..' || str_ends_with($name, '.json')) {
                continue;
            }
            if (!preg_match('/^[A-Za-z0-9._-]+$/', $name)) {
                continue;
            }
            $file = $dir . '/' . $name;
            $metaPath = $file . '.json';
            $when = null;
            if (is_file($metaPath)) {
                $meta = json_decode((string)file_get_contents($metaPath), true);
                if (is_array($meta) && !empty($meta['deleted_at'])) {
                    $parsed = strtotime((string)$meta['deleted_at']);
                    $when = $parsed === false ? null : $parsed;
                }
            }
            if ($when === null) {
                $when = filemtime($file) ?: time();
            }
            if ($when > $cutoff) {
                continue;
            }
            if (is_dir($file) && !is_link($file)) {
                $this->removeTree($file);
            } elseif (is_file($file)) {
                @unlink($file);
            }
            if (is_file($metaPath)) {
                @unlink($metaPath);
            }
            $removed++;
        }
        return $removed;
    }

    public function listBackups(): array
    {
        return $this->listVault($this->dataDir . '/.backups');
    }

    public function listTrash(): array
    {
        return $this->listVault($this->dataDir . '/.trashcan');
    }

    public function restore(string $kind, string $storedAs): array
    {
        if ($kind !== 'backup' && $kind !== 'trash') {
            throw new InvalidArgumentException('Unknown restore source.');
        }
        $storedAs = $this->vaultName($storedAs);
        $dir = $this->vaultDir($kind);
        $file = $dir . '/' . $storedAs;
        $metaPath = $file . '.json';
        if (!file_exists($file) || is_link($file) || !is_file($metaPath)) {
            throw new RuntimeException('Restore item not found.');
        }
        $meta = json_decode((string)file_get_contents($metaPath), true);
        if (!is_array($meta) || empty($meta['project_folder']) || empty($meta['relative_path'])) {
            throw new RuntimeException('Restore metadata is missing.');
        }
        if ((string)$meta['project_folder'] === 'machine') {
            return $this->restoreMachine($meta, $file, $metaPath, $kind);
        }
        if ((string)$meta['project_folder'] === 'wiki') {
            $isDir = !empty($meta['is_dir']);
            if ($isDir ? !is_dir($file) : !is_file($file)) {
                throw new RuntimeException('Restore item not found.');
            }
            $root = realpath($this->dataDir . '/wiki');
            if ($root === false) {
                throw new RuntimeException('Main wiki not found.');
            }
            $relative = $this->cleanRelative((string)$meta['relative_path']);
            $dest = $this->resolveInside($root, $relative, true);
            if (file_exists($dest)) {
                throw new RuntimeException('Something already exists at that path.');
            }
            $parent = dirname($dest);
            if (!is_dir($parent) && !mkdir($parent, 0775, true) && !is_dir($parent)) {
                throw new RuntimeException('Could not create directory.');
            }
            if (!@rename($file, $dest)) {
                throw new RuntimeException($isDir ? 'Could not restore that folder.' : 'Could not restore that file.');
            }
            @unlink($metaPath);
            $this->reindexWiki();
            return ['restored' => 'Main/' . $relative, 'project_folder' => 'wiki', 'kind' => $kind];
        }
        if (!empty($meta['is_dir'])) {
            if (!is_dir($file)) {
                throw new RuntimeException('Restore item not found.');
            }
            $folder = (string)$meta['project_folder'];
            $relative = $this->cleanRelative((string)$meta['relative_path']);
            $dest = $this->resolveInside($this->projectRoot($folder), $relative, true);
            if (file_exists($dest)) {
                throw new RuntimeException('Something already exists at that path.');
            }
            $parent = dirname($dest);
            if (!is_dir($parent) && !mkdir($parent, 0775, true) && !is_dir($parent)) {
                throw new RuntimeException('Could not create directory.');
            }
            if (!@rename($file, $dest)) {
                throw new RuntimeException('Could not restore that folder.');
            }
            @unlink($metaPath);
            $this->reindexWiki();
            return ['restored' => $relative, 'project_folder' => $folder, 'kind' => $kind];
        }
        $content = (string)file_get_contents($file);
        $written = $this->writeFile((string)$meta['project_folder'], (string)$meta['relative_path'], $content);
        return ['restored' => $written['path'], 'project_folder' => $meta['project_folder'], 'kind' => $kind];
    }

    private function backupExisting(string $projectFolder, string $relativePath, string $target): void
    {
        $dir = $this->dataDir . '/.backups';
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $base = $this->uniqueName($dir, basename($target));
        if (!copy($target, $dir . '/' . $base)) {
            throw new RuntimeException('Could not back up file.');
        }
        $meta = [
            'original' => $projectFolder . '/' . $this->cleanRelative($relativePath),
            'project_folder' => $projectFolder,
            'relative_path' => $this->cleanRelative($relativePath),
            'backed_up_at' => date('c'),
            'filename' => basename($target),
            'stored_as' => $base,
        ];
        file_put_contents($dir . '/' . $base . '.json', json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    private function vaultDir(string $kind): string
    {
        if ($kind !== 'backup' && $kind !== 'trash') {
            throw new InvalidArgumentException('Unknown restore source.');
        }
        return $this->dataDir . ($kind === 'backup' ? '/.backups' : '/.trashcan');
    }

    private function vaultName(string $storedAs): string
    {
        $storedAs = basename($storedAs);
        if (!preg_match('/^[A-Za-z0-9._-]+$/', $storedAs) || str_ends_with($storedAs, '.json')) {
            throw new InvalidArgumentException('Invalid restore item.');
        }
        return $storedAs;
    }

    private function listVault(string $dir): array
    {
        if (!is_dir($dir)) {
            return [];
        }
        $items = [];
        foreach (scandir($dir) ?: [] as $name) {
            if (!str_ends_with($name, '.json')) {
                continue;
            }
            $meta = json_decode((string)file_get_contents($dir . '/' . $name), true);
            if (!is_array($meta)) {
                continue;
            }
            $meta['kind'] = str_contains($dir, '.trashcan') ? 'trash' : 'backup';
            $items[] = $meta;
        }
        usort($items, static function (array $a, array $b): int {
            return strcmp((string)($b['deleted_at'] ?? $b['backed_up_at'] ?? ''), (string)($a['deleted_at'] ?? $a['backed_up_at'] ?? ''));
        });
        return $items;
    }

    private function vaultSize(string $kind, string $storedAs): int
    {
        $path = $this->vaultDir($kind) . '/' . $storedAs;
        if (is_file($path)) {
            return (int)(filesize($path) ?: 0);
        }
        if (!is_dir($path) || is_link($path)) {
            return 0;
        }
        $size = 0;
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if ($file->isFile() && !$file->isLink()) {
                $size += (int)$file->getSize();
            }
        }
        return $size;
    }

    private function uniqueName(string $dir, string $filename): string
    {
        $filename = preg_replace('/[^A-Za-z0-9._-]+/', '_', $filename) ?: 'file';
        $base = date('Ymd_His') . '_' . $filename;
        if (!file_exists($dir . '/' . $base)) {
            return $base;
        }
        return date('Ymd_His') . '_' . substr(uniqid(), -4) . '_' . $filename;
    }

    private function workspaceRoot(string $scope, string $folder): string
    {
        if ($scope === 'wiki') {
            $root = realpath($this->dataDir . '/wiki');
            if ($root === false || !is_dir($root)) {
                throw new RuntimeException('Main wiki not found.');
            }
            return $root;
        }
        if ($scope !== 'project') {
            throw new RuntimeException('Unknown file location.');
        }
        if (!preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $folder) || in_array($folder, ['wiki', 'skills', 'sessions', 'projects'], true) || str_contains($folder, 'trashcan') || str_contains($folder, 'backups')) {
            throw new RuntimeException('Invalid project folder.');
        }
        $full = $this->dataDir . '/' . $folder;
        if (is_link($full)) {
            throw new RuntimeException('Invalid project folder.');
        }
        $root = realpath($full);
        $data = realpath($this->dataDir);
        if ($root === false || $data === false || !is_dir($root) || $root === $data || !$this->isInside($root, $data)) {
            throw new RuntimeException('Project folder not found.');
        }
        return $root;
    }

    private function assertAgentPathAllowed(string $relativePath): void
    {
        $norm = str_replace('\\', '/', $relativePath);
        if (str_contains($norm, '.trashcan') || str_contains($norm, '.backups') || str_contains($norm, "\0")) {
            throw new RuntimeException('Backups and trash cannot be changed or deleted. You can read and restore them.');
        }
    }

    private function projectRoot(string $projectFolder): string
    {
        if (!preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $projectFolder)) {
            throw new RuntimeException('Invalid project folder.');
        }
        $root = Projects::root($projectFolder);
        if (!is_dir($root) && !mkdir($root, 0775, true) && !is_dir($root)) {
            throw new RuntimeException('Could not open project folder.');
        }
        if ($projectFolder !== Projects::generalFolder()) {
            $wiki = $root . '/wiki';
            if (!is_dir($wiki) && !mkdir($wiki, 0775, true) && !is_dir($wiki)) {
                throw new RuntimeException('Could not open project wiki.');
            }
        }
        $real = realpath($root);
        if ($real === false) {
            throw new RuntimeException('Could not resolve project folder.');
        }
        return $real;
    }

    private function resolveInside(string $projectRoot, string $relativePath, bool $allowMissing): string
    {
        $relativePath = $this->cleanRelative($relativePath);
        $parts = explode('/', $relativePath);
        foreach ($parts as $part) {
            if ($part === '' || $part === '.' || $part === '..') {
                throw new RuntimeException('Invalid path.');
            }
        }
        $full = $projectRoot . '/' . $relativePath;
        if (is_link($full)) {
            throw new RuntimeException('Symlinks are not allowed.');
        }
        $parent = dirname($full);
        if (!is_dir($parent)) {
            if (!$allowMissing) {
                throw new RuntimeException('Path not found.');
            }
            return $full;
        }
        $parentReal = realpath($parent);
        if ($parentReal === false || !$this->isInside($parentReal, $projectRoot)) {
            throw new RuntimeException('Path escapes project folder.');
        }
        if (file_exists($full)) {
            $real = realpath($full);
            if ($real === false || !$this->isInside($real, $projectRoot)) {
                throw new RuntimeException('Path escapes project folder.');
            }
            return $real;
        }
        if (!$allowMissing) {
            throw new RuntimeException('Path not found.');
        }
        return $full;
    }

    private function isInside(string $path, string $root): bool
    {
        return $path === $root || str_starts_with($path, $root . DIRECTORY_SEPARATOR);
    }

    private function tag(array $nodes, string $scope, string $folder, string $place): array
    {
        foreach ($nodes as &$node) {
            $node['scope'] = $scope;
            $node['folder'] = $folder;
            $node['place'] = $place;
            if (!empty($node['children']) && is_array($node['children'])) {
                $node['children'] = $this->tag($node['children'], $scope, $folder, $place);
            }
        }
        return $nodes;
    }

    private function rejectProjectSkill(string $relativePath): void
    {
        $path = strtolower(str_replace('\\', '/', $relativePath));
        if (!preg_match('/\.(php|py)$/', $path)) {
            return;
        }
        if (!preg_match('#(^|/)skills/#', $path)) {
            return;
        }
        throw new RuntimeException('A skill is not a project file. Call write_skill with a .php or .py filename so it is registered in the Skills library. Later runs and scheduled tasks call it by name.');
    }

    private function assertMarkdownLocation(string $relativePath): void
    {
        if (!$this->isMarkdown($relativePath)) {
            return;
        }
        if (!preg_match('/^wiki\/[A-Za-z0-9][A-Za-z0-9._-]{0,80}\.md$/', $relativePath)) {
            throw new RuntimeException('Markdown files belong in the project wiki folder.');
        }
    }

    private function isMarkdown(string $relativePath): bool
    {
        return str_ends_with(strtolower(basename(str_replace('\\', '/', $relativePath))), '.md');
    }

    private function reindexWiki(): void
    {
        try {
            app_services()['memory']->reindex();
        } catch (Throwable $e) {
        }
    }

    private function descriptionPath(string $projectFolder, string $relativePath): string
    {
        if ($projectFolder === Projects::generalFolder()) {
            return $relativePath;
        }
        $leaf = basename(str_replace('\\', '/', $relativePath));
        if (!preg_match('/_desc\.md$/i', $leaf)) {
            return $relativePath;
        }
        return Projects::wikiRelative($projectFolder, $leaf);
    }

    private function projectPath(string $projectFolder, string $relativePath): string
    {
        $this->assertAgentPathAllowed($relativePath);
        $relativePath = $this->cleanRelative($relativePath);
        $folder = trim($projectFolder, '/');
        foreach (['data/' . $folder . '/', $folder . '/'] as $prefix) {
            if (str_starts_with($relativePath, $prefix)) {
                $relativePath = substr($relativePath, strlen($prefix));
                break;
            }
        }
        if ($relativePath === '' || $relativePath === $folder || $relativePath === 'data/' . $folder) {
            throw new RuntimeException('Name a file or folder inside the project.');
        }
        return $relativePath;
    }

    private function removeTree(string $dir): void
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
                $this->removeTree($full);
            } else {
                @unlink($full);
            }
        }
        @rmdir($dir);
    }

    private function listRelativePath(string $projectFolder, string $relativePath): string
    {
        $relativePath = str_replace('\\', '/', trim($relativePath));
        $relativePath = trim($relativePath, '/');
        if ($relativePath === '' || $relativePath === '.') {
            return '';
        }
        $folder = trim($projectFolder, '/');
        if ($relativePath === $folder || $relativePath === 'data/' . $folder) {
            return '';
        }
        return $this->projectPath($projectFolder, $relativePath);
    }

    private function cleanRelative(string $relativePath): string
    {
        $relativePath = str_replace('\\', '/', trim($relativePath));
        $relativePath = trim($relativePath, '/');
        if ($relativePath === '' || str_contains($relativePath, "\0")) {
            throw new RuntimeException('Invalid path.');
        }
        $parts = [];
        foreach (explode('/', $relativePath) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                throw new RuntimeException('Invalid path.');
            }
            $parts[] = $part;
        }
        if ($parts === []) {
            throw new RuntimeException('Invalid path.');
        }
        return implode('/', $parts);
    }

    private function rejectToolNotes(string $path): void
    {
        $name = strtolower(basename(str_replace('\\', '/', $path)));
        if ($name === 'tools.md') {
            throw new RuntimeException('That file is not available.');
        }
    }

    private function walk(string $dir, string $root, int $depth): array
    {
        if ($depth > 8) {
            return [];
        }
        $items = [];
        $names = scandir($dir) ?: [];
        sort($names, SORT_NATURAL | SORT_FLAG_CASE);
        foreach ($names as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            if (count($items) >= 400) {
                break;
            }
            $full = $dir . '/' . $name;
            if (is_link($full)) {
                continue;
            }
            $path = ltrim(substr($full, strlen($root)), '/');
            if (is_dir($full)) {
                $items[] = [
                    'name' => $name,
                    'path' => $path,
                    'type' => 'dir',
                    'children' => $this->walk($full, $root, $depth + 1),
                ];
                continue;
            }
            if (is_file($full)) {
                $items[] = ['name' => $name, 'path' => $path, 'type' => 'file'];
            }
        }
        return $items;
    }

    private function fullControl(): bool
    {
        return app_services()['db']->fullSystemControl();
    }

    private function machinePath(string $path): bool
    {
        $path = str_replace('\\', '/', trim($path));
        return $this->fullControl() && str_starts_with($path, '/');
    }

    private function assertMachinePath(string $real): void
    {
        foreach (['/dev', '/proc', '/sys', '/private/dev', '/etc/sudoers', '/private/etc/sudoers', '/etc/sudoers.d', '/private/etc/sudoers.d'] as $blocked) {
            if ($real === $blocked || str_starts_with($real, $blocked . '/')) {
                throw new RuntimeException('That path is not allowed.');
            }
        }
    }

    private function resolveMachine(string $path, bool $allowMissing): string
    {
        $path = str_replace('\\', '/', trim($path));
        if (!str_starts_with($path, '/') || str_contains($path, "\0")) {
            throw new RuntimeException('Invalid path.');
        }
        if (is_link($path) || file_exists($path)) {
            $real = realpath($path);
            if ($real === false) {
                throw new RuntimeException('Path not found.');
            }
            $this->assertMachinePath($real);
            return $real;
        }
        if (!$allowMissing) {
            throw new RuntimeException('Path not found.');
        }
        $parent = dirname($path);
        $realParent = $parent !== '' && $parent !== '.' ? realpath($parent) : false;
        if ($realParent === false || !is_dir($realParent)) {
            throw new RuntimeException('Path not found.');
        }
        $name = basename($path);
        if ($name === '' || $name === '.' || $name === '..') {
            throw new RuntimeException('Invalid path.');
        }
        $full = $realParent . '/' . $name;
        $this->assertMachinePath($full);
        return $full;
    }

    private function writeMachine(string $path, string $content): array
    {
        $target = $this->resolveMachine($path, true);
        $dir = dirname($target);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Could not create directory.');
        }
        $backedUp = false;
        if (is_file($target)) {
            $this->backupMachine($target);
            $backedUp = true;
        }
        if (file_put_contents($target, $content) === false) {
            throw new RuntimeException('Could not write file.');
        }
        return ['path' => $target, 'backed_up' => $backedUp];
    }

    private function backupMachine(string $target): void
    {
        $dir = $this->dataDir . '/.backups';
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $base = $this->uniqueName($dir, basename($target));
        if (!copy($target, $dir . '/' . $base)) {
            throw new RuntimeException('Could not back up file.');
        }
        $meta = [
            'original' => $target,
            'project_folder' => 'machine',
            'relative_path' => $target,
            'backed_up_at' => date('c'),
            'filename' => basename($target),
            'stored_as' => $base,
        ];
        file_put_contents($dir . '/' . $base . '.json', json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    private function deleteMachine(string $path): array
    {
        $target = $this->resolveMachine($path, false);
        $isDir = is_dir($target) && !is_link($target);
        if (!$isDir && !is_file($target)) {
            throw new RuntimeException('File not found.');
        }
        $trashDir = $this->dataDir . '/.trashcan';
        if (!is_dir($trashDir)) {
            mkdir($trashDir, 0775, true);
        }
        $base = $this->uniqueName($trashDir, basename($target));
        $dest = $trashDir . '/' . $base;
        if (!@rename($target, $dest)) {
            if ($isDir || !@copy($target, $dest) || !@unlink($target)) {
                throw new RuntimeException($isDir ? 'Could not move that folder to trash.' : 'Could not move file to trash.');
            }
        }
        $meta = [
            'original' => $target,
            'project_folder' => 'machine',
            'relative_path' => $target,
            'deleted_at' => date('c'),
            'filename' => basename($target),
            'stored_as' => $base,
            'is_dir' => $isDir,
        ];
        file_put_contents($trashDir . '/' . $base . '.json', json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        return $meta;
    }

    private function moveMachine(string $from, string $to): array
    {
        $source = $this->resolveMachine($from, false);
        $dest = $this->resolveMachine($to, true);
        if ($source === $dest || (is_dir($source) && str_starts_with($dest, $source . '/'))) {
            throw new RuntimeException('Cannot move a folder into itself.');
        }
        if (file_exists($dest)) {
            throw new RuntimeException('Something already exists at that path.');
        }
        $dir = dirname($dest);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Could not create directory.');
        }
        if (!@rename($source, $dest)) {
            throw new RuntimeException('Could not move that file.');
        }
        return ['from' => $source, 'to' => $dest];
    }

    private function listMachine(string $path): array
    {
        $dir = $this->resolveMachine($path, false);
        if (!is_dir($dir)) {
            throw new RuntimeException('Directory not found.');
        }
        $items = [];
        $names = scandir($dir) ?: [];
        sort($names, SORT_NATURAL | SORT_FLAG_CASE);
        foreach ($names as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            if (count($items) >= 400) {
                break;
            }
            $full = $dir . '/' . $name;
            if (is_link($full)) {
                continue;
            }
            if (is_dir($full)) {
                $items[] = ['name' => $name, 'path' => $full, 'type' => 'dir'];
                continue;
            }
            if (is_file($full)) {
                $items[] = ['name' => $name, 'path' => $full, 'type' => 'file'];
            }
        }
        return $items;
    }

    private function restoreMachine(array $meta, string $file, string $metaPath, string $kind): array
    {
        $dest = $this->resolveMachine((string)$meta['relative_path'], true);
        if (file_exists($dest)) {
            throw new RuntimeException('Something already exists at that path.');
        }
        $parent = dirname($dest);
        if (!is_dir($parent) && !mkdir($parent, 0775, true) && !is_dir($parent)) {
            throw new RuntimeException('Could not create directory.');
        }
        if (!@rename($file, $dest)) {
            throw new RuntimeException('Could not restore that file.');
        }
        @unlink($metaPath);
        return ['restored' => $dest, 'project_folder' => 'machine', 'kind' => $kind];
    }

    private function readText(string $target, int $max): string
    {
        $data = file_get_contents($target);
        if ($data === false) {
            throw new RuntimeException('Could not read file.');
        }
        if (str_contains(substr($data, 0, 8000), "\0")) {
            throw new RuntimeException('This file is not text.');
        }
        return clip_text($data, $max);
    }
}
