<?php
/*
---
name: make_graphic
description: Rules for a simple SVG, icon, infographic, or PNG. args kind, subject, path, optional size and notes. You draw the file. Use for an explanation or a website.
---
*/
$args = json_decode(stream_get_contents(STDIN) ?: '', true);
if (!is_array($args)) {
    $args = [];
}

$kind = strtolower(trim((string)($args['kind'] ?? '')));
if (in_array($kind, ['icon', 'icons'], true)) {
    $kind = 'icon';
} elseif (in_array($kind, ['infographic', 'infographics'], true)) {
    $kind = 'infographic';
} elseif (in_array($kind, ['raster', 'png', 'bitmap'], true)) {
    $kind = 'raster';
} else {
    $kind = 'diagram';
}
$subject = trim((string)($args['subject'] ?? ''));
$path = str_replace('\\', '/', trim((string)($args['path'] ?? '')));
$size = trim((string)($args['size'] ?? ''));
$notes = trim((string)($args['notes'] ?? ''));

echo "Make a graphic. You draw it and save it with write_file. This note is the standard. Do not paste the markup into the chat.\n\n";
echo "Kind: {$kind}.\n";
if ($subject !== '') {
    echo "Subject: {$subject}\n";
} else {
    echo "No subject was passed. Use the thing the user asked to see.\n";
}
if ($path !== '') {
    echo "Path: {$path}\n";
    echo "Write that file. If the path ends in .png, write the svg beside it with the same name, then make the png from that svg.\n";
} elseif ($kind === 'icon') {
    echo "No path was passed. Look at the project first. Save one icon as assets/<name>.svg, or inline it if the page already inlines icons.\n";
} else {
    echo "No path was passed. Look at the project first. Save assets/<short-name>.svg unless the site already keeps images somewhere else.\n";
}
if ($size !== '') {
    echo "Size: {$size}.\n";
}
if ($notes !== '') {
    echo "Notes: {$notes}\n";
}
echo "\n";

echo <<<'TXT'
1. Look at the project so the file lands where this site or note already keeps images. Match an existing icon style if the page has one.
2. Draw one file. An explanation gets one picture that answers one question. A page gets the icon or graphic it asked for, not a set.
3. Save it. Read the file back, check the list below, and fix it before you finish.
4. Cite the path alone on a line. For a raster, cite the png. Keep the svg next to it.

Kind:
- diagram: a flowchart or a simple object, for an explanation. Top to bottom, or left to right for a pipeline. Six boxes at most.
- icon: one metaphor for a website control. viewBox "0 0 24 24". Stroke currentColor, width 1.5, round caps and joins, fill none. No text. Keep the shape off the edge so it still reads at 16px.
- infographic: one claim, then three to six equal parts. Each part is one number or one word, plus a short line. Use only figures you were given.
- raster: draw the svg first, then a png. Try rsvg-convert, then magick, then qlmanage -t. Do not install a converter. If none of those runs, leave the svg and say so.

Draw:
- Flat. No gradient, shadow, filter, blur, or glow. No external image, font, or icon set.
- Two or three colors. The same kind of thing stays the same color. Ink #2c2c2a, paper #f7f6f3, blue #185fa5, teal #0f6e56, coral #993c1d. Use red #a32d2d only for a real warning.
- System font only: system-ui, sans-serif. Sentence case. 14px for a label, 12px for a secondary line.
- viewBox set, width 100%. A diagram is about 680 wide. Pad 40 inside the viewBox. Boxes use rx 8, a 48 gap, and 16 of padding. A connector has fill none and does not cross a box.
- The box is wider than its text. Count about 8px per character at 14px, then add the padding.
- An icon used in a button stays currentColor so the page can color it. Do not add an icon font or an icon CDN for one icon.
- Do not invent a number, a name, or a photograph. If the page needs a real photo, say so and stop.

Check:
- Every label sits inside the viewBox and inside its box.
- Arrows sit behind the boxes.
- An icon has no text and uses currentColor.
- The file has no script and no remote URL.

TXT;
echo "Diagram rules are a short form of the Hermes concept-diagrams skill (MIT), by v1k22, https://github.com/NousResearch/hermes-agent/blob/main/optional-skills/creative/concept-diagrams/SKILL.md. Icons, infographics, and the png step are the Danaco part.\n";
