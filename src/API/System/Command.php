<?php

declare(strict_types=1);

namespace App\API\System;

use App\Libs\Attributes\Route\Delete;
use App\Libs\Attributes\Route\Get;
use App\Libs\Attributes\Route\Post;
use App\Libs\Config;
use App\Libs\DataUtil;
use App\Libs\Enums\Http\Status;
use App\Libs\Extends\Date;
use App\Libs\Middlewares\SignatureMiddleware;
use App\Libs\Shlex;
use App\Libs\StreamedBody;
use DateInterval;
use DirectoryIterator;
use Psr\Http\Message\ResponseInterface as iResponse;
use Psr\Http\Message\ServerRequestInterface as iRequest;
use Random\RandomException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use Throwable;

final class Command
{
    public const string URL = '%{api.prefix}/system/command';

    private const int TIMES_BEFORE_PING = 6;
    private const int PING_INTERVAL = 200_000;
    private const int COMPLETED_SESSION_RETENTION_SECONDS = 86_400;

    private const string STATUS_QUEUED = 'queued';
    private const string STATUS_RUNNING = 'running';
    private const string STATUS_COMPLETED = 'completed';

    private const string SESSIONS_DIR = 'console';
    private const string REQUEST_FILE = 'request.json';
    private const string STATE_FILE = 'state.json';
    private const string STATE_LOCK_FILE = 'state.lock';
    private const string STREAM_FILE = 'stream.log';
    private const string WRITER_LOCK_FILE = 'writer.lock';
    private const string CANCEL_FILE = 'cancel.flag';

    /**
     * @throws RandomException
     */
    #[Post(self::URL . '[/]', middleware: [SignatureMiddleware::class], name: 'system.command.queue')]
    public function queue(iRequest $request): iResponse
    {
        $params = DataUtil::fromRequest($request);

        if (empty($params->getAll())) {
            return api_error('No json data was given.', Status::BAD_REQUEST);
        }

        if (null === ($cmd = $params->get('command'))) {
            return api_error('No command was given.', Status::BAD_REQUEST);
        }

        if (!is_string($cmd)) {
            return api_error('Command is invalid.', Status::BAD_REQUEST);
        }

        $ttl = new DateInterval('PT5M');
        $code = hash('sha256', random_bytes(12) . $cmd);
        $expires = make_date()->add($ttl);

        try {
            $this->createSession($code, $params->getAll(), $expires->format(Date::ATOM));
        } catch (Throwable $e) {
            return api_error($e->getMessage(), Status::INTERNAL_SERVER_ERROR);
        }

        return api_response(Status::CREATED, [
            'token' => $code,
            'tracking' => r('{url}/{code}', ['url' => parse_config_value(self::URL), 'code' => $code]),
            'expires' => $expires->format(Date::ATOM),
        ]);
    }

    #[Get(self::URL . '/{token}[/]', name: 'system.command.stream')]
    public function stream(iRequest $request, #[\SensitiveParameter] string $token): iResponse
    {
        $sessionPath = $this->getSessionPath($token);
        $state = $this->readState($sessionPath);

        if (null === $state) {
            return api_error('Token is invalid or has expired.', Status::NOT_FOUND);
        }

        if ($this->isCompletedAndExpired($state)) {
            return api_error('Token is invalid or has expired.', Status::NOT_FOUND);
        }

        if ($this->isQueuedAndExpired($state)) {
            return api_error('Token is invalid or has expired.', Status::NOT_FOUND);
        }

        $since = $this->getReplayCursor($request);

        $callable = function () use ($sessionPath, $since) {
            ignore_user_abort(true);
            set_time_limit(0);

            $writerLock = null;

            if (null === ($state = $this->attachSession($sessionPath))) {
                return '';
            }

            $connectionId = $this->currentConnectionId($state);

            try {
                if (self::STATUS_QUEUED === ag($state, 'status') && null !== ($writerLock = $this->acquireWriterLock($sessionPath))) {
                    $this->runWriter($sessionPath, $connectionId);
                    return '';
                }

                $this->runReader($sessionPath, $since, $connectionId);
                return '';
            } finally {
                if (is_resource($writerLock)) {
                    flock($writerLock, LOCK_UN);
                    fclose($writerLock);
                }

                $this->detachSession($sessionPath);
            }
        };

        return api_response(Status::OK, body: StreamedBody::create($callable), headers: [
            'Content-Type' => 'text/event-stream; charset=UTF-8',
            'Cache-Control' => 'no-cache',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
            'Content-Encoding' => 'none',
        ]);
    }

    #[Delete(self::URL . '/{token}[/]', name: 'system.command.cancel')]
    public function cancel(#[\SensitiveParameter] string $token): iResponse
    {
        $sessionPath = $this->getSessionPath($token);
        $state = $this->readState($sessionPath);

        if (null === $state) {
            return api_error('Token is invalid or has expired.', Status::NOT_FOUND);
        }

        if ($this->isCompletedAndExpired($state)) {
            return api_error('Token is invalid or has expired.', Status::NOT_FOUND);
        }

        if ($this->isQueuedAndExpired($state)) {
            return api_error('Token is invalid or has expired.', Status::NOT_FOUND);
        }

        if (self::STATUS_QUEUED === ag($state, 'status')) {
            $this->cleanupSession($sessionPath);

            return api_response(Status::ACCEPTED, [
                'message' => 'Command cancellation requested.',
            ]);
        }

        if ($this->isCompleted($state)) {
            return api_response(Status::ACCEPTED, [
                'message' => 'Command has already completed.',
            ]);
        }

        $this->requestCancel($sessionPath);

        return api_response(Status::ACCEPTED, [
            'message' => 'Command cancellation requested.',
        ]);
    }

    #[Get(self::URL . '[/]', name: 'system.command.list')]
    public function list(): iResponse
    {
        return api_response(Status::OK, [
            'items' => $this->getRecentSessions(),
        ]);
    }

    private function runWriter(string $sessionPath, int $connectionId): void
    {
        $params = $this->readRequest($sessionPath);
        $command = ag($params, 'command');
        $pty = false !== ag($params, 'pty', true);

        if (!is_array($params) || !is_string($command) || null === trim($command)) {
            $this->failSession($sessionPath, 'No command was given.');
            return;
        }

        $path = realpath(__DIR__ . '/../../../');
        $cwd = ag($params, 'cwd', Config::get('path', getcwd(...)));

        if (!is_string($cwd) || '' === trim($cwd)) {
            $cwd = Config::get('path', getcwd(...));
        }

        try {
            if (true === (bool) Config::get('console.enable.all') && str_starts_with($command, '$')) {
                $userCommand = trim(after($command, '$'));
                $cmd = ['sh', '-c', $userCommand];
            } else {
                $cmd = Shlex::split("{$path}/bin/console -n " . trim(after($command, 'console')));
            }
        } catch (\InvalidArgumentException $e) {
            $this->failSession($sessionPath, "Failed to parse command: {$e->getMessage()}");
            return;
        }

        $this->markSessionRunning($sessionPath, $cwd);

        $counter = self::TIMES_BEFORE_PING;
        $clientConnected = !connection_aborted();
        $isActiveConnection = fn(): bool => $this->isActiveConnection($sessionPath, $connectionId);

        $this->recordEvent($sessionPath, 'cmd', (string) json_encode($cmd), $clientConnected && $isActiveConnection());
        $this->recordEvent($sessionPath, 'cwd', $cwd, $clientConnected && $isActiveConnection());

        $process = new Process(
            command: $cmd,
            cwd: $cwd,
            env: $this->getCommandEnv($params, $pty, $cwd),
            timeout: ag($params, 'timeout', 7200),
        );

        try {
            if (true === $pty) {
                $process->setPty(true);
            }

            $process->start(function ($type, $data) use ($sessionPath, $connectionId, &$counter, &$clientConnected): void {
                $counter = self::TIMES_BEFORE_PING;

                $payload = json_encode(['data' => $data, 'type' => $type], flags: JSON_INVALID_UTF8_IGNORE);
                $this->recordEvent(
                    $sessionPath,
                    'data',
                    (string) $payload,
                    $clientConnected && $this->isActiveConnection($sessionPath, $connectionId),
                );

                if ($clientConnected && connection_aborted()) {
                    $clientConnected = false;
                }
            });

            while ($process->isRunning()) {
                if (!$isActiveConnection()) {
                    $clientConnected = false;
                }

                if ($this->isCancelRequested($sessionPath)) {
                    $process->stop(1, 9);
                }

                usleep(self::PING_INTERVAL);
                $counter--;

                if ($counter <= 1) {
                    $counter = self::TIMES_BEFORE_PING;
                    $this->touchSession($sessionPath);

                    if ($clientConnected && $isActiveConnection()) {
                        $this->emitPing();

                        if (connection_aborted()) {
                            $clientConnected = false;
                        }
                    }
                }
            }

            $exitCode = $process->getExitCode() ?? 1;
            $this->recordEvent($sessionPath, 'exit_code', (string) $exitCode, $clientConnected && $isActiveConnection());
            $this->recordEvent($sessionPath, 'close', (string) make_date(), $clientConnected && $isActiveConnection());
            $this->markSessionCompleted($sessionPath, $exitCode);
        } catch (ProcessTimedOutException $e) {
            $this->failSession($sessionPath, $e->getMessage(), 124, $clientConnected && $isActiveConnection());
        } catch (Throwable $e) {
            $this->failSession($sessionPath, $e->getMessage(), 1, $clientConnected && $isActiveConnection());
        }
    }

    private function runReader(string $sessionPath, int $since, int $connectionId): void
    {
        $offset = 0;
        $counter = self::TIMES_BEFORE_PING;

        while (!connection_aborted()) {
            if (!$this->isActiveConnection($sessionPath, $connectionId)) {
                return;
            }

            [$since, $offset] = $this->replayTranscript($sessionPath, $since, $offset);

            $state = $this->readState($sessionPath);
            if (null === $state) {
                return;
            }

            if ($this->isCompleted($state)) {
                $this->replayTranscript($sessionPath, $since, $offset);
                return;
            }

            if (!$this->isActiveConnection($sessionPath, $connectionId)) {
                return;
            }

            usleep(self::PING_INTERVAL);
            $counter--;

            if ($counter > 1) {
                continue;
            }

            $counter = self::TIMES_BEFORE_PING;
            $this->emitPing();
        }
    }

    private function failSession(
        string $sessionPath,
        string $message,
        int $exitCode = 1,
        bool $sendToClient = true,
    ): void {
        $payload = json_encode([
            'data' => r("ERROR: {message}\n", ['message' => trim($message)]),
            'type' => 'err',
        ], flags: JSON_INVALID_UTF8_IGNORE);

        $this->recordEvent($sessionPath, 'data', (string) $payload, $sendToClient);
        $this->recordEvent($sessionPath, 'exit_code', (string) $exitCode, $sendToClient);
        $this->recordEvent($sessionPath, 'close', (string) make_date(), $sendToClient);
        $this->markSessionCompleted($sessionPath, $exitCode);
    }

    private function createSession(#[\SensitiveParameter] string $token, array $params, string $expiresAt): void
    {
        $root = $this->getSessionsRoot();
        if (false === is_dir($root)) {
            throw new \RuntimeException(r("The path '{path}' is not a directory.", ['path' => $root]));
        }

        if (false === is_writable($root)) {
            throw new \RuntimeException(r("Unable to write to '{path}' directory. Check user permissions and/or user mapping.", [
                'path' => $root,
            ]));
        }

        if (false === is_readable($root)) {
            throw new \RuntimeException(r("Unable to read data from '{path}' directory. Check user permissions and/or user mapping.", [
                'path' => $root,
            ]));
        }

        $sessionPath = $this->getSessionPath($token);
        if (false === @mkdir($sessionPath, 0o755, true) && false === is_dir($sessionPath)) {
            throw new \RuntimeException("Unable to create console session '{$token}'.");
        }

        $state = [
            'status' => self::STATUS_QUEUED,
            'command' => (string) ag($params, 'command', ''),
            'cwd' => null,
            'created_at' => make_date()->format(Date::ATOM),
            'expires_at' => $expiresAt,
            'updated_at' => null,
            'started_at' => null,
            'finished_at' => null,
            'exit_code' => null,
            'last_sequence' => 0,
            'connection_seq' => 0,
            'active_connection' => 0,
            'connections' => 0,
        ];

        $this->writeJsonFile($sessionPath . '/' . self::REQUEST_FILE, $params);
        $this->writeJsonFile($sessionPath . '/' . self::STATE_FILE, $state);

        if (false === @touch($sessionPath . '/' . self::STREAM_FILE)) {
            throw new \RuntimeException("Unable to create console session transcript '{$token}'.");
        }
    }

    private function attachSession(string $sessionPath): ?array
    {
        return $this->mutateState($sessionPath, function (array $state): ?array {
            if ($this->isQueuedAndExpired($state)) {
                return null;
            }

            if ($this->isCompletedAndExpired($state)) {
                return null;
            }

            $nextConnection = (int) ag($state, 'connection_seq', 0) + 1;
            $state['connection_seq'] = $nextConnection;
            $state['active_connection'] = $nextConnection;
            $state['connections'] = max(0, (int) ag($state, 'connections', 0)) + 1;
            $state['updated_at'] = make_date()->format(Date::ATOM);
            return $state;
        });
    }

    private function detachSession(string $sessionPath): void
    {
        $this->mutateState($sessionPath, static function (array $state): array {
            $state['connections'] = max(0, (int) ag($state, 'connections', 0) - 1);
            $state['updated_at'] = make_date()->format(Date::ATOM);
            return $state;
        });
    }

    private function currentConnectionId(array $state): int
    {
        return (int) ag($state, 'connection_seq', 0);
    }

    private function isActiveConnection(string $sessionPath, int $connectionId): bool
    {
        $state = $this->readState($sessionPath);
        if (null === $state) {
            return false;
        }

        return $connectionId === (int) ag($state, 'active_connection', 0);
    }

    private function markSessionRunning(string $sessionPath, string $cwd): void
    {
        $this->mutateState($sessionPath, static function (array $state) use ($cwd): array {
            $now = make_date()->format(Date::ATOM);
            $state['status'] = self::STATUS_RUNNING;
            $state['cwd'] = $cwd;
            $state['started_at'] = $now;
            $state['updated_at'] = $now;
            return $state;
        });
    }

    private function markSessionCompleted(string $sessionPath, int $exitCode): void
    {
        $this->mutateState($sessionPath, static function (array $state) use ($exitCode): array {
            $now = make_date()->format(Date::ATOM);
            $state['status'] = self::STATUS_COMPLETED;
            $state['exit_code'] = $exitCode;
            $state['finished_at'] = $now;
            $state['updated_at'] = $now;
            return $state;
        });
    }

    private function touchSession(string $sessionPath): void
    {
        $this->mutateState($sessionPath, function (array $state): array {
            if ($this->isCompleted($state)) {
                return $state;
            }

            $state['updated_at'] = make_date()->format(Date::ATOM);
            return $state;
        });
    }

    private function recordEvent(string $sessionPath, string $event, string $data, bool $sendToClient = true): int
    {
        $state = $this->mutateState($sessionPath, static function (array $state): array {
            $state['last_sequence'] = (int) ag($state, 'last_sequence', 0) + 1;
            $state['updated_at'] = make_date()->format(Date::ATOM);
            return $state;
        });

        if (null === $state) {
            return 0;
        }

        $sequence = (int) ag($state, 'last_sequence', 0);
        $payload = json_encode([
            'id' => $sequence,
            'event' => $event,
            'data' => $data,
        ], flags: JSON_INVALID_UTF8_IGNORE);

        if (is_string($payload)) {
            @file_put_contents($sessionPath . '/' . self::STREAM_FILE, $payload . PHP_EOL, FILE_APPEND | LOCK_EX);
        }

        if ($sendToClient && !connection_aborted()) {
            $this->emitEvent($event, $data, $sequence);
        }

        return $sequence;
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function replayTranscript(string $sessionPath, int $since, int $offset): array
    {
        $path = $sessionPath . '/' . self::STREAM_FILE;
        if (false === file_exists($path)) {
            return [$since, $offset];
        }

        $handle = @fopen($path, 'rb');
        if (false === $handle) {
            return [$since, $offset];
        }

        if ($offset > 0) {
            fseek($handle, $offset);
        }

        while (false !== ($line = fgets($handle))) {
            $position = ftell($handle);
            $offset = false === $position ? $offset : $position;
            $entry = json_decode(trim($line), true);

            if (!is_array($entry)) {
                continue;
            }

            $sequence = (int) ag($entry, 'id', 0);
            if ($sequence <= $since) {
                continue;
            }

            $since = $sequence;
            $this->emitEvent((string) ag($entry, 'event', 'data'), (string) ag($entry, 'data', ''), $sequence);
        }

        fclose($handle);
        return [$since, $offset];
    }

    private function acquireWriterLock(string $sessionPath): mixed
    {
        $handle = @fopen($sessionPath . '/' . self::WRITER_LOCK_FILE, 'c+');
        if (false === $handle) {
            return null;
        }

        if (false === flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            return null;
        }

        return $handle;
    }

    /**
     * @return array<int,array<string,int|string|null>>
     */
    private function getRecentSessions(): array
    {
        $root = $this->getSessionsRoot();
        if (false === is_dir($root)) {
            return [];
        }

        $items = [];

        foreach (new DirectoryIterator($root) as $item) {
            if ($item->isDot() || false === $item->isDir()) {
                continue;
            }

            $sessionPath = $item->getRealPath();
            if (false === $sessionPath) {
                continue;
            }

            $state = $this->readState($sessionPath);
            if (null === $state || $this->isQueuedAndExpired($state) || $this->isCompletedAndExpired($state)) {
                continue;
            }

            $request = $this->readRequest($sessionPath) ?? [];
            $token = $item->getFilename();
            $command = ag($request, 'command', ag($state, 'command', ''));

            if (!is_string($command) || '' === trim($command)) {
                continue;
            }

            $items[] = [
                'token' => $token,
                'command' => $command,
                'status' => (string) ag($state, 'status', self::STATUS_QUEUED),
                'cwd' => is_string(ag($state, 'cwd')) ? ag($state, 'cwd') : null,
                'created_at' => $this->normalizeIsoDate(ag($state, 'created_at')),
                'updated_at' => $this->normalizeIsoDate(ag($state, 'updated_at')),
                'started_at' => $this->normalizeIsoDate(ag($state, 'started_at')),
                'finished_at' => $this->normalizeIsoDate(ag($state, 'finished_at')),
                'expires_at' => $this->normalizeIsoDate(ag($state, 'expires_at')),
                'available_until' => $this->getAvailableUntil($state),
                'exit_code' => is_numeric(ag($state, 'exit_code')) ? (int) ag($state, 'exit_code') : null,
                'last_sequence' => max(0, (int) ag($state, 'last_sequence', 0)),
                'connections' => max(0, (int) ag($state, 'connections', 0)),
            ];
        }

        usort($items, static function (array $left, array $right): int {
            $leftTime = strtotime((string) ($left['updated_at'] ?? $left['created_at'] ?? ''));
            $rightTime = strtotime((string) ($right['updated_at'] ?? $right['created_at'] ?? ''));

            $leftTime = false === $leftTime ? 0 : $leftTime;
            $rightTime = false === $rightTime ? 0 : $rightTime;

            return $rightTime <=> $leftTime;
        });

        return $items;
    }

    private function getAvailableUntil(array $state): ?string
    {
        if ($this->isCompleted($state)) {
            $finishedAt = ag($state, 'finished_at');
            if (!is_string($finishedAt) || '' === trim($finishedAt)) {
                return null;
            }

            $finishedAtUnix = strtotime($finishedAt);
            if (false === $finishedAtUnix) {
                return null;
            }

            return make_date($finishedAtUnix + self::COMPLETED_SESSION_RETENTION_SECONDS)->format(Date::ATOM);
        }

        $expiresAt = ag($state, 'expires_at');
        return is_string($expiresAt) && '' !== trim($expiresAt) ? $expiresAt : null;
    }

    private function normalizeIsoDate(mixed $value): ?string
    {
        return is_string($value) && '' !== trim($value) ? $value : null;
    }

    private function cleanupSession(string $sessionPath): void
    {
        if (false === is_dir($sessionPath)) {
            return;
        }

        $stateLock = $this->acquireCleanupLock($sessionPath . '/' . self::STATE_LOCK_FILE);
        if (null === $stateLock) {
            return;
        }

        $writerLock = $this->acquireCleanupLock($sessionPath . '/' . self::WRITER_LOCK_FILE);
        if (null === $writerLock) {
            $this->releaseCleanupLock($stateLock);
            return;
        }

        try {
            $files = [
                self::REQUEST_FILE,
                self::STATE_FILE,
                self::STATE_LOCK_FILE,
                self::STREAM_FILE,
                self::WRITER_LOCK_FILE,
                self::CANCEL_FILE,
            ];

            foreach ($files as $file) {
                $path = $sessionPath . '/' . $file;
                if (file_exists($path)) {
                    @unlink($path);
                }
            }

            @rmdir($sessionPath);
        } finally {
            $this->releaseCleanupLock($writerLock);
            $this->releaseCleanupLock($stateLock);
        }
    }

    private function acquireCleanupLock(string $path): mixed
    {
        $handle = @fopen($path, 'c+');
        if (false === $handle) {
            return null;
        }

        if (false === flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            return null;
        }

        return $handle;
    }

    private function releaseCleanupLock(mixed $handle): void
    {
        if (!is_resource($handle)) {
            return;
        }

        flock($handle, LOCK_UN);
        fclose($handle);
    }

    private function getCommandEnv(array $params, bool $pty, string $cwd): array
    {
        $env = [
            'LANG' => 'en_US.UTF-8',
            'LC_ALL' => 'en_US.UTF-8',
            'PWD' => $cwd,
        ];

        if (true === $pty) {
            $env = array_replace($env, [
                'TERM' => 'xterm-256color',
                'COLORTERM' => 'truecolor',
                'FORCE_COLOR' => (string) ag($params, 'force_color', '1'),
                'CLICOLOR' => '1',
            ]);
        }

        return array_replace_recursive($env, $_ENV);
    }

    private function getReplayCursor(iRequest $request): int
    {
        $header = trim($request->getHeaderLine('Last-Event-ID'));
        if ('' !== $header && is_numeric($header)) {
            return max(0, (int) $header);
        }

        $since = ag($request->getQueryParams(), 'since', 0);
        if (is_numeric($since)) {
            return max(0, (int) $since);
        }

        return 0;
    }

    private function getSessionsRoot(): string
    {
        return fix_path(Config::get('tmpDir', getcwd(...)) . '/' . self::SESSIONS_DIR);
    }

    private function getSessionPath(#[\SensitiveParameter] string $token): string
    {
        return fix_path($this->getSessionsRoot() . '/' . $token);
    }

    private function readRequest(string $sessionPath): ?array
    {
        return $this->readJsonFile($sessionPath . '/' . self::REQUEST_FILE);
    }

    private function readState(string $sessionPath): ?array
    {
        return $this->readJsonFile($sessionPath . '/' . self::STATE_FILE);
    }

    private function readJsonFile(string $path): ?array
    {
        if (false === file_exists($path) || false === is_readable($path)) {
            return null;
        }

        $content = @file_get_contents($path);
        if (false === $content || '' === trim($content)) {
            return null;
        }

        $data = json_decode($content, true);
        return is_array($data) ? $data : null;
    }

    private function writeJsonFile(string $path, array $data): void
    {
        $payload = json_encode($data, flags: JSON_PRETTY_PRINT | JSON_INVALID_UTF8_IGNORE);
        $tmpPath = $path . '.tmp';

        if (false === $payload || false === @file_put_contents($tmpPath, $payload, LOCK_EX) || false === @rename($tmpPath, $path)) {
            if (file_exists($tmpPath)) {
                @unlink($tmpPath);
            }

            throw new \RuntimeException("Unable to write console session file '{$path}'.");
        }
    }

    private function mutateState(string $sessionPath, callable $callback): ?array
    {
        $lockPath = $sessionPath . '/' . self::STATE_LOCK_FILE;
        $handle = @fopen($lockPath, 'c+');
        if (false === $handle) {
            return null;
        }

        flock($handle, LOCK_EX);

        try {
            $state = $this->readState($sessionPath);
            if (null === $state) {
                return null;
            }

            $state = $callback($state);
            if (null === $state) {
                return null;
            }

            $this->writeJsonFile($sessionPath . '/' . self::STATE_FILE, $state);
            return $state;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function isQueuedAndExpired(array $state): bool
    {
        if (self::STATUS_QUEUED !== ag($state, 'status')) {
            return false;
        }

        $expiresAt = ag($state, 'expires_at');
        if (!is_string($expiresAt) || '' === trim($expiresAt)) {
            return true;
        }

        return strtotime($expiresAt) < time();
    }

    private function isCompleted(array $state): bool
    {
        return self::STATUS_COMPLETED === ag($state, 'status');
    }

    private function isCompletedAndExpired(array $state): bool
    {
        if (!$this->isCompleted($state) || 0 !== (int) ag($state, 'connections', 0)) {
            return false;
        }

        $finishedAt = ag($state, 'finished_at');
        if (!is_string($finishedAt) || '' === trim($finishedAt)) {
            return true;
        }

        return (strtotime($finishedAt) + self::COMPLETED_SESSION_RETENTION_SECONDS) <= time();
    }

    private function requestCancel(string $sessionPath): void
    {
        @touch($sessionPath . '/' . self::CANCEL_FILE);
    }

    private function isCancelRequested(string $sessionPath): bool
    {
        return file_exists($sessionPath . '/' . self::CANCEL_FILE);
    }

    private function emitPing(): void
    {
        echo ': ping ' . make_date() . "\n\n";
        if (ob_get_level() > 0) {
            @ob_flush();
        }
        flush();
    }

    private function emitEvent(string $event, string $data, ?int $id = null): void
    {
        if (null !== $id) {
            echo 'id: ' . $id . "\n";
        }

        echo "event: {$event}\n";
        echo "data: {$data}\n\n";
        if (ob_get_level() > 0) {
            @ob_flush();
        }
        flush();
    }
}
