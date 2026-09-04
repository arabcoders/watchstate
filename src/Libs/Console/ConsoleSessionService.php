<?php

declare(strict_types=1);

namespace App\Libs\Console;

use App\Libs\Config;
use App\Libs\Extends\Date;
use App\Libs\StreamedBody;
use DirectoryIterator;
use Psr\Http\Message\ServerRequestInterface as iRequest;
use Psr\Log\LoggerInterface as iLogger;
use RuntimeException;
use Throwable;

final class ConsoleSessionService
{
    private const int COMPLETED_RETENTION_SECONDS = 86_400;
    private const int PING_INTERVAL_MICROSECONDS = 200_000;
    private const int PING_AFTER_ITERATIONS = 6;

    private const string STATUS_QUEUED = 'queued';
    private const string STATUS_RUNNING = 'running';
    private const string STATUS_COMPLETED = 'completed';

    private const string REQUEST_FILE = 'request.json';
    private const string STATE_FILE = 'state.json';
    private const string STATE_LOCK_FILE = 'state.lock';
    private const string STREAM_FILE = 'stream.log';
    private const string WRITER_LOCK_FILE = 'writer.lock';
    private const string CANCEL_FILE = 'cancel.flag';

    public function __construct(
        private readonly iLogger $logger,
    ) {}

    public function isWorkerRunning(): bool
    {
        return true === ag(get_worker_status(), 'status', false);
    }

    /** @param array<string,mixed> $params */
    public function queue(array $params, string $expiresAt): string
    {
        $root = $this->getRoot();
        if (false === is_dir($root)) {
            throw new RuntimeException("The path '{$root}' is not a directory.");
        }

        if (false === is_writable($root) || false === is_readable($root)) {
            throw new RuntimeException("Unable to access console session directory '{$root}'.");
        }

        $command = (string) ag($params, 'command', '');
        $token = hash('sha256', random_bytes(12) . $command);
        $sessionPath = $this->getPath($token);
        if (false === @mkdir($sessionPath, 0o755, true) && false === is_dir($sessionPath)) {
            throw new RuntimeException('Unable to create console session.');
        }

        $now = make_date()->format(Date::ATOM);

        try {
            $this->writeJson($sessionPath . '/' . self::REQUEST_FILE, $params);
            $this->writeJson($sessionPath . '/' . self::STATE_FILE, [
                'status' => self::STATUS_QUEUED,
                'command' => $command,
                'cwd' => null,
                'created_at' => $now,
                'expires_at' => $expiresAt,
                'updated_at' => null,
                'started_at' => null,
                'finished_at' => null,
                'exit_code' => null,
                'outcome' => null,
                'failure_reason' => null,
                'worker_pid' => null,
                'child_pid' => null,
                'worker_heartbeat_at' => null,
                'timeout_seconds' => null,
                'last_sequence' => 0,
                'connection_seq' => 0,
                'active_connection' => 0,
                'connections' => 0,
            ]);

            if (false === @touch($sessionPath . '/' . self::STREAM_FILE)) {
                throw new RuntimeException('Unable to create console session transcript.');
            }
        } catch (Throwable $e) {
            $this->remove($sessionPath);
            throw $e;
        }

        $this->logger->info("Console session '{session}' queued.", [
            'operation' => 'console.queue',
            'session' => $this->getSafeId($token),
        ]);

        return $token;
    }

    /** @return array<string,mixed>|null */
    public function getState(#[\SensitiveParameter] string $token): ?array
    {
        if (false === $this->isValidToken($token)) {
            return null;
        }

        return $this->readJson($this->getPath($token) . '/' . self::STATE_FILE);
    }

    /** @return array<string,mixed>|null */
    public function getRequest(#[\SensitiveParameter] string $token): ?array
    {
        if (false === $this->isValidToken($token)) {
            return null;
        }

        return $this->readJson($this->getPath($token) . '/' . self::REQUEST_FILE);
    }

    /** @return Array<array<string,int|string|null>> */
    public function list(): array
    {
        $root = $this->getRoot();
        if (false === is_dir($root)) {
            return [];
        }

        $items = [];
        foreach (new DirectoryIterator($root) as $entry) {
            if ($entry->isDot() || false === $entry->isDir()) {
                continue;
            }

            $token = $entry->getFilename();
            if (false === $this->isValidToken($token)) {
                continue;
            }

            $state = $this->getState($token);
            if (null === $state || $this->isExpired($state)) {
                continue;
            }

            $request = $this->getRequest($token) ?? [];
            $command = ag($request, 'command', ag($state, 'command', ''));
            if (!is_string($command) || '' === trim($command)) {
                continue;
            }

            $items[] = [
                'token' => $token,
                'command' => $command,
                'status' => (string) ag($state, 'status', self::STATUS_QUEUED),
                'cwd' => is_string(ag($state, 'cwd')) ? ag($state, 'cwd') : null,
                'created_at' => $this->normalizeDate(ag($state, 'created_at')),
                'updated_at' => $this->normalizeDate(ag($state, 'updated_at')),
                'started_at' => $this->normalizeDate(ag($state, 'started_at')),
                'finished_at' => $this->normalizeDate(ag($state, 'finished_at')),
                'expires_at' => $this->normalizeDate(ag($state, 'expires_at')),
                'available_until' => $this->getAvailableUntil($state),
                'exit_code' => is_numeric(ag($state, 'exit_code')) ? (int) ag($state, 'exit_code') : null,
                'outcome' => is_string(ag($state, 'outcome')) ? ag($state, 'outcome') : null,
                'failure_reason' => is_string(ag($state, 'failure_reason')) ? ag($state, 'failure_reason') : null,
                'last_sequence' => max(0, (int) ag($state, 'last_sequence', 0)),
                'connections' => max(0, (int) ag($state, 'connections', 0)),
            ];
        }

        usort($items, static function (array $left, array $right): int {
            $leftTime = strtotime((string) ($left['updated_at'] ?? $left['created_at'] ?? ''));
            $rightTime = strtotime((string) ($right['updated_at'] ?? $right['created_at'] ?? ''));
            return (false === $rightTime ? 0 : $rightTime) <=> (false === $leftTime ? 0 : $leftTime);
        });

        return $items;
    }

    /** @return Array<string> */
    public function getTokens(): array
    {
        $root = $this->getRoot();
        if (false === is_dir($root)) {
            return [];
        }

        $tokens = [];
        foreach (new DirectoryIterator($root) as $entry) {
            if ($entry->isDot() || false === $entry->isDir() || false === $this->isValidToken($entry->getFilename())) {
                continue;
            }

            $tokens[] = $entry->getFilename();
        }

        return $tokens;
    }

    public function stream(iRequest $request, #[\SensitiveParameter] string $token): ?StreamedBody
    {
        $this->recover($token);
        $state = $this->getState($token);
        if (null === $state || $this->isExpired($state)) {
            return null;
        }

        $header = trim($request->getHeaderLine('Last-Event-ID'));
        $querySince = ag($request->getQueryParams(), 'since', 0);
        $since = 0;
        if (is_numeric($header)) {
            $since = (int) $header;
        } elseif (is_numeric($querySince)) {
            $since = (int) $querySince;
        }
        $sessionPath = $this->getPath($token);

        return StreamedBody::create(function () use ($sessionPath, $since): string {
            ignore_user_abort(true);
            set_time_limit(0);

            $state = $this->attach($sessionPath);
            if (null === $state) {
                return '';
            }

            $connectionId = (int) ag($state, 'connection_seq', 0);
            $offset = 0;
            $counter = self::PING_AFTER_ITERATIONS;

            try {
                while (!connection_aborted()) {
                    if (false === $this->isActiveConnection($sessionPath, $connectionId)) {
                        return '';
                    }

                    [$since, $offset] = $this->replay($sessionPath, $since, $offset);
                    $state = $this->readJson($sessionPath . '/' . self::STATE_FILE);
                    if (null === $state || self::STATUS_COMPLETED === ag($state, 'status')) {
                        $this->replay($sessionPath, $since, $offset);
                        return '';
                    }

                    usleep(self::PING_INTERVAL_MICROSECONDS);
                    $counter--;
                    if (1 >= $counter) {
                        $counter = self::PING_AFTER_ITERATIONS;
                        $this->emitPing();
                    }
                }
            } finally {
                $this->detach($sessionPath);
            }

            return '';
        });
    }

    public function cancel(#[\SensitiveParameter] string $token): ?string
    {
        $state = $this->getState($token);
        if (null === $state || $this->isExpired($state)) {
            return null;
        }

        if (self::STATUS_COMPLETED === ag($state, 'status')) {
            return 'Command has already completed.';
        }

        $writerLock = $this->acquireWriterLock($token);
        if (is_resource($writerLock)) {
            try {
                $state = $this->getState($token);
                if (self::STATUS_QUEUED === ag($state, 'status')) {
                    $this->complete($token, 130, 'cancelled', 'Command was cancelled before execution.');
                    return 'Command cancellation completed.';
                }

                if (self::STATUS_RUNNING === ag($state, 'status')) {
                    $this->complete($token, 125, 'worker_lost', 'Command worker was lost before cancellation.');
                    return 'Command cancellation completed.';
                }
            } finally {
                $this->releaseLock($writerLock);
            }
        }

        if (false === @touch($this->getPath($token) . '/' . self::CANCEL_FILE)) {
            throw new RuntimeException('Unable to request command cancellation.');
        }

        $this->logger->notice("Console session '{session}' cancellation requested.", [
            'operation' => 'console.cancel',
            'session' => $this->getSafeId($token),
        ]);

        return 'Command cancellation requested.';
    }

    /** @return resource|null */
    public function claim(#[\SensitiveParameter] string $token): mixed
    {
        $writerLock = $this->acquireWriterLock($token);
        if (!is_resource($writerLock)) {
            return null;
        }

        $state = $this->getState($token);
        if (null === $state || self::STATUS_QUEUED !== ag($state, 'status') || $this->isExpired($state)) {
            $this->releaseLock($writerLock);
            return null;
        }

        return $writerLock;
    }

    public function recover(#[\SensitiveParameter] string $token): bool
    {
        $state = $this->getState($token);
        if (null === $state || self::STATUS_RUNNING !== ag($state, 'status')) {
            return false;
        }

        $writerLock = $this->acquireWriterLock($token);
        if (!is_resource($writerLock)) {
            return false;
        }

        try {
            $state = $this->getState($token);
            if (null === $state || self::STATUS_RUNNING !== ag($state, 'status')) {
                return false;
            }

            $this->complete($token, 125, 'worker_lost', 'The console worker stopped before the command completed.');
            return true;
        } finally {
            $this->releaseLock($writerLock);
        }
    }

    public function markRunning(#[\SensitiveParameter] string $token, string $cwd, int $timeoutSeconds): void
    {
        $workerPid = getmypid();
        $state = $this->mutate($token, static function (array $state) use ($cwd, $timeoutSeconds, $workerPid): array {
            $now = make_date()->format(Date::ATOM);
            $state['status'] = self::STATUS_RUNNING;
            $state['cwd'] = $cwd;
            $state['started_at'] = $now;
            $state['updated_at'] = $now;
            $state['worker_pid'] = $workerPid;
            $state['worker_heartbeat_at'] = $now;
            $state['timeout_seconds'] = $timeoutSeconds;
            return $state;
        });
        if (null === $state) {
            throw new RuntimeException('Unable to mark console session as running.');
        }

        $this->logger->info("Console session '{session}' started with worker PID '{process.worker_pid}' and timeout '{timeout_seconds}s'.", [
            'operation' => 'console.start',
            'session' => $this->getSafeId($token),
            'process' => ['worker_pid' => $workerPid],
            'timeout_seconds' => $timeoutSeconds,
        ]);
    }

    public function heartbeat(#[\SensitiveParameter] string $token, ?int $childPid = null): bool
    {
        $state = $this->mutate($token, static function (array $state) use ($childPid): array {
            $state['worker_heartbeat_at'] = make_date()->format(Date::ATOM);
            if (null !== $childPid) {
                $state['child_pid'] = $childPid;
            }
            return $state;
        });

        return null !== $state;
    }

    public function append(#[\SensitiveParameter] string $token, string $event, string $data): bool
    {
        $lock = $this->acquireStateLock($token);
        if (!is_resource($lock)) {
            $this->logStorageFailure($token, 'state_lock_failed');
            return false;
        }

        try {
            $state = $this->getState($token);
            if (null === $state) {
                $this->logStorageFailure($token, 'state_read_failed');
                return false;
            }

            $sequence = (int) ag($state, 'last_sequence', 0) + 1;
            $payload = json_encode([
                'id' => $sequence,
                'event' => $event,
                'data' => $data,
            ], JSON_INVALID_UTF8_IGNORE);
            if (!is_string($payload)) {
                $this->logStorageFailure($token, 'transcript_encode_failed');
                return false;
            }

            $record = $payload . PHP_EOL;
            $written = @file_put_contents(
                $this->getPath($token) . '/' . self::STREAM_FILE,
                $record,
                FILE_APPEND | LOCK_EX,
            );
            if (strlen($record) !== $written) {
                $this->logStorageFailure($token, 'transcript_write_failed');
                return false;
            }

            $state['last_sequence'] = $sequence;
            $state['updated_at'] = make_date()->format(Date::ATOM);
            $this->writeJson($this->getPath($token) . '/' . self::STATE_FILE, $state);
            return true;
        } catch (Throwable $e) {
            $this->logger->error("Console session '{session}' failed to persist transcript event '{event}'.", [
                'operation' => 'console.transcript',
                'session' => $this->getSafeId($token),
                'event' => $event,
                'error' => 'storage_failure',
                ...exception_log($e),
            ]);
            return false;
        } finally {
            $this->releaseLock($lock);
        }
    }

    public function complete(
        #[\SensitiveParameter]
        string $token,
        int $exitCode,
        string $outcome,
        string $message = '',
    ): void {
        $stored = true;
        if ('' !== $message) {
            $payload = json_encode([
                'data' => r("ERROR: {message}\n", ['message' => trim($message)]),
                'type' => 'err',
            ], JSON_INVALID_UTF8_IGNORE);
            $stored = is_string($payload) && $this->append($token, 'data', $payload);
        }

        $stored = $this->append($token, 'exit_code', (string) $exitCode) && $stored;
        $stored = $this->append($token, 'close', make_date()->format(Date::ATOM)) && $stored;
        if (false === $stored) {
            $outcome = 'storage_failure';
            $message = 'Unable to persist the complete command transcript.';
        }

        try {
            $state = $this->mutate($token, static function (array $state) use ($exitCode, $outcome, $message): array {
                $now = make_date()->format(Date::ATOM);
                $state['status'] = self::STATUS_COMPLETED;
                $state['exit_code'] = $exitCode;
                $state['finished_at'] = $now;
                $state['updated_at'] = $now;
                $state['outcome'] = $outcome;
                $state['failure_reason'] = match (true) {
                    'success' === $outcome => null,
                    '' !== $message => $message,
                    default => $outcome,
                };
                $state['worker_heartbeat_at'] = null;
                return $state;
            });
            if (null === $state) {
                throw new RuntimeException('Unable to persist console session terminal state.');
            }
        } catch (Throwable $e) {
            $this->logger->error("Console session '{session}' failed to persist terminal state.", [
                'operation' => 'console.complete',
                'session' => $this->getSafeId($token),
                'error' => 'storage_failure',
                ...exception_log($e),
            ]);
            return;
        }

        $context = [
            'operation' => 'console.complete',
            'session' => $this->getSafeId($token),
            'process' => ['exit_code' => $exitCode],
            'outcome' => $outcome,
        ];
        if ('success' === $outcome) {
            $this->logger->info("Console session '{session}' completed with exit code '{process.exit_code}'.", $context);
            return;
        }

        $context['error'] = $outcome;
        $this->logger->error("Console session '{session}' ended as '{outcome}' with exit code '{process.exit_code}'.", $context);
    }

    public function isCancellationRequested(#[\SensitiveParameter] string $token): bool
    {
        return $this->isValidToken($token) && file_exists($this->getPath($token) . '/' . self::CANCEL_FILE);
    }

    /** @param resource|null $lock */
    public function releaseLock(mixed $lock): void
    {
        if (is_resource($lock)) {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function attach(string $sessionPath): ?array
    {
        return $this->mutatePath($sessionPath, function (array $state): ?array {
            if ($this->isExpired($state)) {
                return null;
            }

            $connectionId = (int) ag($state, 'connection_seq', 0) + 1;
            $state['connection_seq'] = $connectionId;
            $state['active_connection'] = $connectionId;
            $state['connections'] = max(0, (int) ag($state, 'connections', 0)) + 1;
            $state['updated_at'] = make_date()->format(Date::ATOM);
            return $state;
        });
    }

    private function detach(string $sessionPath): void
    {
        $this->mutatePath($sessionPath, static function (array $state): array {
            $state['connections'] = max(0, (int) ag($state, 'connections', 0) - 1);
            $state['updated_at'] = make_date()->format(Date::ATOM);
            return $state;
        });
    }

    private function isActiveConnection(string $sessionPath, int $connectionId): bool
    {
        $state = $this->readJson($sessionPath . '/' . self::STATE_FILE);
        return null !== $state && $connectionId === (int) ag($state, 'active_connection', 0);
    }

    /** @return array{0:int,1:int} */
    private function replay(string $sessionPath, int $since, int $offset): array
    {
        $handle = @fopen($sessionPath . '/' . self::STREAM_FILE, 'rb');
        if (false === $handle) {
            return [$since, $offset];
        }

        if (0 < $offset) {
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
            $this->emitEvent(
                (string) ag($entry, 'event', 'data'),
                (string) ag($entry, 'data', ''),
                $sequence,
            );
        }

        fclose($handle);
        return [$since, $offset];
    }

    private function acquireWriterLock(#[\SensitiveParameter] string $token): mixed
    {
        if (false === $this->isValidToken($token)) {
            return null;
        }

        $handle = @fopen($this->getPath($token) . '/' . self::WRITER_LOCK_FILE, 'c+');
        if (false === $handle) {
            return null;
        }

        if (false === flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            return null;
        }

        return $handle;
    }

    private function acquireStateLock(#[\SensitiveParameter] string $token): mixed
    {
        if (false === $this->isValidToken($token)) {
            return null;
        }

        $handle = @fopen($this->getPath($token) . '/' . self::STATE_LOCK_FILE, 'c+');
        if (false === $handle) {
            return null;
        }

        if (false === flock($handle, LOCK_EX)) {
            fclose($handle);
            return null;
        }

        return $handle;
    }

    /**
     * @param callable(array<string,mixed>):array<string,mixed> $callback
     * @return array<string,mixed>|null
     */
    private function mutate(#[\SensitiveParameter] string $token, callable $callback): ?array
    {
        if (false === $this->isValidToken($token)) {
            return null;
        }

        return $this->mutatePath($this->getPath($token), $callback);
    }

    /**
     * @param callable(array<string,mixed>):array<string,mixed>|null $callback
     * @return array<string,mixed>|null
     */
    private function mutatePath(string $sessionPath, callable $callback): ?array
    {
        $handle = @fopen($sessionPath . '/' . self::STATE_LOCK_FILE, 'c+');
        if (false === $handle || false === flock($handle, LOCK_EX)) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            return null;
        }

        try {
            $state = $this->readJson($sessionPath . '/' . self::STATE_FILE);
            if (null === $state || null === ($state = $callback($state))) {
                return null;
            }

            $this->writeJson($sessionPath . '/' . self::STATE_FILE, $state);
            return $state;
        } finally {
            $this->releaseLock($handle);
        }
    }

    /** @return array<string,mixed>|null */
    private function readJson(string $path): ?array
    {
        if (false === is_readable($path)) {
            return null;
        }

        $contents = @file_get_contents($path);
        if (false === $contents || '' === trim($contents)) {
            return null;
        }

        $data = json_decode($contents, true);
        return is_array($data) ? $data : null;
    }

    /** @param array<string,mixed> $data */
    private function writeJson(string $path, array $data): void
    {
        $payload = json_encode($data, JSON_PRETTY_PRINT | JSON_INVALID_UTF8_IGNORE);
        $temporaryPath = $path . '.tmp';
        if (
            !is_string($payload)
            || strlen($payload) !== @file_put_contents($temporaryPath, $payload, LOCK_EX)
            || false === @rename($temporaryPath, $path)
        ) {
            @unlink($temporaryPath);
            throw new RuntimeException('Unable to write console session state.');
        }
    }

    private function isExpired(array $state): bool
    {
        if (self::STATUS_QUEUED === ag($state, 'status')) {
            $expiresAt = strtotime((string) ag($state, 'expires_at', ''));
            return false === $expiresAt || $expiresAt < time();
        }

        if (self::STATUS_COMPLETED !== ag($state, 'status') || 0 !== (int) ag($state, 'connections', 0)) {
            return false;
        }

        $finishedAt = strtotime((string) ag($state, 'finished_at', ''));
        return false === $finishedAt || ($finishedAt + self::COMPLETED_RETENTION_SECONDS) <= time();
    }

    private function getAvailableUntil(array $state): ?string
    {
        if (self::STATUS_COMPLETED !== ag($state, 'status')) {
            return $this->normalizeDate(ag($state, 'expires_at'));
        }

        $finishedAt = strtotime((string) ag($state, 'finished_at', ''));
        return false === $finishedAt
            ? null
            : make_date($finishedAt + self::COMPLETED_RETENTION_SECONDS)->format(Date::ATOM);
    }

    private function normalizeDate(mixed $value): ?string
    {
        return is_string($value) && '' !== trim($value) ? $value : null;
    }

    private function getRoot(): string
    {
        return fix_path((string) Config::get('tmpDir', getcwd(...)) . '/console');
    }

    private function getPath(#[\SensitiveParameter] string $token): string
    {
        return fix_path($this->getRoot() . '/' . $token);
    }

    private function isValidToken(#[\SensitiveParameter] string $token): bool
    {
        return 1 === preg_match('/^[a-f0-9]{64}$/', $token);
    }

    private function getSafeId(#[\SensitiveParameter] string $token): string
    {
        return substr(hash('sha256', $token), 0, 12);
    }

    private function logStorageFailure(#[\SensitiveParameter] string $token, string $reason): void
    {
        $this->logger->error("Console session '{session}' failed to persist output: {error}.", [
            'operation' => 'console.transcript',
            'session' => $this->getSafeId($token),
            'error' => $reason,
        ]);
    }

    private function emitEvent(string $event, string $data, int $sequence): void
    {
        echo "id: {$sequence}\n";
        echo "event: {$event}\n";
        echo "data: {$data}\n\n";
        if (0 < ob_get_level()) {
            @ob_flush();
        }
        flush();
    }

    private function emitPing(): void
    {
        echo ': ping ' . make_date() . "\n\n";
        if (0 < ob_get_level()) {
            @ob_flush();
        }
        flush();
    }

    private function remove(string $sessionPath): void
    {
        foreach ([
            self::REQUEST_FILE,
            self::STATE_FILE,
            self::STATE_LOCK_FILE,
            self::STREAM_FILE,
            self::WRITER_LOCK_FILE,
            self::CANCEL_FILE,
        ] as $file) {
            @unlink($sessionPath . '/' . $file);
        }
        @rmdir($sessionPath);
    }
}
