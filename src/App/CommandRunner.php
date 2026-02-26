<?php

declare(strict_types=1);

namespace App;

final class CommandRunner
{
    /**
     * @return array{code:int,stdout:string,stderr:string}
     */
    public static function run(string $command): array
    {
        $descriptors = [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($command, $descriptors, $pipes);
        if (!is_resource($process)) {
            return ['code' => 1, 'stdout' => '', 'stderr' => 'Failed to start process'];
        }

        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);

        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);

        $code = proc_close($process);

        return [
            'code' => $code,
            'stdout' => $stdout === false ? '' : $stdout,
            'stderr' => $stderr === false ? '' : $stderr,
        ];
    }
}
