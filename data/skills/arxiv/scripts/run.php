<?php
$args = json_decode(stream_get_contents(STDIN) ?: '', true);
if (!is_array($args)) {
    $args = [];
}

$action = strtolower(trim((string)($args['action'] ?? 'search')));
if ($action === '') {
    $action = 'search';
}
if ($action === 'read') {
    arxiv_read($args);
} elseif ($action === 'bibtex') {
    arxiv_bibtex($args);
} elseif ($action === 'search') {
    arxiv_search($args);
} else {
    fwrite(STDERR, "action is search, read, or bibtex.\n");
    exit(1);
}

function arxiv_search(array $args): void
{
    $id = trim((string)($args['id'] ?? ''));
    $query = arxiv_words((string)($args['query'] ?? ''), 400);
    $author = arxiv_words((string)($args['author'] ?? ''), 200);
    $category = trim((string)($args['category'] ?? ''));
    if ($id === '' && $query === '' && $author === '' && $category === '') {
        fwrite(STDERR, "Pass query, author, category, or id.\n");
        exit(1);
    }
    if ($category !== '' && preg_match('/^[A-Za-z][A-Za-z0-9.-]*$/', $category) !== 1) {
        fwrite(STDERR, "Pass a category such as cs.AI or cs.LG.\n");
        exit(1);
    }
    $max = arxiv_int($args['max'] ?? null, 5);
    if ($max < 1) {
        $max = 1;
    }
    if ($max > 8) {
        $max = 8;
    }
    $start = arxiv_int($args['start'] ?? null, 0);
    if ($start < 0) {
        $start = 0;
    }
    if ($start > 10000) {
        $start = 10000;
    }
    $sort = arxiv_sort((string)($args['sort'] ?? ''));
    $params = ['max_results' => (string)$max, 'start' => (string)$start, 'sortBy' => $sort, 'sortOrder' => 'descending'];
    if ($id !== '') {
        $paper = arxiv_paper_id($id);
        if ($paper === '') {
            fwrite(STDERR, "Pass one id. It is 2402.03300, 2402.03300v2, or an abs or pdf address.\n");
            exit(1);
        }
        $params = ['id_list' => rawurlencode($paper), 'max_results' => (string)$max, 'start' => (string)$start];
    } else {
        $parts = [];
        if ($query !== '') {
            $parts[] = arxiv_term('all:', $query);
        }
        if ($author !== '') {
            $parts[] = arxiv_term('au:', $author);
        }
        if ($category !== '') {
            $parts[] = arxiv_term('cat:', $category);
        }
        $params['search_query'] = implode('+AND+', $parts);
    }
    $found = arxiv_feed($params);
    if ($found['entries'] === []) {
        echo "No papers matched.\n";
        exit(0);
    }
    $shown = count($found['entries']);
    echo 'Found ' . $found['total'] . ' results. Showing ' . $shown . ".\n\n";
    $n = 0;
    foreach ($found['entries'] as $entry) {
        $n++;
        echo $n . '. ' . $entry['title'] . "\n";
        if ($entry['withdrawn']) {
            echo "Withdrawn.\n";
        }
        echo 'ID: ' . $entry['id'] . "\n";
        echo 'Published: ' . $entry['published'] . ' | Updated: ' . $entry['updated'] . "\n";
        echo 'Authors: ' . arxiv_authors($entry['authors']) . "\n";
        echo 'Categories: ' . ($entry['categories'] !== [] ? implode(', ', $entry['categories']) : 'none') . "\n";
        echo 'Abstract: ' . arxiv_snippet($entry['summary'], 400) . "\n";
        echo 'Links: ' . $entry['abs'] . ' | ' . $entry['pdf'] . "\n\n";
    }
    $next = $start + $shown;
    if ($found['total'] > $next) {
        echo 'More results remain. Call search again with start ' . $next . ".\n";
    }
}

function arxiv_read(array $args): void
{
    $entry = arxiv_one($args);
    $body = '';
    $note = '';
    $html = arxiv_get('https://arxiv.org/html/' . arxiv_url_id($entry['id']), 6, null);
    if ($html['ok']) {
        $text = arxiv_html_text($html['body']);
        if (strlen($text) >= 200) {
            $body = $text;
        }
    }
    if ($body === '') {
        $pdf = arxiv_pdf_text($entry['pdf']);
        if ($pdf['text'] !== '') {
            $body = $pdf['text'];
            $note = "Read from the PDF.\n";
        } else {
            $note = $pdf['note'] !== '' ? $pdf['note'] . "\n" : "The full text was not read.\n";
        }
    }
    $offset = arxiv_int($args['offset'] ?? null, 0);
    if ($offset < 0) {
        $offset = 0;
    }
    $header = $entry['title'] . "\n\n";
    $header .= 'ID: ' . $entry['id'] . "\n";
    $header .= 'Published: ' . $entry['published'] . ' | Updated: ' . $entry['updated'] . "\n";
    $header .= 'Authors: ' . arxiv_authors($entry['authors']) . "\n";
    $header .= 'Categories: ' . ($entry['categories'] !== [] ? implode(', ', $entry['categories']) : 'none') . "\n";
    $header .= 'Links: ' . $entry['abs'] . ' | ' . $entry['pdf'] . "\n";
    if ($entry['withdrawn']) {
        $header .= "Withdrawn.\n";
    }
    if ($note !== '') {
        $header .= $note;
    }
    if ($offset <= 0) {
        $header .= "\nAbstract:\n" . $entry['summary'] . "\n";
    }
    if ($body === '') {
        echo $header;
        exit(0);
    }
    $length = strlen($body);
    if ($offset >= $length) {
        echo 'ID: ' . $entry['id'] . "\n";
        echo 'No more text at offset ' . $offset . ".\n";
        exit(0);
    }
    $header .= "\nArticle:\n";
    $room = 11000 - strlen($header) - 80;
    if ($room < 500) {
        $room = 500;
    }
    if ($room > 9000) {
        $room = 9000;
    }
    $end = $offset + $room;
    if ($end > $length) {
        $end = $length;
    } else {
        $slice = substr($body, $offset, $end - $offset);
        $cut = strrpos($slice, "\n");
        if ($cut !== false && $cut > (int)(($end - $offset) / 2)) {
            $end = $offset + $cut;
        }
    }
    echo $header;
    echo substr($body, $offset, $end - $offset);
    echo "\n";
    if ($end < $length) {
        echo "\nMore text remains. Call read again with this id and offset " . $end . ".\n";
    }
}

function arxiv_bibtex(array $args): void
{
    $entry = arxiv_one($args);
    $surname = 'arxiv';
    if ($entry['authors'] !== []) {
        $bits = preg_split('/\s+/', $entry['authors'][0]) ?: [];
        $last = (string)($bits[count($bits) - 1] ?? '');
        $clean = preg_replace('/[^A-Za-z]/', '', $last) ?? '';
        if ($clean !== '') {
            $surname = $clean;
        }
    }
    $year = substr($entry['published'], 0, 4);
    if (!preg_match('/^\d{4}$/', $year)) {
        $year = '0000';
    }
    $bare = preg_replace('/v\d+$/', '', $entry['id']) ?? $entry['id'];
    $key = $surname . $year . '_' . str_replace('.', '', $bare);
    $primary = $entry['primary'] !== '' ? $entry['primary'] : ($entry['categories'][0] ?? '');
    $authors = $entry['authors'] !== [] ? implode(' and ', $entry['authors']) : 'unknown';
    echo '@article{' . $key . ",\n";
    echo '  title     = {' . arxiv_bib($entry['title']) . "},\n";
    echo '  author    = {' . arxiv_bib($authors) . "},\n";
    echo '  year      = {' . $year . "},\n";
    echo '  eprint    = {' . arxiv_bib($entry['id']) . "},\n";
    echo "  archivePrefix = {arXiv},\n";
    echo '  primaryClass  = {' . arxiv_bib($primary) . "},\n";
    echo '  url       = {' . $entry['abs'] . "}\n";
    echo "}\n";
}

function arxiv_one(array $args): array
{
    $paper = arxiv_paper_id((string)($args['id'] ?? ''));
    if ($paper === '') {
        fwrite(STDERR, "Pass one id. It is 2402.03300, 2402.03300v2, or an abs or pdf address.\n");
        exit(1);
    }
    $found = arxiv_feed(['id_list' => rawurlencode($paper), 'max_results' => '1']);
    if ($found['entries'] === []) {
        fwrite(STDERR, "That id was not found.\n");
        exit(1);
    }
    return $found['entries'][0];
}

function arxiv_feed(array $params): array
{
    $pairs = [];
    foreach ($params as $key => $value) {
        $pairs[] = rawurlencode((string)$key) . '=' . $value;
    }
    $got = arxiv_get('https://export.arxiv.org/api/query?' . implode('&', $pairs), 6, null);
    if (!$got['ok']) {
        fwrite(STDERR, $got['error'] !== '' ? $got['error'] . "\n" : "Could not reach arXiv.\n");
        exit(1);
    }
    if (!function_exists('simplexml_load_string')) {
        fwrite(STDERR, "Could not read the arXiv response.\n");
        exit(1);
    }
    $prev = libxml_use_internal_errors(true);
    $root = simplexml_load_string($got['body']);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    if ($root === false) {
        fwrite(STDERR, "Could not read the arXiv response.\n");
        exit(1);
    }
    $total = 0;
    $open = $root->children('http://a9.com/-/spec/opensearch/1.1/');
    if (isset($open->totalResults)) {
        $total = (int)(string)$open->totalResults;
    }
    $entries = [];
    foreach ($root->children('http://www.w3.org/2005/Atom')->entry as $node) {
        $parsed = arxiv_entry($node);
        if ($parsed === null) {
            continue;
        }
        if ($parsed['title'] === 'Error') {
            $message = $parsed['summary'] !== '' ? $parsed['summary'] : 'arXiv rejected that request.';
            fwrite(STDERR, $message . "\n");
            exit(1);
        }
        $entries[] = $parsed;
    }
    if ($total < count($entries)) {
        $total = count($entries);
    }
    return ['total' => $total, 'entries' => $entries];
}

function arxiv_entry(SimpleXMLElement $node): ?array
{
    $atom = $node->children('http://www.w3.org/2005/Atom');
    $title = arxiv_space((string)$atom->title);
    $raw = trim((string)$atom->id);
    if ($title === '' && $raw === '') {
        return null;
    }
    $id = $raw;
    if (preg_match('#/abs/([^/\s]+)$#', $raw, $match)) {
        $id = $match[1];
    }
    $authors = [];
    foreach ($atom->author as $author) {
        $name = arxiv_space((string)$author->name);
        if ($name !== '') {
            $authors[] = $name;
        }
    }
    $categories = [];
    foreach ($atom->category as $category) {
        $term = arxiv_attr($category, 'term');
        if ($term !== '') {
            $categories[] = $term;
        }
    }
    $primary = '';
    $extra = $node->children('http://arxiv.org/schemas/atom');
    if (isset($extra->primary_category)) {
        $primary = arxiv_attr($extra->primary_category, 'term');
    }
    $summary = arxiv_space((string)$atom->summary);
    $published = substr(trim((string)$atom->published), 0, 10);
    $updated = substr(trim((string)$atom->updated), 0, 10);
    $safe = arxiv_url_id($id);
    return [
        'title' => $title !== '' ? $title : 'Untitled',
        'id' => $id,
        'published' => $published,
        'updated' => $updated,
        'authors' => $authors,
        'categories' => $categories,
        'primary' => $primary,
        'summary' => $summary,
        'withdrawn' => preg_match('/\b(?:withdrawn|retracted)\b/i', $summary) === 1,
        'abs' => 'https://arxiv.org/abs/' . $safe,
        'pdf' => 'https://arxiv.org/pdf/' . $safe,
    ];
}

function arxiv_pdf_text(string $url): array
{
    $bin = '';
    if (function_exists('shell_exec')) {
        $found = shell_exec('command -v pdftotext 2>/dev/null');
        $bin = is_string($found) ? trim($found) : '';
    }
    if ($bin === '' || !is_executable($bin)) {
        return ['text' => '', 'note' => 'The HTML article is not available, and pdftotext is not installed, so the full text was not read.'];
    }
    $dest = tempnam(sys_get_temp_dir(), 'danaco-arxiv-');
    if ($dest === false) {
        return ['text' => '', 'note' => 'The PDF could not be read.'];
    }
    $got = arxiv_get($url, 8, $dest);
    $text = '';
    if ($got['ok'] && is_file($dest) && filesize($dest) > 5) {
        $head = (string)file_get_contents($dest, false, null, 0, 5);
        if (str_starts_with($head, '%PDF')) {
            $cmd = escapeshellarg($bin) . ' -q -enc UTF-8 -layout ' . escapeshellarg($dest) . ' -';
            $out = shell_exec($cmd);
            $text = is_string($out) ? trim($out) : '';
        }
    }
    @unlink($dest);
    if (!$got['ok']) {
        return ['text' => '', 'note' => 'The PDF could not be read.'];
    }
    if ($text === '') {
        return ['text' => '', 'note' => 'The PDF had no text.'];
    }
    return ['text' => $text, 'note' => ''];
}

function arxiv_get(string $url, int $timeout, ?string $dest): array
{
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'body' => '', 'error' => 'Could not reach arXiv.'];
    }
    $ch = curl_init($url);
    if ($ch === false) {
        return ['ok' => false, 'body' => '', 'error' => 'Could not reach arXiv.'];
    }
    $fh = null;
    $opts = [
        CURLOPT_RETURNTRANSFER => $dest === null,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_USERAGENT => 'DanacoAgent/1.0',
    ];
    if ($dest !== null) {
        $fh = fopen($dest, 'wb');
        if ($fh === false) {
            unset($ch);
            return ['ok' => false, 'body' => '', 'error' => 'The PDF could not be read.'];
        }
        $opts[CURLOPT_FILE] = $fh;
    }
    curl_setopt_array($ch, $opts);
    $body = curl_exec($ch);
    $errno = curl_errno($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $final = (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    unset($ch);
    if (is_resource($fh)) {
        fclose($fh);
    }
    $host = strtolower((string)(parse_url($final, PHP_URL_HOST) ?? ''));
    if (!in_array($host, ['arxiv.org', 'www.arxiv.org', 'export.arxiv.org'], true)) {
        return ['ok' => false, 'body' => '', 'error' => 'Could not reach arXiv.'];
    }
    if ($errno !== 0 || ($dest === null && $body === false) || $code >= 400 || $code === 0) {
        return ['ok' => false, 'body' => '', 'error' => $code >= 400 ? 'arXiv returned HTTP ' . $code . '.' : 'Could not reach arXiv.'];
    }
    return ['ok' => true, 'body' => $dest === null ? (string)$body : '', 'error' => ''];
}

function arxiv_html_text(string $html): string
{
    $html = preg_replace('#<script\b[^>]*>.*?</script>#is', ' ', $html) ?? $html;
    $html = preg_replace('#<style\b[^>]*>.*?</style>#is', ' ', $html) ?? $html;
    if (preg_match('#<article\b[^>]*>(.*)</article>#is', $html, $match)) {
        $html = $match[1];
    } elseif (preg_match('#<main\b[^>]*>(.*)</main>#is', $html, $match)) {
        $html = $match[1];
    }
    $html = preg_replace('#<br\s*/?>#i', "\n", $html) ?? $html;
    $html = preg_replace('#</p>#i', "\n\n", $html) ?? $html;
    $html = preg_replace('#</h[1-6]>#i', "\n\n", $html) ?? $html;
    $text = strip_tags($html);
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = str_replace("\u{00A0}", ' ', $text);
    $text = preg_replace("/[ \t]+\n/", "\n", $text) ?? $text;
    $text = preg_replace("/\n[ \t]+/", "\n", $text) ?? $text;
    $text = preg_replace("/[ \t]{2,}/", ' ', $text) ?? $text;
    $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;
    return trim($text);
}

function arxiv_paper_id(string $raw): string
{
    $raw = trim($raw);
    if ($raw === '' || str_contains($raw, ',')) {
        return '';
    }
    $raw = preg_replace('/[?#].*$/', '', $raw) ?? $raw;
    $raw = preg_replace('#^https?://(?:export\.)?(?:www\.)?arxiv\.org/(?:abs|pdf|html)/#i', '', $raw) ?? $raw;
    $raw = preg_replace('#\.pdf$#i', '', $raw) ?? $raw;
    $raw = preg_replace('#^arxiv:#i', '', trim($raw, " \t/")) ?? $raw;
    if (preg_match('#^(\d{4}\.\d{4,5})(v\d+)?$#i', $raw, $match)) {
        return $match[1] . strtolower((string)($match[2] ?? ''));
    }
    if (preg_match('#^([a-z][a-z.-]*/\d{7})(v\d+)?$#i', $raw, $match)) {
        return $match[1] . strtolower((string)($match[2] ?? ''));
    }
    return '';
}

function arxiv_attr(SimpleXMLElement $node, string $name): string
{
    $attrs = $node->attributes();
    if ($attrs !== null && isset($attrs[$name])) {
        return trim((string)$attrs[$name]);
    }
    return trim((string)$node[$name]);
}

function arxiv_url_id(string $id): string
{
    return str_replace('%2F', '/', rawurlencode($id));
}

function arxiv_authors(array $authors): string
{
    if ($authors === []) {
        return 'unknown';
    }
    $shown = array_slice($authors, 0, 12);
    $text = implode(', ', $shown);
    $extra = count($authors) - count($shown);
    if ($extra > 0) {
        $text .= ', and ' . $extra . ' more';
    }
    return $text;
}

function arxiv_term(string $prefix, string $text): string
{
    return $prefix . str_replace('%20', '+', rawurlencode($text));
}

function arxiv_sort(string $raw): string
{
    $key = strtolower(trim($raw));
    $map = [
        '' => 'relevance',
        'relevance' => 'relevance',
        'date' => 'submittedDate',
        'updated' => 'lastUpdatedDate',
    ];
    if (!isset($map[$key])) {
        fwrite(STDERR, "sort is relevance, date, or updated.\n");
        exit(1);
    }
    return $map[$key];
}

function arxiv_words(string $text, int $max): string
{
    $text = arxiv_space($text);
    if (strlen($text) > $max) {
        $text = trim(substr($text, 0, $max));
    }
    return $text;
}

function arxiv_space(string $text): string
{
    $text = str_replace(["\r\n", "\r"], "\n", $text);
    $text = preg_replace('/\s+/', ' ', $text) ?? $text;
    return trim($text);
}

function arxiv_snippet(string $text, int $max): string
{
    if (strlen($text) <= $max) {
        return $text;
    }
    $cut = substr($text, 0, $max);
    $space = strrpos($cut, ' ');
    if ($space !== false && $space > (int)($max / 2)) {
        $cut = substr($cut, 0, $space);
    }
    return rtrim($cut) . '...';
}

function arxiv_bib(string $text): string
{
    return str_replace(['\\', '{', '}'], ['\\\\', '\\{', '\\}'], $text);
}

function arxiv_int(mixed $value, int $default): int
{
    if (is_int($value)) {
        return $value;
    }
    if (is_string($value) && preg_match('/^-?\d+$/', trim($value))) {
        return (int)trim($value);
    }
    return $default;
}
