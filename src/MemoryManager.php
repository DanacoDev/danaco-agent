<?php

class MemoryManager
{
    public function __construct(private string $dataDir)
    {
    }

    public function search(string $query, ?string $projectFolder = null): string
    {
        $query = trim($query);
        if ($query === '') {
            return 'Empty search.';
        }
        if (Process::commandExists('qmd')) {
            $result = Process::run(['qmd', 'search', $query], $this->dataDir, null, 30);
            if ($result['code'] === 0 && trim($result['stdout']) !== '') {
                return clip_text($result['stdout']);
            }
            if (trim($result['stderr']) !== '' && trim($result['stdout']) === '') {
                return "qmd search failed, using file scan.\n" . $this->fallbackSearch($query, $projectFolder);
            }
        }
        return $this->fallbackSearch($query, $projectFolder);
    }

    public function update(): string
    {
        return $this->qmd(['qmd', 'update'], 'update');
    }

    public function reindex(): void
    {
        if (!Process::commandExists('qmd')) {
            return;
        }
        $dirty = $this->dataDir . '/qmd-reindex.dirty';
        touch($dirty);
        $script = 'while [ -f ' . escapeshellarg($dirty) . ' ]; do rm -f ' . escapeshellarg($dirty) . '; qmd update; qmd embed; done';
        $log = escapeshellarg($this->dataDir . '/qmd.log');
        exec('cd ' . escapeshellarg($this->dataDir) . ' && nohup sh -c ' . escapeshellarg($script) . ' >> ' . $log . ' 2>&1 &');
    }

    public function embed(): string
    {
        return $this->qmd(['qmd', 'embed'], 'embed');
    }

    public function centralFiles(): array
    {
        $files = $this->markdownFiles($this->dataDir . '/wiki');
        return array_values(array_filter($files, static fn(array $file): bool => strtolower($file['name']) !== 'tools.md'));
    }

    public function relocateToolNotes(): bool
    {
        $from = $this->dataDir . '/wiki/tools.md';
        $to = $this->dataDir . '/tool-notes.md';
        if (!is_file($from)) {
            return false;
        }
        if (!is_file($to)) {
            return @rename($from, $to);
        }
        $extra = trim((string)file_get_contents($from));
        $kept = trim((string)file_get_contents($to));
        if ($extra !== '' && !str_contains($kept, $extra)) {
            file_put_contents($to, ($kept === '' ? '' : $kept . "\n") . $extra . "\n");
        }
        @unlink($from);
        return true;
    }

    public function toolNotesForPrompt(): string
    {
        $path = $this->dataDir . '/tool-notes.md';
        if (!is_file($path)) {
            return '';
        }
        $text = (string)file_get_contents($path);
        $text = preg_replace('/\A# Tools\s*/', '', $text) ?? $text;
        $text = preg_replace('/\ANotes the agent keeps about email, ftp, the headless browser, and other tools\.\s*/', '', $text) ?? $text;
        $text = trim($text);
        if ($text === '') {
            return '';
        }
        return "Tool notes:\n" . clip_text($text, 2500);
    }

    public function remember(string $tool, string $note): string
    {
        $tool = trim($tool);
        $note = trim(preg_replace('/\s+/', ' ', $note) ?? '');
        if ($tool === '' || strlen($tool) > 40 || $note === '' || strlen($note) > 500) {
            return 'A short tool name and note are required.';
        }
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9 ._-]{0,39}$/', $tool)) {
            return 'Tool name should be a short label such as email, ftp, or browser.';
        }
        $path = $this->dataDir . '/tool-notes.md';
        if (!is_file($path)) {
            file_put_contents($path, "# Tools\n\nNotes the agent keeps about email, ftp, the headless browser, and other tools.\n");
        }
        $line = '- ' . date('Y-m-d H:i') . ' · ' . $tool . ' — ' . $note . "\n";
        if (file_put_contents($path, $line, FILE_APPEND) === false) {
            return 'Could not save that note.';
        }
        return 'Remembered under ' . $tool . '.';
    }

    public function readContextSummary(?string $projectFolder): string
    {
        $path = $this->contextSummaryPath($projectFolder);
        if (!is_file($path)) {
            return '';
        }
        $text = (string)file_get_contents($path);
        $text = preg_replace('/\A# Conversation memory\s*/', '', $text) ?? $text;
        return trim($text);
    }

    public function writeContextSummary(?string $projectFolder, string $summary): string
    {
        $summary = trim($summary);
        if ($summary === '') {
            return '';
        }
        $path = $this->contextSummaryPath($projectFolder);
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            return '';
        }
        $body = "# Conversation memory\n\n" . $summary . "\n";
        if (file_put_contents($path, $body) === false) {
            return '';
        }
        $this->reindex();
        return $path;
    }

    public function readCentral(string $name): string
    {
        if (strtolower($name) === 'tools.md') {
            throw new RuntimeException('That file is not available.');
        }
        $file = $this->wikiFile($name);
        return clip_text((string)file_get_contents($file), 100000);
    }

    private function qmd(array $command, string $label): string
    {
        if (!Process::commandExists('qmd')) {
            return 'qmd is not installed. Wiki files are still available, and search scans markdown directly.';
        }
        $result = Process::run($command, $this->dataDir, null, 60);
        $text = trim($result['stdout'] . ($result['stderr'] !== '' ? "\n" . $result['stderr'] : ''));
        if ($text === '') {
            $text = 'qmd ' . $label . ' finished.';
        }
        return clip_text($text);
    }

    private function fallbackSearch(string $query, ?string $projectFolder): string
    {
        $files = $this->markdownFiles($this->dataDir . '/wiki');
        if ($projectFolder && preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $projectFolder)) {
            $local = Projects::root($projectFolder) . '/wiki';
            $files = array_merge($files, $this->markdownFiles($local));
        }
        $needle = strtolower($query);
        $hits = [];
        foreach ($files as $file) {
            $text = (string)@file_get_contents($file['path']);
            $hay = strtolower($text);
            $count = substr_count($hay, $needle);
            if ($count === 0) {
                continue;
            }
            $pos = strpos($hay, $needle);
            $start = max(0, $pos - 80);
            $snippet = trim(preg_replace('/\s+/', ' ', substr($text, $start, 240)) ?? '');
            $hits[] = ['score' => $count, 'file' => $file['name'], 'snippet' => $snippet];
        }
        usort($hits, static fn(array $a, array $b): int => $b['score'] <=> $a['score']);
        if (!$hits) {
            return 'No wiki matches for: ' . $query;
        }
        $lines = [];
        foreach (array_slice($hits, 0, 8) as $hit) {
            $lines[] = $hit['file'] . ': ' . $hit['snippet'];
        }
        return implode("\n", $lines);
    }

    private function markdownFiles(string $dir): array
    {
        if (!is_dir($dir)) {
            return [];
        }
        $out = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (!$file->isFile() || !str_ends_with(strtolower($file->getFilename()), '.md')) {
                continue;
            }
            if (count($out) >= 200) {
                break;
            }
            $out[] = ['name' => $file->getFilename(), 'path' => $file->getPathname()];
        }
        return $out;
    }

    private function contextSummaryPath(?string $projectFolder): string
    {
        $folder = strtolower(trim((string)$projectFolder));
        if ($folder !== '' && preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $folder)) {
            return Projects::root($folder) . '/wiki/context_memory.md';
        }
        return $this->dataDir . '/wiki/chat_memory.md';
    }

    private function wikiFile(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        if (!preg_match('/^[A-Za-z0-9._-]+\.md$/', $name)) {
            throw new InvalidArgumentException('Invalid wiki file.');
        }
        $root = realpath($this->dataDir . '/wiki');
        $file = $root . DIRECTORY_SEPARATOR . $name;
        if ($root === false || !is_file($file)) {
            throw new RuntimeException('Wiki file not found.');
        }
        $real = realpath($file);
        if ($real === false || !str_starts_with($real, $root . DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('Wiki file not found.');
        }
        return $real;
    }
}
