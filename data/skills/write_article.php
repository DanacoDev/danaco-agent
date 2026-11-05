<?php
/*
---
name: write_article
description: Article workflow in five saved passes. args path, instructions, optional sources or source_paths, language, voice or voice_path. Follow every step before you present the file.
---
*/
$args = json_decode(stream_get_contents(STDIN) ?: '', true);
if (!is_array($args)) {
    $args = [];
}

function article_load(string $path): string
{
    $path = str_replace('\\', '/', trim($path));
    if ($path === '' || str_contains($path, "\0") || preg_match('#(^|/)\.\.(/|$)#', $path)) {
        fwrite(STDERR, "Name a file inside the project.\n");
        exit(1);
    }
    $root = realpath(getcwd() ?: '.');
    $target = ($root ?: '') . '/' . ltrim($path, '/');
    $real = realpath($target);
    if ($root === false || $real === false || !is_file($real) || !str_starts_with($real, $root . DIRECTORY_SEPARATOR)) {
        fwrite(STDERR, "File not found: {$path}\n");
        exit(1);
    }
    $data = file_get_contents($real);
    if ($data === false) {
        fwrite(STDERR, "Could not read {$path}.\n");
        exit(1);
    }
    if (strlen($data) > 4000) {
        $data = substr($data, 0, 4000) . "\n…[truncated]";
    }
    return $data;
}

$path = str_replace('\\', '/', trim((string)($args['path'] ?? '')));
$language = trim((string)($args['language'] ?? ''));
$instructions = trim((string)($args['instructions'] ?? ''));
$voice = trim((string)($args['voice'] ?? ''));
$voicePath = trim((string)($args['voice_path'] ?? ''));
if ($voice === '' && $voicePath !== '') {
    $voice = article_load($voicePath);
}

$sources = $args['sources'] ?? '';
if (is_array($sources)) {
    $chunks = [];
    foreach ($sources as $source) {
        if (is_string($source) && trim($source) !== '') {
            $chunks[] = trim($source);
        }
    }
    $sources = implode("\n\n", $chunks);
}
$sources = trim((string)$sources);

$sourcePaths = $args['source_paths'] ?? ($args['source_path'] ?? []);
if (is_string($sourcePaths)) {
    $sourcePaths = preg_split('/\s*,\s*/', $sourcePaths) ?: [];
}
if (!is_array($sourcePaths)) {
    $sourcePaths = [];
}
$loaded = [];
foreach ($sourcePaths as $sourcePath) {
    if (!is_string($sourcePath) || trim($sourcePath) === '') {
        continue;
    }
    $sourcePath = trim($sourcePath);
    $loaded[] = $sourcePath . "\n" . article_load($sourcePath);
    if (count($loaded) >= 6) {
        break;
    }
}

$swedish = (bool)preg_match('/^(sv|swe|swedish|svenska)$/i', $language);

echo "Article workflow. Do these five steps in order. Save the wiki file at the end of each step before you start the next. Do not present the article until step 5 is saved. Do not skip a step because the draft looks fine.\n\n";
if ($path !== '') {
    echo "Working file: {$path}\n";
    echo "Create it in step 2 if it does not exist. Markdown belongs in wiki/.\n\n";
} else {
    echo "No path was passed. Choose one markdown file in wiki/ and use that same path for every step.\n\n";
}
if ($language !== '') {
    echo "Language: {$language}. The whole article stays in this language. Names and quotes from the source may stay as they are. Do not leave sentences in another language.\n\n";
} else {
    echo "Language: use the language the user asked for. The whole article stays in that language.\n\n";
}
if ($swedish) {
    echo "Swedish is a weak language for this model, so the first draft will be wrong in places. On every pass, fix grammar a Swedish reader would notice. Write compounds as one word unless the source splits them. Keep en or ett with its noun. Use å, ä, and ö. Do not swap in an English word to dodge a Swedish one.\n\n";
}
if ($instructions !== '') {
    echo "Instructions:\n{$instructions}\n\n";
}

echo <<<'TXT'
1. Sources and instructions. Read the instructions and every source below. Note the facts you may use, and what you must not invent. If the task named files that are not pasted here, read those files before you draft. Do not write the article in this step.

2. Draft. Write the full article into the working file and save it. One idea per section. Say who does what. Stop. Do not polish it in the same breath.

3. Repetition and contradiction. Read the saved file from the start. Delete a section that repeats an earlier one. When two sentences disagree, keep the one the sources support and delete the other. Save the file again.

4. Humanizer. Run humanizer with path set to the working file. If a voice sample was given, pass voice or voice_path too. Rewrite from that checklist, in the same language, and save the file. Do not paste the checklist into the article.

5. Spelling. Read the saved article line by line. Fix misspellings, wrong word forms, and agreement. Do not change the meaning and do not open a new style pass. Save the file. Then present that path. The file is the result. Do not show an earlier draft.

TXT;

if ($voicePath !== '') {
    echo "Voice sample file for humanizer: {$voicePath}\n\n";
} elseif ($voice !== '') {
    echo "Voice sample for humanizer:\n{$voice}\n\n";
}
if ($sources !== '') {
    echo "Sources:\n{$sources}\n\n";
}
if ($loaded) {
    echo "Source files:\n\n" . implode("\n\n", $loaded) . "\n";
}
if ($sources === '' && !$loaded) {
    echo "No source text was passed. Use the instructions, and read any files the task named, before you draft.\n";
}
