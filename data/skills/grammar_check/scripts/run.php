<?php
$args = json_decode(stream_get_contents(STDIN) ?: '', true);
if (!is_array($args)) {
    $args = [];
}

$consent = $args['consent'] ?? false;
$agreed = $consent === true || $consent === 1 || (is_string($consent) && in_array(strtolower(trim($consent)), ['1', 'true', 'yes'], true));
if (!$agreed) {
    fwrite(STDERR, "Nothing was sent. This posts one line to https://api.languagetool.org, an external server. The line can contain personal information, sensitive information, or a password. Tell the user the exact line and wait for a yes. Then call again with consent set to yes.\n");
    exit(1);
}

$text = str_replace("\r\n", "\n", (string)($args['text'] ?? ''));
$text = trim($text);
if ($text === '') {
    fwrite(STDERR, "Pass the suspect line in text.\n");
    exit(1);
}
$lines = [];
foreach (explode("\n", $text) as $line) {
    $line = trim($line);
    if ($line !== '') {
        $lines[] = $line;
    }
}
if (count($lines) > 3) {
    fwrite(STDERR, "Nothing was sent. Pass one suspect line or a few words. Do not send the article.\n");
    exit(1);
}
$text = implode(' ', $lines);
$chars = function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
if ($chars > 400) {
    fwrite(STDERR, "Nothing was sent. That is longer than one line. Send the suspect words or one line.\n");
    exit(1);
}

$language = grammar_language((string)($args['language'] ?? ''));
if ($language === '') {
    fwrite(STDERR, "Pass language, such as sv, sv-se, or en-us.\n");
    exit(1);
}

$post = http_build_query(['text' => $text, 'language' => $language], '', '&', PHP_QUERY_RFC3986);
$ch = curl_init('https://api.languagetool.org/v2/check');
if ($ch === false) {
    fwrite(STDERR, "Could not reach LanguageTool.\n");
    exit(1);
}
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $post,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT => 5,
    CURLOPT_TIMEOUT => 12,
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
]);
$raw = curl_exec($ch);
$errno = curl_errno($ch);
$code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
unset($ch);
if ($raw === false || $errno !== 0) {
    fwrite(STDERR, "Could not reach LanguageTool.\n");
    exit(1);
}
$json = json_decode((string)$raw, true);
if (!is_array($json) || $code >= 400) {
    $message = is_array($json) ? (string)($json['message'] ?? '') : '';
    fwrite(STDERR, $message !== '' ? $message . "\n" : "LanguageTool rejected the line.\n");
    exit(1);
}

echo 'Sent one line to LanguageTool (' . $language . ").\n";
$matches = $json['matches'] ?? [];
if (!is_array($matches) || $matches === []) {
    echo "No findings.\n";
    exit(0);
}
$n = 0;
foreach ($matches as $match) {
    if (!is_array($match)) {
        continue;
    }
    $n++;
    $rule = is_array($match['rule'] ?? null) ? $match['rule'] : [];
    $category = is_array($rule['category'] ?? null) ? trim((string)($rule['category']['name'] ?? '')) : '';
    $message = trim((string)($match['message'] ?? ''));
    echo $n . '. ' . ($category !== '' ? $category . ': ' : '') . $message . "\n";
    $suggestions = [];
    $replacements = is_array($match['replacements'] ?? null) ? $match['replacements'] : [];
    foreach (array_slice($replacements, 0, 5) as $item) {
        if (is_array($item) && isset($item['value']) && (string)$item['value'] !== '') {
            $suggestions[] = (string)$item['value'];
        }
    }
    if ($suggestions !== []) {
        echo 'Suggestion: ' . implode(' | ', $suggestions) . "\n";
    }
}

function grammar_language(string $raw): string
{
    $key = strtolower(trim(str_replace('_', '-', $raw)));
    $map = [
        'sv' => 'sv',
        'sv-se' => 'sv',
        'en' => 'en-US',
        'en-us' => 'en-US',
        'en-gb' => 'en-GB',
        'en-au' => 'en-AU',
        'en-ca' => 'en-CA',
        'en-nz' => 'en-NZ',
        'en-za' => 'en-ZA',
        'de' => 'de-DE',
        'de-de' => 'de-DE',
        'de-at' => 'de-AT',
        'de-ch' => 'de-CH',
        'da' => 'da-DK',
        'da-dk' => 'da-DK',
        'nb' => 'nb',
        'no' => 'nb',
        'fr' => 'fr',
        'fr-fr' => 'fr',
        'fr-ca' => 'fr-CA',
        'fr-be' => 'fr-BE',
        'fr-ch' => 'fr-CH',
        'es' => 'es',
        'es-es' => 'es',
        'nl' => 'nl',
        'nl-be' => 'nl-BE',
        'pt-pt' => 'pt-PT',
        'pt-br' => 'pt-BR',
        'it' => 'it',
        'pl' => 'pl-PL',
        'ru' => 'ru-RU',
        'uk' => 'uk-UA',
        'auto' => 'auto',
    ];
    if (isset($map[$key])) {
        return $map[$key];
    }
    $parts = explode('-', $key);
    if (count($parts) === 2 && preg_match('/^[a-z]{2,3}$/', $parts[0]) && preg_match('/^[a-z]{2}$/', $parts[1])) {
        return $parts[0] . '-' . strtoupper($parts[1]);
    }
    if (preg_match('/^[a-z]{2,3}$/', $key)) {
        return $key;
    }
    return '';
}
