<?php
/*
---
name: humanizer
description: Checklist for prose that should sound human. args text or path, optional voice or voice_path. You rewrite; this does not.
---
*/
$args = json_decode(stream_get_contents(STDIN) ?: '', true);
if (!is_array($args)) {
    $args = [];
}

function humanizer_load(string $path): string
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
    if (strlen($data) > 60000) {
        $data = substr($data, 0, 60000) . "\n…[truncated]";
    }
    return $data;
}

$draft = trim((string)($args['text'] ?? ''));
if ($draft === '' && trim((string)($args['path'] ?? '')) !== '') {
    $draft = humanizer_load((string)$args['path']);
}
$voice = trim((string)($args['voice'] ?? ''));
if ($voice === '' && trim((string)($args['voice_path'] ?? '')) !== '') {
    $voice = humanizer_load((string)$args['voice_path']);
}

echo <<<'TXT'
Humanizer. Rewrite the draft so a person could have written it. Keep the meaning and the requested tone. If a voice sample is below, copy its sentence length, word choice, openings, and punctuation instead of inventing a new voice.

You do the rewrite. This note is only the checklist.
1. Mark the tells.
2. Rewrite the weak parts. Use is, are, and has. Prefer a named detail over a claim of importance.
3. Ask what still sounds machine-written, then fix that.
4. Show the rewrite. If it came from a file, write the file and say what changed. Do not overwrite in silence.

Sound like someone with a pulse: mixed sentence lengths, a real opinion when one fits, mixed feelings when the topic has them, and "I" when it belongs. Sterile neutrality is also a tell.

Tells to cut or rewrite:
- Puffed-up importance: pivotal, testament, underscores, landscape, turning point, setting the stage, vital role.
- Notability padding: a bare list of outlets, "active social media presence".
- Fake depth tacked on with -ing: highlighting, ensuring, reflecting, fostering, showcasing.
- Ad copy: vibrant, nestled, groundbreaking, renowned, breathtaking, commitment to.
- Weasel sources: experts argue, observers, industry reports, with nobody named.
- Stock sections: Challenges and Future Prospects, Despite these challenges.
- AI words and blog clichés: delve, crucial, enhance, foster, tapestry, align, game-changer, deep dive, moving forward, let's dive in, at the end of the day.
- Copula avoidance: serves as, stands as, boasts. Use is, are, has.
- "Not only / not just" and clipped tails like "no guessing".
- Forced groups of three, and a new synonym every time you repeat something.
- "From X to Y" where X and Y are not a scale.
- Hidden actors and subjectless fragments. Say who does the thing.
- Em dashes used for rhythm, mechanical bold, bold-label lists, Title Case headings, emoji bullets, curly quotes.
- Chat leftovers: I hope this helps, Certainly, let me know, Great question, you're absolutely right.
- Cutoff disclaimers, stacked hedging, filler (in order to, it is important to note), and happy endings (the future looks bright).
- Uniform hyphens on ordinary pairs. Technical compounds can stay.
- Fake depth: the real question is, at its core, fundamentally.
- Announcing the next part, or a heading repeated by the next sentence.
- Metaphors that need a translation, poster fragments, a question you answer yourself, tic openers (So, Interestingly, Crucially), and reassurance nobody asked for.

TXT;
if ($voice !== '') {
    echo "Voice sample:\n" . $voice . "\n\n";
}
if ($draft !== '') {
    echo "Draft:\n" . $draft . "\n\n";
} else {
    echo "No draft was passed. Apply the checklist to the prose you already have.\n\n";
}
echo "Adapted from blader/humanizer (MIT), which tracks Wikipedia's \"Signs of AI writing\" (WikiProject AI Cleanup). Original author Siqi Chen, https://github.com/blader/humanizer. The Danaco port added later tells; this copy is the short form used here.\n";
