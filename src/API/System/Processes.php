<?php

declare(strict_types=1);

namespace App\API\System;

use App\Libs\Attributes\Route\Get;
use App\Libs\Enums\Http\Status;
use Psr\Http\Message\ResponseInterface as iResponse;
use Psr\Http\Message\ServerRequestInterface as iRequest;
use Symfony\Component\Process\Process;

final class Processes
{
    public const string URL = '%{api.prefix}/system/processes';

    #[Get(self::URL . '[/]', name: 'system.processes')]
    public function __invoke(iRequest $request): iResponse
    {
        $cmd = 'ps aux';
        $proc = Process::fromShellCommandline($cmd);
        $proc->run();

        if (!$proc->isSuccessful()) {
            return api_error('Failed to get process list.', Status::INTERNAL_SERVER_ERROR, [
                'error' => $proc->getErrorOutput()
            ]);
        }

        $output = $proc->getOutput();
        $lines = explode(PHP_EOL, trim($output));
        $headerLine = array_shift($lines);
        $headers = preg_split('/\s+/', $headerLine, 11);
        $headers = array_map(
            fn($key, $value) => str_replace('%', '', strtolower($value)),
            range(0, count($headers) - 1),
            $headers
        );
        $processes = [];

        foreach ($lines as $line) {
            if (empty(trim($line))) {
                continue;
            }

            $parts = preg_split('/\s+/', $line, 11);

            if (count($parts) < 11) {
                continue;
            }

            $process = array_combine($headers, $parts);

            if (str_ends_with($process['command'], $cmd)) {
                continue;
            }

            $processes[] = $process;
        }

        return api_response(Status::OK, ['processes' => $processes]);
    }
}
