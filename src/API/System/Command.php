<?php

declare(strict_types=1);

namespace App\API\System;

use App\Libs\Attributes\Route\Get;
use App\Libs\Config;
use App\Libs\DataUtil;
use App\Libs\Enums\Http\Status;
use App\Libs\StreamClosure;
use JsonException;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface as iResponse;
use Psr\Http\Message\ServerRequestInterface as iRequest;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

final class Command
{
    public const string URL = '%{api.prefix}/system/command';
    private const int TIMES_BEFORE_PING = 6;
    private const int PING_INTERVAL = 200000;

    private int $counter = 1;

    private bool $toBackground = false;

    public function __construct()
    {
        set_time_limit(0);
    }

    #[Get(self::URL . '[/]', name: 'system.command')]
    public function __invoke(iRequest $request): iResponse
    {
        if (null === ($json = ag($request->getQueryParams(), 'json'))) {
            return api_error('No command was given.', Status::HTTP_BAD_REQUEST);
        }

        try {
            $json = json_decode(base64_decode(rawurldecode($json)), true, flags: JSON_THROW_ON_ERROR);
            $data = DataUtil::fromArray($json);
            if (null === ($command = $data->get('command'))) {
                return api_error('No command was given.', Status::HTTP_BAD_REQUEST);
            }
        } catch (JsonException $e) {
            return api_error(
                r('Unable to decode json data. {error}', ['error' => $e->getMessage()]),
                Status::HTTP_BAD_REQUEST
            );
        }

        if (!is_string($command)) {
            return api_error('Command is invalid.', Status::HTTP_BAD_REQUEST);
        }

        $callable = function () use ($command, $data, $request) {
            ignore_user_abort(true);

            $path = realpath(__DIR__ . '/../../../');

            try {
                $userCommand = "{$path}/bin/console -n {$command}";
                if (true === (bool)Config::get('console.enable.all') && str_starts_with($command, '$')) {
                    $userCommand = trim(after($command, '$'));
                }
                $process = Process::fromShellCommandline(
                    command: $userCommand,
                    cwd: $path,
                    env: array_replace_recursive([
                        'LANG' => 'en_US.UTF-8',
                        'LC_ALL' => 'en_US.UTF-8',
                        'TERM' => 'xterm-256color',
                        'PWD' => $path,
                    ], $_ENV),
                    timeout: $data->get('timeout', 7200),
                );

                $process->setPty(true);

                $process->start(callback: function ($type, $data) use ($process) {
                    if (true === $this->toBackground) {
                        return;
                    }

                    echo "id: " . hrtime(true) . "\n";
                    echo "event: data\n";
                    echo "data: " . base64_encode((string)$data);
                    echo "\n\n";

                    flush();

                    $this->counter = self::TIMES_BEFORE_PING;

                    if (ob_get_length() > 0) {
                        ob_end_flush();
                    }

                    if (connection_aborted()) {
                        $this->toBackground = true;
                    }
                });

                while (false === $this->toBackground && $process->isRunning()) {
                    usleep(self::PING_INTERVAL);
                    $this->counter--;

                    if ($this->counter > 1) {
                        continue;
                    }

                    $this->counter = self::TIMES_BEFORE_PING;

                    echo "id: " . hrtime(true) . "\n";
                    echo "event: ping\n";
                    echo 'data: ' . makeDate() . "\n\n";
                    flush();

                    if (ob_get_length() > 0) {
                        ob_end_flush();
                    }

                    if (connection_aborted()) {
                        $this->toBackground = true;
                    }
                }
            } catch (ProcessTimedOutException) {
            }

            if (false === $this->toBackground && !connection_aborted()) {
                echo "id: " . hrtime(true) . "\n";
                echo "event: close\n";
                echo 'data: ' . makeDate() . "\n\n";
                flush();

                if (ob_get_length() > 0) {
                    ob_end_flush();
                }
            }
            exit;
        };

        return new Response(status: Status::HTTP_OK->value, headers: [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
            'Last-Event-Id' => time(),
        ], body: StreamClosure::create($callable));
    }
}
