<?php

class PdfFile
{
    public static function readProject(SafetyInterceptor $safety, string $folder, string $path): string
    {
        $file = $safety->readBytes($folder, $path, 8 * 1024 * 1024);
        if (!str_ends_with(strtolower($file['path']), '.pdf')) {
            throw new RuntimeException('Name a .pdf file.');
        }
        $text = self::extract($file['bytes']);
        if ($text === '') {
            return 'No text could be read from this PDF.';
        }
        return clip_text($text, 20000);
    }

    public static function writeProject(SafetyInterceptor $safety, string $folder, string $path, string $text): string
    {
        $text = trim($text);
        if ($text === '') {
            throw new RuntimeException('PDF text is required.');
        }
        if (strlen($text) > 200000) {
            throw new RuntimeException('That PDF text is too long.');
        }
        $relative = ltrim(str_replace('\\', '/', trim($path)), '/');
        if (!preg_match('/^(?:[A-Za-z0-9][A-Za-z0-9._-]{0,40}\/)*[A-Za-z0-9][A-Za-z0-9._-]{0,80}\.pdf$/', $relative) || str_contains($relative, '..')) {
            throw new RuntimeException('Name a .pdf file inside the project.');
        }
        if (str_starts_with($relative, 'wiki/')) {
            throw new RuntimeException('PDF files do not belong in the wiki.');
        }
        $requested = $relative;
        $relative = self::nextPdfPath($relative, fn(string $path): bool => $safety->fileExists($folder, $path));
        $written = $safety->writeFile($folder, $relative, self::build($text));
        $note = 'Wrote ' . $written['path'] . '. Put that path alone on a line in your reply so the user can download it.';
        if ($relative !== $requested) {
            $note .= ' ' . $requested . ' was left unchanged. The user can compare the two and delete the old one if this version is better.';
        }
        return $note;
    }

    private static function nextPdfPath(string $relative, callable $taken): string
    {
        if (!$taken($relative)) {
            return $relative;
        }
        $slash = strrpos($relative, '/');
        $dir = $slash === false ? '' : substr($relative, 0, $slash + 1);
        $base = substr($slash === false ? $relative : substr($relative, $slash + 1), 0, -4);
        $stem = $base;
        $start = 2;
        if (preg_match('/^(.*\D)(\d+)$/', $base, $match)) {
            $stem = $match[1];
            $start = ((int)$match[2]) + 1;
        }
        for ($n = $start; $n < $start + 1000; $n++) {
            $candidate = $dir . $stem . $n . '.pdf';
            if (!preg_match('/^(?:[A-Za-z0-9][A-Za-z0-9._-]{0,40}\/)*[A-Za-z0-9][A-Za-z0-9._-]{0,80}\.pdf$/', $candidate)) {
                break;
            }
            if (!$taken($candidate)) {
                return $candidate;
            }
        }
        throw new RuntimeException('Could not find a free name for that PDF.');
    }

    public static function build(string $text): string
    {
        $runs = self::runs($text);
        if (count($runs) > 2000) {
            $runs = array_slice($runs, 0, 2000);
        }
        if ($runs === []) {
            $runs = [self::blankRun()];
        }
        $pages = self::paginate($runs);
        $regularId = 3 + count($pages) * 2;
        $boldId = $regularId + 1;
        $italicId = $boldId + 1;
        $objects = [];
        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $kids = [];
        foreach ($pages as $index => $pageLines) {
            $pageId = 3 + $index * 2;
            $contentId = $pageId + 1;
            $kids[] = $pageId . ' 0 R';
            $commands = ['BT', '50 790 Td'];
            foreach ($pageLines as $line) {
                if ((int)$line['gap'] > 0) {
                    $commands[] = (int)$line['gap'] . ' TL';
                    $commands[] = '() Tj';
                    $commands[] = 'T*';
                }
                $commands[] = (int)$line['lead'] . ' TL';
                foreach ($line['parts'] as $part) {
                    $commands[] = '/' . $part['font'] . ' ' . (int)$line['size'] . ' Tf';
                    $commands[] = '(' . self::escape($part['text']) . ') Tj';
                }
                $commands[] = 'T*';
            }
            $commands[] = 'ET';
            $stream = implode("\n", $commands);
            $objects[$contentId] = "<< /Length " . strlen($stream) . " >>\nstream\n" . $stream . "\nendstream";
            $objects[$pageId] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Contents ' . $contentId . ' 0 R /Resources << /Font << /F1 ' . $regularId . ' 0 R /F2 ' . $boldId . ' 0 R /F3 ' . $italicId . ' 0 R >> >> >>';
        }
        $objects[2] = '<< /Type /Pages /Count ' . count($pages) . ' /Kids [' . implode(' ', $kids) . '] >>';
        $objects[$regularId] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $objects[$boldId] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
        $objects[$italicId] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Oblique /Encoding /WinAnsiEncoding >>';
        ksort($objects);
        $pdf = "%PDF-1.4\n";
        $offsets = [];
        for ($id = 1; $id <= $italicId; $id++) {
            $offsets[$id] = strlen($pdf);
            $pdf .= $id . " 0 obj\n" . $objects[$id] . "\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 " . ($italicId + 1) . "\n";
        $pdf .= "0000000000 65535 f \n";
        for ($id = 1; $id <= $italicId; $id++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$id]);
        }
        $pdf .= "trailer\n<< /Size " . ($italicId + 1) . " /Root 1 0 R >>\nstartxref\n" . $xref . "\n%%EOF";
        return $pdf;
    }

    private static function runs(string $text): array
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $runs = [];
        foreach (explode("\n", $text) as $line) {
            if (preg_match('/^```/', trim($line)) || preg_match('/^([-*_])\1{2,}\s*$/', trim($line))) {
                $runs[] = self::blankRun();
                continue;
            }
            $size = 10;
            $lead = 13;
            $gap = 0;
            $heading = false;
            if (preg_match('/^(#{1,3})(?:[ \t]+|(?=\S))(.*)$/', $line, $match)) {
                $level = strlen($match[1]);
                $line = trim((string)preg_replace('/[ \t]+#+\s*$/', '', $match[2]));
                $heading = true;
                $gap = 4;
                if ($level === 1) {
                    $size = 13;
                    $lead = 17;
                } elseif ($level === 2) {
                    $size = 12;
                    $lead = 15;
                } else {
                    $size = 11;
                    $lead = 14;
                }
            } elseif (preg_match('/^[*+-]\s+(.*)$/', $line, $match)) {
                $line = '• ' . $match[1];
            }
            $line = (string)preg_replace('/^>\s?/', '', $line);
            $spans = self::spans($line);
            if ($heading) {
                $plain = '';
                foreach ($spans as $span) {
                    $plain .= $span['text'];
                }
                $spans = [['font' => 'F2', 'text' => $plain]];
            }
            $width = max(40, (int)floor(960 / $size));
            $wrapped = self::wrapSpans($spans, $width);
            foreach ($wrapped as $index => $parts) {
                $runs[] = [
                    'size' => $size,
                    'lead' => $lead,
                    'gap' => $index === 0 ? $gap : 0,
                    'parts' => $parts,
                ];
            }
        }
        return $runs;
    }

    private static function spans(string $line): array
    {
        $spans = [];
        $pattern = '/`([^`]*)`|!\[([^\]]*)\]\([^)]*\)|\[([^\]]+)\]\([^)]*\)|\*\*([^*]+)\*\*|__([^_]+)__|\*([^*\n]+)\*|_([^_\n]+)_/u';
        $offset = 0;
        $length = strlen($line);
        while ($offset < $length && preg_match($pattern, $line, $match, PREG_OFFSET_CAPTURE, $offset)) {
            $start = $match[0][1];
            if ($start > $offset) {
                $spans[] = ['font' => 'F1', 'text' => substr($line, $offset, $start - $offset)];
            }
            $text = '';
            $italic = false;
            foreach ([1 => false, 2 => false, 3 => false, 4 => true, 5 => true, 6 => true, 7 => true] as $group => $isItalic) {
                if (($match[$group][1] ?? -1) < 0) {
                    continue;
                }
                $text = $match[$group][0];
                $italic = $isItalic;
                break;
            }
            if ($text !== '') {
                $spans[] = ['font' => $italic ? 'F3' : 'F1', 'text' => $text];
            }
            $offset = $start + strlen($match[0][0]);
        }
        if ($offset < $length) {
            $spans[] = ['font' => 'F1', 'text' => substr($line, $offset)];
        }
        if ($spans === []) {
            $spans[] = ['font' => 'F1', 'text' => ''];
        }
        return $spans;
    }

    private static function wrapSpans(array $spans, int $width): array
    {
        $lines = [];
        $line = [];
        $used = 0;
        $emit = function () use (&$lines, &$line, &$used): void {
            if ($line !== []) {
                $last = count($line) - 1;
                $line[$last]['text'] = rtrim($line[$last]['text']);
                if ($line[$last]['text'] === '') {
                    array_pop($line);
                }
            }
            if ($line !== []) {
                $lines[] = $line;
            }
            $line = [];
            $used = 0;
        };
        foreach ($spans as $span) {
            $tokens = preg_split('/( +)/', $span['text'], -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
            foreach ($tokens as $token) {
                if ($token === '') {
                    continue;
                }
                $encoded = self::winAnsi($token);
                $len = strlen($encoded);
                $word = trim($token) !== '';
                if ($word && $used > 0 && $used + $len > $width) {
                    $emit();
                }
                if (!$word && $used === 0) {
                    continue;
                }
                if ($word && $len > $width) {
                    while (strlen($encoded) > $width) {
                        if ($used > 0) {
                            $emit();
                        }
                        $line[] = ['font' => $span['font'], 'text' => substr($encoded, 0, $width)];
                        $used = $width;
                        $emit();
                        $encoded = substr($encoded, $width);
                    }
                    if ($encoded === '') {
                        continue;
                    }
                    $len = strlen($encoded);
                }
                if ($line !== [] && $line[count($line) - 1]['font'] === $span['font']) {
                    $line[count($line) - 1]['text'] .= $encoded;
                } else {
                    $line[] = ['font' => $span['font'], 'text' => $encoded];
                }
                $used += $len;
            }
        }
        $emit();
        return $lines === [] ? [[['font' => 'F1', 'text' => '']]] : $lines;
    }

    private static function blankRun(): array
    {
        return ['size' => 10, 'lead' => 13, 'gap' => 0, 'parts' => [['font' => 'F1', 'text' => '']]];
    }

    private static function paginate(array $runs): array
    {
        $pages = [];
        $page = [];
        $y = 790;
        $previousBlank = true;
        foreach ($runs as $run) {
            $gap = ($previousBlank || $page === []) ? 0 : (int)$run['gap'];
            $need = (int)$run['lead'] + $gap;
            if ($page !== [] && ($y - $need) < 52) {
                $pages[] = $page;
                $page = [];
                $y = 790;
                $gap = 0;
                $need = (int)$run['lead'];
            }
            $run['gap'] = $gap;
            $page[] = $run;
            $y -= $need;
            $previousBlank = self::lineText($run) === '';
        }
        if ($page !== []) {
            $pages[] = $page;
        }
        return $pages === [] ? [[self::blankRun()]] : $pages;
    }

    private static function lineText(array $run): string
    {
        $text = '';
        foreach ($run['parts'] as $part) {
            $text .= $part['text'];
        }
        return $text;
    }

    private static function winAnsi(string $text): string
    {
        $encoded = @iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $text);
        if (is_string($encoded)) {
            return $encoded;
        }
        return preg_replace('/[^\x09\x20-\x7E]/', '?', $text) ?? '';
    }

    public static function extract(string $pdf): string
    {
        if (!str_starts_with($pdf, '%PDF')) {
            throw new RuntimeException('That file is not a PDF.');
        }
        $lines = [];
        if (preg_match_all('/<<(.*?)>>\s*stream\r?\n(.*?)\nendstream/s', $pdf, $parts, PREG_SET_ORDER)) {
            foreach ($parts as $part) {
                $data = $part[2];
                if (str_contains($part[1], 'FlateDecode')) {
                    $decoded = @gzuncompress($data);
                    if ($decoded === false) {
                        $decoded = @gzinflate($data);
                    }
                    if (!is_string($decoded)) {
                        continue;
                    }
                    $data = $decoded;
                }
                if (!str_contains($data, 'Tj') && !str_contains($data, 'TJ')) {
                    continue;
                }
                $lines = array_merge($lines, self::stringsFrom($data));
            }
        }
        return trim(implode("\n", $lines));
    }

    private static function stringsFrom(string $data): array
    {
        $lines = [];
        if (str_contains($data, 'T*') && preg_match_all('/\(((?:\\\\.|[^\\\\)])*)\)\s*Tj|T\*/', $data, $ops, PREG_SET_ORDER)) {
            $current = '';
            foreach ($ops as $op) {
                if (rtrim($op[0]) === 'T*') {
                    $lines[] = $current;
                    $current = '';
                    continue;
                }
                $current .= self::decode($op[1] ?? '');
            }
            if ($current !== '') {
                $lines[] = $current;
            }
            return $lines;
        }
        if (!preg_match_all('/\(((?:\\\\.|[^\\\\)])*)\)\s*Tj|\[(.*?)\]\s*TJ/s', $data, $ops, PREG_SET_ORDER)) {
            return $lines;
        }
        foreach ($ops as $op) {
            $token = rtrim($op[0]);
            if (str_ends_with($token, 'Tj')) {
                $lines[] = self::decode($op[1]);
                continue;
            }
            $chunk = '';
            if (($op[2] ?? '') !== '' && preg_match_all('/\(((?:\\\\.|[^\\\\)])*)\)/', $op[2], $bits)) {
                foreach ($bits[1] as $bit) {
                    $chunk .= self::decode($bit);
                }
            }
            if ($chunk !== '') {
                $lines[] = $chunk;
            }
        }
        return $lines;
    }

    private static function escape(string $line): string
    {
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $line);
    }

    private static function decode(string $raw): string
    {
        $text = preg_replace_callback('/\\\\([nrt\\\\()])/', static function (array $match): string {
            return match ($match[1]) {
                'n' => "\n",
                'r' => '',
                't' => "\t",
                '\\', '(', ')' => $match[1],
                default => $match[1],
            };
        }, $raw) ?? $raw;
        $utf = @iconv('UTF-8', 'UTF-8//IGNORE', $text);
        if ($utf === $text) {
            return $text;
        }
        $converted = @iconv('Windows-1252', 'UTF-8//IGNORE', $text);
        return is_string($converted) ? $converted : $text;
    }
}
