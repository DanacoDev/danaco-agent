<?php

require __DIR__ . '/../src/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Run this from the command line.\n");
    exit(1);
}

function backup_need_tty(): void
{
    if (!stream_isatty(STDIN)) {
        fwrite(STDERR, "Run this from a terminal so the choices can be asked.\n");
        exit(1);
    }
}

function backup_ask(string $prompt): string
{
    fwrite(STDOUT, $prompt);
    $line = fgets(STDIN);
    if ($line === false) {
        fwrite(STDERR, "\n");
        exit(1);
    }
    return trim($line);
}

function backup_expand(string $path): string
{
    if ($path === '~' || str_starts_with($path, '~/')) {
        $home = getenv('HOME') ?: '';
        if ($home !== '') {
            $path = $home . substr($path, 1);
        }
    }
    return $path;
}

function backup_choice_label(string $choice): string
{
    if ($choice === 'overwrite') {
        return 'overwrite';
    }
    if ($choice === 'ignore') {
        return 'ignore';
    }
    return 'keep both';
}

function backup_fail(Throwable $e): void
{
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}

function cmd_backup(array $argv): void
{
    if (count($argv) > 3) {
        fwrite(STDERR, "Usage: danaco backup [file.zip]\n");
        exit(1);
    }
    backup_need_tty();
    $projects = Database::instance()->projects();
    if (!$projects) {
        fwrite(STDERR, "There are no projects to export.\n");
        exit(1);
    }
    echo "Projects\n";
    foreach ($projects as $i => $project) {
        echo '  ' . ($i + 1) . '. ' . $project['name'] . ' (' . $project['folder_name'] . ")\n";
    }
    $picked = [];
    while (!$picked) {
        $line = backup_ask('Export which projects? Enter numbers separated by spaces, or all: ');
        if ($line === '') {
            echo "Nothing was exported.\n";
            return;
        }
        if (strcasecmp($line, 'all') === 0) {
            foreach ($projects as $project) {
                $picked[] = (string)$project['folder_name'];
            }
            break;
        }
        $bad = false;
        $picked = [];
        foreach (preg_split('/[\s,]+/', $line) ?: [] as $part) {
            if ($part === '') {
                continue;
            }
            if (!ctype_digit($part) || (int)$part < 1 || (int)$part > count($projects)) {
                $bad = true;
                break;
            }
            $picked[] = (string)$projects[(int)$part - 1]['folder_name'];
        }
        $picked = array_values(array_unique($picked));
        if ($bad || !$picked) {
            echo "Enter numbers from the list, or all.\n";
            $picked = [];
        }
    }
    $default = getcwd() . '/danaco-backup-' . date('Ymd-Hi') . '.zip';
    if (isset($argv[2]) && $argv[2] !== '') {
        $dest = backup_expand($argv[2]);
    } else {
        $entered = backup_ask('Save backup as [' . $default . ']: ');
        $dest = $entered === '' ? $default : backup_expand($entered);
    }
    if (is_dir($dest)) {
        $dest = rtrim($dest, '/') . '/' . basename($default);
    }
    if (is_file($dest)) {
        $yes = strtolower(backup_ask('That file exists. Overwrite it? [y/N]: '));
        if ($yes !== 'y' && $yes !== 'yes') {
            echo "Nothing was exported.\n";
            return;
        }
    }
    if (!is_dir(dirname($dest))) {
        fwrite(STDERR, "That folder does not exist.\n");
        exit(1);
    }
    $archive = new ProjectBackup(Database::instance());
    try {
        $tmp = $archive->export($picked);
    } catch (Throwable $e) {
        backup_fail($e);
    }
    if (is_file($dest)) {
        @unlink($dest);
    }
    if (!@rename($tmp, $dest)) {
        if (!@copy($tmp, $dest)) {
            @unlink($tmp);
            fwrite(STDERR, "Could not save the backup.\n");
            exit(1);
        }
        @unlink($tmp);
    }
    @chmod($dest, 0600);
    $count = count($picked);
    echo 'Saved ' . $dest . ' (' . $count . ($count === 1 ? ' project' : ' projects') . ").\n";
}

function cmd_restore(array $argv): void
{
    if (count($argv) > 3) {
        fwrite(STDERR, "Usage: danaco restore [file.zip]\n");
        exit(1);
    }
    if (isset($argv[2]) && $argv[2] !== '') {
        $path = backup_expand($argv[2]);
    } else {
        backup_need_tty();
        $path = backup_expand(backup_ask('Backup file: '));
    }
    if ($path === '' || !is_file($path)) {
        fwrite(STDERR, "That backup was not found.\n");
        exit(1);
    }
    $archive = new ProjectBackup(Database::instance());
    try {
        $info = $archive->inspect($path);
    } catch (Throwable $e) {
        backup_fail($e);
    }
    echo "Projects in this backup\n";
    foreach ($info['projects'] as $project) {
        echo '  ' . $project['name'] . ' (' . $project['folder'] . ")\n";
    }
    $choices = [];
    if ($info['conflicts']) {
        backup_need_tty();
        $apply = null;
        foreach ($info['conflicts'] as $index => $conflict) {
            echo "\n" . $conflict['name'] . ' — ' . $conflict['reason'] . "\n";
            if ($apply !== null) {
                $choices[$conflict['folder']] = $apply;
                echo 'Using ' . backup_choice_label($apply) . ".\n";
                continue;
            }
            $choice = '';
            while ($choice === '') {
                echo "  o  Overwrite\n  i  Ignore\n  b  Keep both\n";
                $answer = strtolower(backup_ask('Choice [o/i/b]: '));
                $choice = match ($answer) {
                    'o', 'overwrite' => 'overwrite',
                    'i', 'ignore' => 'ignore',
                    'b', 'keep', 'both' => 'keep',
                    default => '',
                };
                if ($choice === '') {
                    echo "Enter o, i, or b.\n";
                }
            }
            $choices[$conflict['folder']] = $choice;
            if (count($info['conflicts']) - $index - 1 > 0) {
                $again = strtolower(backup_ask('Use this for the other duplicates too? [y/N]: '));
                if ($again === 'y' || $again === 'yes') {
                    $apply = $choice;
                }
            }
        }
    }
    try {
        $result = $archive->restore($path, $choices);
    } catch (Throwable $e) {
        backup_fail($e);
    }
    foreach ($result['lines'] as $line) {
        echo $line . "\n";
    }
}

$command = $argv[1] ?? '';
if ($command === 'backup') {
    cmd_backup($argv);
} elseif ($command === 'restore') {
    cmd_restore($argv);
} else {
    fwrite(STDERR, "Usage: danaco backup [file.zip]\n       danaco restore [file.zip]\n");
    exit(1);
}
