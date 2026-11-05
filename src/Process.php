<?php

class Process
{
    public static function run(array $command, ?string $cwd = null, ?string $stdin = null, int $timeout = 20, ?array $env = null): array
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open($command, $descriptors, $pipes, $cwd ?: null, $env, ['bypass_shell' => true]);
        if (!is_resource($proc)) {
            return ['code' => 1, 'stdout' => '', 'stderr' => 'Could not start process.'];
        }
        if ($stdin !== null) {
            fwrite($pipes[0], $stdin);
        }
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $stdout = '';
        $stderr = '';
        $start = microtime(true);
        $timedOut = false;
        while (true) {
            $stdout .= (string)stream_get_contents($pipes[1]);
            $stderr .= (string)stream_get_contents($pipes[2]);
            $status = proc_get_status($proc);
            if (!$status['running']) {
                break;
            }
            if ((microtime(true) - $start) > $timeout) {
                proc_terminate($proc);
                $timedOut = true;
                $stderr .= ($stderr === '' ? '' : "\n") . 'Timed out.';
                break;
            }
            usleep(20000);
        }
        $stdout .= (string)stream_get_contents($pipes[1]);
        $stderr .= (string)stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);
        if ($timedOut) {
            $code = 124;
        }
        return ['code' => $code, 'stdout' => $stdout, 'stderr' => $stderr];
    }

    public static function commandExists(string $name): bool
    {
        if (!preg_match('/^[a-zA-Z0-9._+-]+$/', $name)) {
            return false;
        }
        $result = self::run(['/bin/sh', '-c', 'command -v ' . $name], null, null, 5);
        return $result['code'] === 0 && trim($result['stdout']) !== '';
    }
}
