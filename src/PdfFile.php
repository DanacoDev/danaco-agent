<?php

class PdfFile
{
    public static function readProject(SafetyInterceptor $safety, string $folder, string $path, string $question = '', ?callable $shouldStop = null): string
    {
        $file = $safety->readBytes($folder, $path, 8 * 1024 * 1024);
        if (!str_ends_with(strtolower($file['path']), '.pdf')) {
            throw new RuntimeException('Name a .pdf file.');
        }
        $text = self::extract($file['bytes']);
        if (self::thin($text)) {
            $poppler = self::popplerText($file['bytes']);
            if (!self::thin($poppler)) {
                $text = $poppler;
            }
        }
        if (!self::thin($text)) {
            return clip_text($text, 20000);
        }
        $seen = self::look($file['bytes'], $question, $shouldStop);
        if ($seen !== '') {
            if (trim($text) !== '') {
                return "Text already in the file:\n" . clip_text($text, 4000) . "\n\nRead from the page images:\n" . $seen;
            }
            return $seen;
        }
        if (trim($text) !== '') {
            return clip_text($text, 20000);
        }
        return 'No text could be read from this PDF, and the pages could not be opened as images. A scan needs poppler-utils (pdftoppm).';
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
        if (preg_match('/(?:^|\n)\s*<\s*h[1-6]\b/i', $text) || preg_match('/<\s*(?:h[1-6]|span|font|div|p)\b[^>]*style\s*=/i', $text)) {
            throw new RuntimeException('That text contains an HTML tag, so the tag would be printed. A large purple title is {size:18}{color:purple}# Scarface (1983). Bold is **word**. Italic is *word*. One purple phrase is {color:purple}word{/color}. Put {size:18} or {color:purple} at the start of a line to change that whole line.');
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
            $fill = '0.000 0.000 0.000';
            foreach ($pageLines as $line) {
                if ((int)$line['gap'] > 0) {
                    $commands[] = (int)$line['gap'] . ' TL';
                    $commands[] = '() Tj';
                    $commands[] = 'T*';
                }
                $commands[] = (int)$line['lead'] . ' TL';
                foreach ($line['parts'] as $part) {
                    $next = $part['color'] ?? '0.000 0.000 0.000';
                    if ($next !== $fill) {
                        $commands[] = $next . ' rg';
                        $fill = $next;
                    }
                    $point = (int)($part['size'] ?? $line['size']);
                    $commands[] = '/' . $part['font'] . ' ' . $point . ' Tf';
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
            $pieces = self::pieces($line);
            $plain = self::pieceText($pieces);
            if (preg_match('/^```/', trim($plain)) || preg_match('/^([-*_])\1{2,}\s*$/', trim($plain))) {
                $runs[] = self::blankRun();
                continue;
            }
            $leadSpace = strlen($plain) - strlen(ltrim($plain));
            if ($leadSpace > 0) {
                self::eatPrefix($pieces, $leadSpace);
                $plain = ltrim($plain);
            }
            $size = 10;
            $lead = 13;
            $gap = 0;
            $heading = false;
            if (preg_match('/^(#{1,3})(?:[ \t]+|(?=\S))(.*)$/', $plain, $match)) {
                $level = strlen($match[1]);
                $rawBody = (string)$match[2];
                $clean = rtrim((string)preg_replace('/[ \t]+#+\s*$/', '', $rawBody));
                self::eatPrefix($pieces, strlen($plain) - strlen($rawBody));
                self::eatSuffix($pieces, strlen($rawBody) - strlen($clean));
                $heading = true;
                $gap = 4;
                $custom = self::uniformSize($pieces);
                if ($custom !== null) {
                    $size = $custom;
                    $lead = $custom + 4;
                } elseif ($level === 1) {
                    $size = 13;
                    $lead = 17;
                } elseif ($level === 2) {
                    $size = 12;
                    $lead = 15;
                } else {
                    $size = 11;
                    $lead = 14;
                }
            } else {
                $custom = self::uniformSize($pieces);
                if ($custom !== null) {
                    $size = $custom;
                    $lead = $custom + 4;
                }
                if (preg_match('/^[*+-]\s+(.*)$/', $plain, $match)) {
                    self::eatPrefix($pieces, strlen($plain) - strlen($match[1]));
                    self::prependPiece($pieces, '• ');
                } elseif (preg_match('/^>\s?/', $plain, $match)) {
                    self::eatPrefix($pieces, strlen($match[0]));
                }
            }
            $spans = self::spansFrom($pieces, $size);
            if ($heading) {
                foreach ($spans as &$span) {
                    $span['font'] = 'F2';
                }
                unset($span);
            }
            $max = $size;
            foreach ($spans as $span) {
                if (isset($span['size'])) {
                    $max = max($max, (int)$span['size']);
                }
            }
            if ($max > $size) {
                $lead = max($lead, $max + 4);
            }
            $width = max(40, (int)floor(960 / $max));
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

    private static function pieces(string $line): array
    {
        $pieces = [];
        $color = null;
        $size = null;
        $buf = '';
        $offset = 0;
        $length = strlen($line);
        $flush = function () use (&$pieces, &$buf, &$color, &$size): void {
            if ($buf === '') {
                return;
            }
            $pieces[] = ['text' => $buf, 'color' => $color, 'size' => $size];
            $buf = '';
        };
        while ($offset < $length) {
            $next = strpos($line, '{', $offset);
            if ($next === false) {
                $buf .= substr($line, $offset);
                break;
            }
            if ($next > $offset) {
                $buf .= substr($line, $offset, $next - $offset);
                $offset = $next;
            }
            if (preg_match('/\G\{size:\s*(\d{1,2})(?:pt)?\s*\}/A', $line, $match, 0, $offset)) {
                $point = self::pointSize($match[1]);
                if ($point !== null) {
                    $flush();
                    $size = $point;
                    $offset += strlen($match[0]);
                    continue;
                }
            }
            if (preg_match('/\G\{color:\s*([^{}]+?)\s*\}/A', $line, $match, 0, $offset)) {
                $rgb = self::colorRgb($match[1]);
                if ($rgb !== null) {
                    $flush();
                    $color = $rgb;
                    $offset += strlen($match[0]);
                    continue;
                }
            }
            if (preg_match('/\G\{\/(color|size)\}/A', $line, $match, 0, $offset)) {
                $flush();
                if ($match[1] === 'color') {
                    $color = null;
                } else {
                    $size = null;
                }
                $offset += strlen($match[0]);
                continue;
            }
            $buf .= '{';
            $offset++;
        }
        $flush();
        if ($pieces === []) {
            $pieces[] = ['text' => '', 'color' => null, 'size' => null];
        }
        return $pieces;
    }

    private static function spansFrom(array $pieces, int $lineSize): array
    {
        $spans = [];
        foreach ($pieces as $piece) {
            if ($piece['text'] === '') {
                continue;
            }
            foreach (self::markdownSpans($piece['text']) as $span) {
                if ($piece['color'] !== null) {
                    $span['color'] = $piece['color'];
                }
                if ($piece['size'] !== null && $piece['size'] !== $lineSize) {
                    $span['size'] = $piece['size'];
                }
                $spans[] = $span;
            }
        }
        if ($spans === []) {
            $spans[] = ['font' => 'F1', 'text' => ''];
        }
        return $spans;
    }

    private static function markdownSpans(string $line): array
    {
        $spans = [];
        $pattern = '/`([^`]*)`|!\[([^\]]*)\]\([^)]*\)|\[([^\]]+)\]\([^)]*\)|\*\*([^*]+)\*\*|__([^_]+)__|\*([^*\n]+)\*|_([^_\n]+)_/u';
        $offset = 0;
        $length = strlen($line);
        $fonts = [1 => 'F1', 2 => 'F1', 3 => 'F1', 4 => 'F2', 5 => 'F2', 6 => 'F3', 7 => 'F3'];
        while ($offset < $length && preg_match($pattern, $line, $match, PREG_OFFSET_CAPTURE, $offset)) {
            $start = $match[0][1];
            if ($start > $offset) {
                $spans[] = ['font' => 'F1', 'text' => substr($line, $offset, $start - $offset)];
            }
            $text = '';
            $font = 'F1';
            foreach ($fonts as $group => $groupFont) {
                if (($match[$group][1] ?? -1) < 0) {
                    continue;
                }
                $text = $match[$group][0];
                $font = $groupFont;
                break;
            }
            if ($text !== '') {
                $spans[] = ['font' => $font, 'text' => $text];
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

    private static function pieceText(array $pieces): string
    {
        $text = '';
        foreach ($pieces as $piece) {
            $text .= $piece['text'];
        }
        return $text;
    }

    private static function eatPrefix(array &$pieces, int $bytes): void
    {
        $left = $bytes;
        foreach ($pieces as &$piece) {
            if ($left <= 0) {
                break;
            }
            $len = strlen($piece['text']);
            if ($len <= $left) {
                $left -= $len;
                $piece['text'] = '';
                continue;
            }
            $piece['text'] = substr($piece['text'], $left);
            $left = 0;
        }
        unset($piece);
    }

    private static function eatSuffix(array &$pieces, int $bytes): void
    {
        if ($bytes <= 0) {
            return;
        }
        for ($i = count($pieces) - 1; $i >= 0 && $bytes > 0; $i--) {
            $len = strlen($pieces[$i]['text']);
            if ($len <= $bytes) {
                $bytes -= $len;
                $pieces[$i]['text'] = '';
                continue;
            }
            $pieces[$i]['text'] = substr($pieces[$i]['text'], 0, $len - $bytes);
            $bytes = 0;
        }
    }

    private static function prependPiece(array &$pieces, string $prefix): void
    {
        foreach ($pieces as &$piece) {
            $piece['text'] = $prefix . $piece['text'];
            unset($piece);
            return;
        }
        $pieces[] = ['text' => $prefix, 'color' => null, 'size' => null];
    }

    private static function uniformSize(array $pieces): ?int
    {
        $size = null;
        $saw = false;
        foreach ($pieces as $piece) {
            if ($piece['text'] === '') {
                continue;
            }
            if (!$saw) {
                $size = $piece['size'];
                $saw = true;
                continue;
            }
            if ($piece['size'] !== $size) {
                return null;
            }
        }
        return $saw ? $size : null;
    }

    private static function pointSize(string $value): ?int
    {
        $n = (int)$value;
        if ($n < 8 || $n > 36) {
            return null;
        }
        return $n;
    }

    private static function colorRgb(string $value): ?string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/\s+/', '', $value) ?? $value;
        $named = [
            'black' => [0, 0, 0],
            'white' => [1, 1, 1],
            'red' => [0.75, 0.1, 0.1],
            'green' => [0.1, 0.45, 0.2],
            'blue' => [0.1, 0.25, 0.75],
            'purple' => [0.502, 0, 0.502],
            'violet' => [0.502, 0, 0.502],
            'orange' => [0.9, 0.4, 0],
            'yellow' => [0.8, 0.65, 0],
            'gray' => [0.4, 0.4, 0.4],
            'grey' => [0.4, 0.4, 0.4],
            'teal' => [0.06, 0.43, 0.34],
            'navy' => [0, 0.15, 0.4],
            'brown' => [0.4, 0.25, 0.1],
            'pink' => [0.85, 0.3, 0.5],
            'gold' => [0.72, 0.55, 0.05],
        ];
        if (isset($named[$value])) {
            return self::rgb($named[$value]);
        }
        if (preg_match('/^#?([0-9a-f]{3}|[0-9a-f]{6})$/', $value, $match)) {
            $hex = $match[1];
            if (strlen($hex) === 3) {
                $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
            }
            return self::rgb([
                hexdec(substr($hex, 0, 2)) / 255,
                hexdec(substr($hex, 2, 2)) / 255,
                hexdec(substr($hex, 4, 2)) / 255,
            ]);
        }
        return null;
    }

    private static function rgb(array $channels): string
    {
        return sprintf('%.3f %.3f %.3f', $channels[0], $channels[1], $channels[2]);
    }

    private static function styled(string $font, string $text, array $from): array
    {
        $part = ['font' => $font, 'text' => $text];
        if (isset($from['color'])) {
            $part['color'] = $from['color'];
        }
        if (isset($from['size'])) {
            $part['size'] = $from['size'];
        }
        return $part;
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
                        $line[] = self::styled($span['font'], substr($encoded, 0, $width), $span);
                        $used = $width;
                        $emit();
                        $encoded = substr($encoded, $width);
                    }
                    if ($encoded === '') {
                        continue;
                    }
                    $len = strlen($encoded);
                }
                $last = $line === [] ? null : $line[count($line) - 1];
                $same = $last !== null
                    && $last['font'] === $span['font']
                    && ($last['color'] ?? null) === ($span['color'] ?? null)
                    && ($last['size'] ?? null) === ($span['size'] ?? null);
                if ($same) {
                    $line[count($line) - 1]['text'] .= $encoded;
                } else {
                    $line[] = self::styled($span['font'], $encoded, $span);
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

    private static function thin(string $text): bool
    {
        $letters = preg_replace('/[^\p{L}\p{N}]+/u', '', $text) ?? '';
        return strlen($letters) < 40;
    }

    private static function popplerText(string $pdf): string
    {
        $bin = self::bin('pdftotext');
        if ($bin === '') {
            return '';
        }
        $dir = self::workDir();
        if ($dir === '') {
            return '';
        }
        $file = $dir . '/file.pdf';
        file_put_contents($file, $pdf);
        try {
            $run = Process::run([$bin, '-layout', '-enc', 'UTF-8', $file, '-'], $dir, null, 20);
        } finally {
            self::removeDir($dir);
        }
        return trim((string)($run['stdout'] ?? ''));
    }

    private static function look(string $pdf, string $question, ?callable $shouldStop): string
    {
        $images = self::pageImages($pdf);
        if ($images === []) {
            return '';
        }
        $question = trim($question);
        if ($question === '') {
            $question = 'Transcribe this document page. Keep the amounts, totals, dates, names, and what each charge is for.';
        }
        $llm = app_services()['llm'];
        $parts = [];
        $count = count($images);
        foreach ($images as $i => $bytes) {
            $ask = $count > 1 ? 'Page ' . ($i + 1) . ' of ' . $count . '. ' . $question : $question;
            $seen = $llm->seeImage($ask, $bytes, $shouldStop);
            if (str_starts_with($seen, 'The CPU failsafe') || str_starts_with($seen, 'This model cannot see') || str_starts_with($seen, 'Stopped.')) {
                return $seen;
            }
            $parts[] = $count > 1 ? 'Page ' . ($i + 1) . ":\n" . $seen : $seen;
        }
        $text = trim(implode("\n\n", $parts));
        if ($count === 3) {
            $text .= "\n\nThese are the first three pages.";
        }
        return trim($text);
    }

    private static function pageImages(string $pdf): array
    {
        $dir = self::workDir();
        if ($dir === '') {
            return [];
        }
        $file = $dir . '/file.pdf';
        file_put_contents($file, $pdf);
        try {
            $pdftoppm = self::bin('pdftoppm');
            if ($pdftoppm !== '') {
                Process::run([$pdftoppm, '-png', '-r', '120', '-f', '1', '-l', '3', '-scale-to', '1600', $file, $dir . '/page'], $dir, null, 30);
            } else {
                $cairo = self::bin('pdftocairo');
                if ($cairo !== '') {
                    Process::run([$cairo, '-png', '-r', '120', '-f', '1', '-l', '3', '-scale-to', '1600', $file, $dir . '/page'], $dir, null, 30);
                } else {
                    $gs = self::bin('gs');
                    if ($gs !== '') {
                        Process::run([$gs, '-q', '-dSAFER', '-dBATCH', '-dNOPAUSE', '-sDEVICE=png16m', '-r120', '-dFirstPage=1', '-dLastPage=3', '-sOutputFile=' . $dir . '/page-%d.png', $file], $dir, null, 30);
                    } elseif (PHP_OS_FAMILY === 'Darwin' && is_executable('/usr/bin/qlmanage')) {
                        Process::run(['/usr/bin/qlmanage', '-t', '-s', '1600', '-o', $dir, $file], $dir, null, 20);
                    }
                }
            }
            $paths = glob($dir . '/*.png') ?: [];
            sort($paths, SORT_NATURAL);
            $images = [];
            foreach (array_slice($paths, 0, 3) as $path) {
                $bytes = file_get_contents($path);
                if (is_string($bytes) && str_starts_with($bytes, "\x89PNG") && strlen($bytes) <= 4 * 1024 * 1024) {
                    $images[] = $bytes;
                }
            }
            return $images;
        } finally {
            self::removeDir($dir);
        }
    }

    private static function bin(string $name): string
    {
        if (!preg_match('/^[a-z0-9]+$/', $name)) {
            return '';
        }
        $which = Process::run(['/bin/sh', '-c', 'command -v ' . $name], null, null, 5);
        $path = trim($which['stdout']);
        if ($which['code'] === 0 && $path !== '' && !str_contains($path, "\n") && is_executable($path)) {
            return $path;
        }
        foreach (['/usr/bin/' . $name, '/usr/local/bin/' . $name, '/opt/homebrew/bin/' . $name] as $fixed) {
            if (is_executable($fixed)) {
                return $fixed;
            }
        }
        return '';
    }

    private static function workDir(): string
    {
        $dir = sys_get_temp_dir() . '/danaco-pdf-' . bin2hex(random_bytes(4));
        if (!mkdir($dir, 0700, true) && !is_dir($dir)) {
            return '';
        }
        return $dir;
    }

    private static function removeDir(string $dir): void
    {
        $temp = realpath(sys_get_temp_dir());
        $real = realpath($dir);
        if ($temp === false || $real === false || $real === $temp || !str_starts_with($real, $temp . '/')) {
            return;
        }
        foreach (scandir($real) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $full = $real . '/' . $name;
            if (is_dir($full) && !is_link($full)) {
                self::removeDir($full);
            } else {
                @unlink($full);
            }
        }
        @rmdir($real);
    }
}
