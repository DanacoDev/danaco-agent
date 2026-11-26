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
        return $this->compactContextSummary((string)file_get_contents($path));
    }

    public function writeContextSummary(?string $projectFolder, string $summary): string
    {
        $summary = $this->compactContextSummary($summary);
        if ($summary === '') {
            return '';
        }
        $path = $this->contextSummaryPath($projectFolder);
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            return '';
        }
        if (file_put_contents($path, $summary . "\n") === false) {
            return '';
        }
        return $path;
    }

    public function compactContextSummary(string $summary): string
    {
        $summary = trim($summary);
        if ($summary === '') {
            return '';
        }
        $summary = preg_replace('/\A```(?:json)?\s*/i', '', $summary) ?? $summary;
        $summary = preg_replace('/\s*```\z/', '', $summary) ?? $summary;
        $summary = trim($summary);
        if ($summary === '') {
            return '';
        }
        $decoded = json_decode($summary, true);
        if (is_array($decoded) && !array_is_list($decoded)) {
            return $this->encodeContext($decoded);
        }
        $start = strpos($summary, '{');
        $end = strrpos($summary, '}');
        if ($start !== false && $end !== false && $end > $start) {
            $decoded = json_decode(substr($summary, $start, $end - $start + 1), true);
            if (is_array($decoded) && !array_is_list($decoded)) {
                return $this->encodeContext($decoded);
            }
        }
        $sections = $this->markdownContext($summary);
        if ($sections !== null) {
            $encoded = $this->encodeContext($sections);
            if ($encoded !== '') {
                return $encoded;
            }
        }
        $text = $this->oneSpace($summary);
        if ($text === '') {
            return '';
        }
        return $this->encodeContext(['text' => $text]);
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
        $name = 'chat.json';
        if ($folder !== '' && preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $folder)) {
            $name = $folder . '.json';
        }
        return $this->dataDir . '/.context/' . $name;
    }

    private function encodeContext(array $data): string
    {
        if (array_is_list($data)) {
            return '';
        }
        $clean = [];
        foreach (['decisions', 'results', 'facts', 'open', 'log'] as $key) {
            if (!array_key_exists($key, $data)) {
                continue;
            }
            $items = $this->contextLines($data[$key]);
            if ($items !== []) {
                $clean[$key] = $items;
            }
        }
        if (array_key_exists('text', $data)) {
            $text = $this->oneSpace(implode(' ', $this->contextLines($data['text'])));
            if ($text !== '') {
                $clean['text'] = $text;
            }
        }
        if ($clean === []) {
            return '';
        }
        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
        $json = json_encode($clean, $flags);
        if (!is_string($json)) {
            return '';
        }
        while (strlen($json) > 6000) {
            $key = '';
            foreach (['log', 'open', 'facts', 'results', 'decisions', 'text'] as $candidate) {
                if (array_key_exists($candidate, $clean)) {
                    $key = $candidate;
                    break;
                }
            }
            if ($key === '') {
                return '';
            }
            $before = strlen($json);
            if ($key === 'text') {
                $clean['text'] = rtrim(substr((string)$clean['text'], 0, max(0, strlen((string)$clean['text']) - 200)));
                if ($clean['text'] === '') {
                    unset($clean['text']);
                }
            } else {
                $last = count($clean[$key]) - 1;
                $item = (string)$clean[$key][$last];
                if (strlen($item) > 240) {
                    $clean[$key][$last] = rtrim(substr($item, 0, strlen($item) - 200));
                    if ($clean[$key][$last] === '') {
                        array_pop($clean[$key]);
                    }
                } else {
                    array_pop($clean[$key]);
                }
                if ($clean[$key] === []) {
                    unset($clean[$key]);
                }
            }
            if ($clean === []) {
                return '';
            }
            $json = json_encode($clean, $flags);
            if (!is_string($json) || strlen($json) >= $before) {
                return '';
            }
        }
        return $json;
    }

    private function contextLines(mixed $value): array
    {
        if (is_string($value) || is_numeric($value)) {
            $line = $this->oneSpace((string)$value);
            return $line === '' ? [] : [$line];
        }
        if (!is_array($value)) {
            return [];
        }
        $lines = [];
        foreach ($value as $item) {
            if (!is_string($item) && !is_numeric($item)) {
                continue;
            }
            $line = $this->oneSpace((string)$item);
            if ($line !== '') {
                $lines[] = $line;
            }
        }
        return $lines;
    }

    private function oneSpace(string $text): string
    {
        $text = preg_replace('/\s+/u', ' ', $text);
        return trim(is_string($text) ? $text : '');
    }

    private function markdownContext(string $summary): ?array
    {
        $summary = preg_replace('/\A# Conversation memory\s*/', '', $summary) ?? $summary;
        $map = [
            'decisions' => 'decisions',
            'results' => 'results',
            'facts' => 'facts',
            'open questions' => 'open',
        ];
        $data = [];
        $section = '';
        $saw = false;
        foreach (preg_split('/\R/', $summary) ?: [] as $line) {
            $trim = trim($line);
            if ($trim === '') {
                continue;
            }
            $header = strtolower(trim($trim, "#* \t"));
            if (isset($map[$header])) {
                $section = $map[$header];
                $saw = true;
                continue;
            }
            if (preg_match('/^(user|agent)\s*:\s*(.*)$/i', $trim, $match)) {
                $who = strtolower($match[1]) === 'agent' ? 'a' : 'u';
                $data['log'][] = $who . ': ' . $match[2];
                $saw = true;
                continue;
            }
            if ($section === '') {
                continue;
            }
            $item = preg_replace('/^(?:[-*]|\d+[.)])\s+/', '', $trim) ?? $trim;
            $data[$section][] = $item;
        }
        return $saw ? $data : null;
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
