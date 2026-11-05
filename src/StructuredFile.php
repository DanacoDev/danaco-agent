<?php

class StructuredFile
{
    public function __construct(private SafetyInterceptor $safety)
    {
    }

    public function kind(string $name): string
    {
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if ($ext === 'csv') {
            return 'csv';
        }
        if (in_array($ext, ['db', 'sqlite', 'sqlite3'], true)) {
            return 'sqlite';
        }
        if ($ext === 'docx') {
            return 'docx';
        }
        return '';
    }

    public function read(string $scope, string $folder, string $path): array
    {
        $kind = $this->kind($path);
        if ($kind === '') {
            throw new InvalidArgumentException('That file type does not have a structured editor.');
        }
        $bytes = $this->safety->readWorkspaceBytes($scope, $folder, $path, 8 * 1024 * 1024)['bytes'];
        if ($kind === 'csv') {
            return ['kind' => $kind, 'data' => $this->readCsv($bytes)];
        }
        if ($kind === 'sqlite') {
            return ['kind' => $kind, 'data' => $this->readSqlite($bytes)];
        }
        return ['kind' => $kind, 'data' => $this->readDocx($bytes)];
    }

    public function save(string $scope, string $folder, string $path, array $body): array
    {
        $kind = $this->kind($path);
        if ($kind === '') {
            throw new InvalidArgumentException('That file type does not have a structured editor.');
        }
        $original = $this->safety->readWorkspaceBytes($scope, $folder, $path, 8 * 1024 * 1024)['bytes'];
        if ($kind === 'csv') {
            $bytes = $this->writeCsv($body);
        } elseif ($kind === 'sqlite') {
            $bytes = $this->writeSqlite($original, $body);
        } else {
            $bytes = $this->writeDocx($original, $body);
        }
        if (strlen($bytes) > 8 * 1024 * 1024) {
            throw new RuntimeException('That file is too large.');
        }
        return $this->safety->writeWorkspace($scope, $folder, $path, $bytes);
    }

    private function readCsv(string $bytes): array
    {
        $stream = fopen('php://temp', 'r+');
        if ($stream === false) {
            throw new RuntimeException('Could not open the CSV file.');
        }
        fwrite($stream, $bytes);
        rewind($stream);
        $rows = [];
        while (($row = fgetcsv($stream)) !== false) {
            if (count($rows) >= 10000) {
                throw new RuntimeException('CSV files are limited to 10,000 rows in the editor.');
            }
            $rows[] = array_map(static fn($value): string => (string)($value ?? ''), $row);
        }
        fclose($stream);
        $columns = 0;
        foreach ($rows as $row) {
            $columns = max($columns, count($row));
        }
        $columns = min(100, $columns);
        foreach ($rows as &$row) {
            $row = array_pad(array_slice($row, 0, $columns), $columns, '');
        }
        unset($row);
        $headers = $rows !== [] ? array_shift($rows) : [];
        $headers = array_pad(array_slice($headers, 0, $columns), $columns, '');
        return ['headers' => $headers, 'rows' => $rows];
    }

    private function writeCsv(array $body): string
    {
        $headers = $this->gridRow($body['headers'] ?? []);
        $rows = $body['rows'] ?? [];
        if (count($headers) > 100 || count($rows) > 10000) {
            throw new InvalidArgumentException('CSV files are limited to 100 columns and 10,000 rows.');
        }
        $stream = fopen('php://temp', 'r+');
        if ($stream === false) {
            throw new RuntimeException('Could not create the CSV file.');
        }
        fputcsv($stream, $headers);
        foreach ($rows as $row) {
            fputcsv($stream, array_pad(array_slice($this->gridRow($row), 0, count($headers)), count($headers), ''));
        }
        rewind($stream);
        $bytes = stream_get_contents($stream);
        fclose($stream);
        return is_string($bytes) ? $bytes : '';
    }

    private function readSqlite(string $bytes): array
    {
        $path = $this->temporaryFile($bytes, 'db');
        try {
            $db = $this->openSqlite($path);
            $tables = [];
            $names = $db->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
            foreach ($names as $name) {
                $table = $this->identifier((string)$name);
                $columns = $db->query('PRAGMA table_info("' . str_replace('"', '""', $table) . '")')->fetchAll();
                $rows = $db->query('SELECT * FROM "' . str_replace('"', '""', $table) . '" LIMIT 500')->fetchAll();
                $tables[] = ['name' => $table, 'columns' => $columns, 'rows' => $rows];
            }
            return ['tables' => $tables];
        } finally {
            @unlink($path);
            @unlink($path . '-wal');
            @unlink($path . '-shm');
        }
    }

    private function writeSqlite(string $bytes, array $body): string
    {
        $path = $this->temporaryFile($bytes, 'db');
        try {
            $db = $this->openSqlite($path);
            $table = $this->identifier((string)($body['table'] ?? ''));
            $action = (string)($body['action'] ?? '');
            $columns = $db->query('PRAGMA table_info("' . str_replace('"', '""', $table) . '")')->fetchAll();
            if (!$columns) {
                throw new InvalidArgumentException('Table not found.');
            }
            $allowed = array_column($columns, 'name');
            $quote = static fn(string $name): string => '"' . str_replace('"', '""', $name) . '"';
            $db->beginTransaction();
            if ($action === 'update') {
                $row = is_array($body['row'] ?? null) ? $body['row'] : [];
                $primary = null;
                foreach ($columns as $column) {
                    if ((int)$column['pk'] === 1) {
                        $primary = (string)$column['name'];
                        break;
                    }
                }
                if ($primary === null || !array_key_exists($primary, $row)) {
                    throw new InvalidArgumentException('Editing requires a primary-key column.');
                }
                $sets = [];
                $values = [];
                foreach ($allowed as $column) {
                    if ($column === $primary || !array_key_exists($column, $row)) {
                        continue;
                    }
                    $sets[] = $quote($column) . ' = ?';
                    $values[] = $row[$column];
                }
                if ($sets) {
                    $values[] = $row[$primary];
                    $db->prepare('UPDATE ' . $quote($table) . ' SET ' . implode(', ', $sets) . ' WHERE ' . $quote($primary) . ' = ?')->execute($values);
                }
            } elseif ($action === 'insert') {
                $row = is_array($body['row'] ?? null) ? $body['row'] : [];
                $insert = array_values(array_filter($allowed, static fn(string $column): bool => array_key_exists($column, $row)));
                if (!$insert) {
                    throw new InvalidArgumentException('Enter at least one value.');
                }
                $db->prepare('INSERT INTO ' . $quote($table) . ' (' . implode(', ', array_map($quote, $insert)) . ') VALUES (' . implode(', ', array_fill(0, count($insert), '?')) . ')')->execute(array_map(static fn(string $column) => $row[$column], $insert));
            } elseif ($action === 'delete') {
                $row = is_array($body['row'] ?? null) ? $body['row'] : [];
                $primary = null;
                foreach ($columns as $column) {
                    if ((int)$column['pk'] === 1) {
                        $primary = (string)$column['name'];
                        break;
                    }
                }
                if ($primary === null || !array_key_exists($primary, $row)) {
                    throw new InvalidArgumentException('Deleting requires a primary-key column.');
                }
                $db->prepare('DELETE FROM ' . $quote($table) . ' WHERE ' . $quote($primary) . ' = ?')->execute([$row[$primary]]);
            } else {
                throw new InvalidArgumentException('Unknown database action.');
            }
            $db->commit();
            $out = file_get_contents($path);
            if ($out === false) {
                throw new RuntimeException('Could not save the database.');
            }
            return $out;
        } finally {
            @unlink($path);
            @unlink($path . '-wal');
            @unlink($path . '-shm');
        }
    }

    private function readDocx(string $bytes): array
    {
        $path = $this->temporaryFile($bytes, 'docx');
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            @unlink($path);
            throw new RuntimeException('Could not open the DOCX file.');
        }
        $xml = $zip->getFromName('word/document.xml');
        $zip->close();
        @unlink($path);
        if (!is_string($xml)) {
            throw new RuntimeException('This DOCX file has no document body.');
        }
        $dom = $this->xml($xml);
        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
        $paragraphs = [];
        foreach ($xpath->query('//w:body/w:p') ?: [] as $paragraph) {
            $paragraphs[] = $this->nodeText($xpath, $paragraph);
        }
        $tables = [];
        foreach ($xpath->query('//w:body/w:tbl') ?: [] as $table) {
            $grid = [];
            foreach ($xpath->query('./w:tr', $table) ?: [] as $tr) {
                $row = [];
                foreach ($xpath->query('./w:tc', $tr) ?: [] as $cell) {
                    $row[] = $this->nodeText($xpath, $cell);
                }
                $grid[] = $row;
            }
            $tables[] = $grid;
        }
        return ['paragraphs' => $paragraphs, 'tables' => $tables];
    }

    private function writeDocx(string $bytes, array $body): string
    {
        $path = $this->temporaryFile($bytes, 'docx');
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            @unlink($path);
            throw new RuntimeException('Could not open the DOCX file.');
        }
        $xml = $zip->getFromName('word/document.xml');
        if (!is_string($xml)) {
            $zip->close();
            @unlink($path);
            throw new RuntimeException('This DOCX file has no document body.');
        }
        $dom = $this->xml($xml);
        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
        $paragraphs = is_array($body['paragraphs'] ?? null) ? $body['paragraphs'] : [];
        foreach ($xpath->query('//w:body/w:p') ?: [] as $index => $paragraph) {
            $this->replaceNodeText($dom, $xpath, $paragraph, (string)($paragraphs[$index] ?? ''));
        }
        $tables = is_array($body['tables'] ?? null) ? $body['tables'] : [];
        foreach ($xpath->query('//w:body/w:tbl') ?: [] as $tableIndex => $table) {
            $grid = is_array($tables[$tableIndex] ?? null) ? $tables[$tableIndex] : [];
            foreach ($xpath->query('./w:tr', $table) ?: [] as $rowIndex => $tr) {
                $row = is_array($grid[$rowIndex] ?? null) ? $grid[$rowIndex] : [];
                foreach ($xpath->query('./w:tc', $tr) ?: [] as $cellIndex => $cell) {
                    $this->replaceNodeText($dom, $xpath, $cell, (string)($row[$cellIndex] ?? ''));
                }
            }
        }
        $updated = $dom->saveXML();
        if (!is_string($updated) || !$zip->addFromString('word/document.xml', $updated)) {
            $zip->close();
            @unlink($path);
            throw new RuntimeException('Could not update the DOCX file.');
        }
        if (!$zip->close()) {
            @unlink($path);
            throw new RuntimeException('Could not save the DOCX file.');
        }
        $result = file_get_contents($path);
        @unlink($path);
        return is_string($result) ? $result : $bytes;
    }

    private function replaceNodeText(DOMDocument $dom, DOMXPath $xpath, DOMNode $node, string $text): void
    {
        $texts = [];
        foreach ($xpath->query('.//w:t', $node) ?: [] as $textNode) {
            $texts[] = $textNode;
        }
        if (!$texts) {
            $run = $dom->createElementNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'w:r');
            $textNode = $dom->createElementNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'w:t');
            $textNode->appendChild($dom->createTextNode($text));
            $run->appendChild($textNode);
            $node->appendChild($run);
            return;
        }
        $texts[0]->nodeValue = $text;
        foreach (array_slice($texts, 1) as $extra) {
            $extra->nodeValue = '';
        }
    }

    private function nodeText(DOMXPath $xpath, DOMNode $node): string
    {
        $out = '';
        foreach ($xpath->query('.//w:t', $node) ?: [] as $text) {
            $out .= (string)$text->nodeValue;
        }
        return $out;
    }

    private function gridRow(mixed $row): array
    {
        if (!is_array($row)) {
            return [];
        }
        return array_map(static fn($value): string => is_scalar($value) || $value === null ? (string)($value ?? '') : '', $row);
    }

    private function identifier(string $name): string
    {
        if ($name === '' || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name)) {
            throw new InvalidArgumentException('Invalid database identifier.');
        }
        return $name;
    }

    private function temporaryFile(string $bytes, string $prefix): string
    {
        $path = tempnam(sys_get_temp_dir(), $prefix . '-');
        if ($path === false || file_put_contents($path, $bytes) === false) {
            throw new RuntimeException('Could not prepare the file.');
        }
        return $path;
    }

    private function openSqlite(string $path): PDO
    {
        $db = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $db->exec('PRAGMA busy_timeout = 5000');
        return $db;
    }

    private function xml(string $source): DOMDocument
    {
        $dom = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $ok = $dom->loadXML($source, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$ok) {
            throw new RuntimeException('The DOCX document XML is invalid.');
        }
        return $dom;
    }
}
