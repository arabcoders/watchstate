<?php

declare(strict_types=1);

namespace App\API\System;

use App\Libs\Attributes\Route\Get;
use App\Libs\Attributes\Route\Post;
use App\Libs\Config;
use App\Libs\DataUtil;
use App\Libs\Enums\Http\Status;
use App\Libs\Extends\Date;
use App\Libs\Shlex;
use App\Libs\StreamedBody;
use DateInterval;
use Psr\Http\Message\ResponseInterface as iResponse;
use Psr\Http\Message\ServerRequestInterface as iRequest;
use Psr\SimpleCache\CacheInterface as iCache;
use Psr\SimpleCache\InvalidArgumentException;
use Random\RandomException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

final class Command
{
    public const string URL = '%{api.prefix}/system/command';
    private const int TIMES_BEFORE_PING = 6;
    private const int PING_INTERVAL = 200_000;

    private int $counter = 1;

    private bool $toBackground = false;

    public function __construct(
        private iCache $cache,
    ) {}

    /**
     * @throws InvalidArgumentException
     * @throws RandomException
     */
    #[Post(self::URL . '[/]', name: 'system.command.queue')]
    public function queue(iRequest $request): iResponse
    {
        $params = $request->getParsedBody();

        if (!is_array($params) || empty($params)) {
            return api_error('No json data was given.', Status::BAD_REQUEST);
        }

        if (null === ($cmd = ag($params, 'command', null))) {
            return api_error('No command was given.', Status::BAD_REQUEST);
        }

        if (!is_string($cmd)) {
            return api_error('Command is invalid.', Status::BAD_REQUEST);
        }

        $code = hash('sha256', random_bytes(12) . $cmd);

        $ttl = new DateInterval('PT5M');
        $this->cache->set($code, $params, $ttl);

        return api_response(Status::CREATED, [
            'token' => $code,
            'tracking' => r('{url}/{code}', ['url' => parse_config_value(self::URL), 'code' => $code]),
            'expires' => make_date()->add($ttl)->format(Date::ATOM),
        ]);
    }

    /**
     * @throws InvalidArgumentException
     */
    #[Get(self::URL . '/{token}[/]', name: 'system.command.stream')]
    public function stream(#[\SensitiveParameter] string $token): iResponse
    {
        if (null === ($data = $this->cache->get($token))) {
            return api_error('Token is invalid or has expired.', Status::BAD_REQUEST);
        }

        if ($this->cache->has($token)) {
            $this->cache->delete($token);
        }

        $data = DataUtil::fromArray($data);

        if (null === ($command = $data->get('command'))) {
            return api_error('No command was given.', Status::BAD_REQUEST);
        }

        if (!is_string($command)) {
            return api_error('Command is invalid.', Status::BAD_REQUEST);
        }

        $callable = function () use ($command, $data) {
            ignore_user_abort(true);

            $path = realpath(__DIR__ . '/../../../');
            $cwd = $data->get('cwd', Config::get('path', getcwd(...)));

            try {
                if (true === (bool) Config::get('console.enable.all') && str_starts_with($command, '$')) {
                    $userCommand = trim(after($command, '$'));
                    $cmd = ['sh', '-c', $userCommand];
                } else {
                    try {
                        $cmd = Shlex::split("{$path}/bin/console -n " . trim(after($command, 'console')));
                    } catch (\InvalidArgumentException $e) {
                        $this->write('error', "Failed to parse command: {$e->getMessage()}");
                        $this->write('exit_code', '1');
                        $this->write('close', (string) make_date());
                        return;
                    }
                }

                $process = new Process(
                    command: $cmd,
                    cwd: $cwd,
                    env: array_replace_recursive([
                        'LANG' => 'en_US.UTF-8',
                        'LC_ALL' => 'en_US.UTF-8',
                        'TERM' => 'xterm-256color',
                        'FORCE_COLOR' => (string) $data->get('force_color', 'true'),
                        'PWD' => $path,
                    ], $_ENV),
                    timeout: $data->get('timeout', 7200),
                );

                $this->write('cmd', (string) json_encode($cmd));
                $this->write('cwd', (string) $cwd);

                $process->setPty(true);

                $process->start(callback: function ($type, $data) use ($process) {
                    if (true === $this->toBackground) {
                        return;
                    }
                    $this->counter = self::TIMES_BEFORE_PING;

                    $this->write(
                        'data',
                        json_encode(['data' => $data, 'type' => $type], flags: JSON_INVALID_UTF8_IGNORE),
                    );

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

                    $this->write('ping', (string) make_date());

                    if (connection_aborted()) {
                        $this->toBackground = true;
                    }
                }

                $this->write('exit_code', (string) $process->getExitCode());
            } catch (ProcessTimedOutException) {
            }

            if (false === $this->toBackground && !connection_aborted()) {
                $this->write('close', (string) make_date());
            }
            exit();
        };

        set_time_limit(0);

        return api_response(Status::OK, body: StreamedBody::create($callable), headers: [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
            'Last-Event-Id' => time(),
        ]);
    }

    private function write(string $event, string $data, bool $multiLine = false): void
    {
        echo 'id: ' . hrtime(true) . "\n";
        echo "event: {$event}\n";
        if (true === $multiLine) {
            foreach (explode(PHP_EOL, $data) as $line) {
                echo "data: {$line}\n";
            }
        } else {
            echo "data: {$data}\n";
        }
        echo "\n\n";
        flush();
        if (ob_get_length() > 0) {
            ob_end_flush();
        }
    }
}
