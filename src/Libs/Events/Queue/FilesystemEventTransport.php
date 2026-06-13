<?php

declare(strict_types=1);

namespace App\Libs\Events\Queue;

use DirectoryIterator;
use JsonException;
use RuntimeException;
use Throwable;

final class FilesystemEventTransport implements EventTransportInterface
{
    private const string EXTENSION = '.json';
    private const string PROCESSING_SUFFIX = '.processing';

    public function __construct(
        private readonly string $path,
        private readonly int $claimAfterSeconds = 300,
    ) {
        $this->ensureDirectories();
    }

    /**
     * @inheritdoc
     */
    public function enqueue(EventEnvelope $envelope): EventEnvelope
    {
        try {
            $payload = json_encode($envelope->toArray(), flags: JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException(r('Unable to encode queue envelope: {error}', ['error' => $e->getMessage()]), previous: $e);
        }

        $filename = $this->filename($envelope);
        $tmp = $this->dir('tmp') . '/' . $filename . '.tmp.' . getmypid();
        $target = $this->dir('pending') . '/' . $filename;

        if (false === @file_put_contents($tmp, $payload, LOCK_EX)) {
            throw new RuntimeException(r("Unable to write event queue payload '{file}'.", ['file' => $tmp]));
        }

        if (false === @rename($tmp, $target)) {
            if (true === file_exists($tmp)) {
                @unlink($tmp);
            }
            throw new RuntimeException(r("Unable to queue event payload '{file}'.", ['file' => $target]));
        }

        return $envelope->withAck($target);
    }

    /**
     * @inheritdoc
     */
    public function dequeue(int $limit): array
    {
        $limit = max(1, $limit);
        $this->reclaimStale();

        $items = $this->pendingFiles();
        $claimed = [];

        foreach ($items as $file) {
            if (count($claimed) >= $limit) {
                break;
            }

            $source = $this->dir('pending') . '/' . $file;
            $target = $this->dir('processing') . '/' . $file . '.' . getmypid() . self::PROCESSING_SUFFIX;

            if (false === @rename($source, $target)) {
                continue;
            }

            try {
                $payload = $this->readPayload($target);
                $claimed[] = EventEnvelope::fromArray($payload, $target);
            } catch (Throwable) {
                $this->moveFailed($target);
            }
        }

        return $claimed;
    }

    /**
     * @inheritdoc
     */
    public function ack(EventEnvelope $envelope): void
    {
        if (false === is_string($envelope->ack) || '' === $envelope->ack) {
            return;
        }

        @unlink($envelope->ack);
    }

    /**
     * @inheritdoc
     */
    public function release(EventEnvelope $envelope): void
    {
        if (false === is_string($envelope->ack) || '' === $envelope->ack || false === file_exists($envelope->ack)) {
            return;
        }

        @rename($envelope->ack, $this->dir('pending') . '/' . $this->pendingName($envelope->ack));
    }

    /**
     * @inheritdoc
     */
    public function fail(EventEnvelope $envelope): void
    {
        if (false === is_string($envelope->ack) || '' === $envelope->ack || false === file_exists($envelope->ack)) {
            return;
        }

        $this->moveFailed($envelope->ack);
    }

    /**
     * @inheritdoc
     */
    public function count(): int
    {
        return count($this->pendingFiles());
    }

    private function ensureDirectories(): void
    {
        foreach (['pending', 'processing', 'failed', 'tmp'] as $dir) {
            $path = $this->dir($dir);
            if (true === is_dir($path)) {
                continue;
            }

            if (false === mkdir($path, 0o755, true) && false === is_dir($path)) {
                throw new RuntimeException(r("Unable to create event queue directory '{path}'.", ['path' => $path]));
            }
        }
    }

    private function dir(string $name): string
    {
        return rtrim(fix_path($this->path), '/') . '/' . $name;
    }

    private function filename(EventEnvelope $envelope): string
    {
        $id = preg_replace('/[^A-Za-z0-9_.-]/', '_', $envelope->id);
        if (false === is_string($id) || '' === $id) {
            $id = generate_uuid();
        }

        return sprintf('%020d.%s%s', (int) (microtime(true) * 1_000_000), $id, self::EXTENSION);
    }

    /**
     * @return array<string>
     */
    private function pendingFiles(): array
    {
        $files = [];

        foreach (new DirectoryIterator($this->dir('pending')) as $file) {
            if ($file->isDot() || false === $file->isFile()) {
                continue;
            }

            $name = $file->getFilename();
            if (false === str_ends_with($name, self::EXTENSION)) {
                continue;
            }

            $files[] = $name;
        }

        sort($files, SORT_STRING);

        return $files;
    }

    private function reclaimStale(): void
    {
        $threshold = time() - max(1, $this->claimAfterSeconds);

        foreach (new DirectoryIterator($this->dir('processing')) as $file) {
            if ($file->isDot() || false === $file->isFile()) {
                continue;
            }

            $path = $file->getPathname();
            $modifiedAt = $file->getMTime();
            if ($modifiedAt > $threshold) {
                continue;
            }

            @rename($path, $this->dir('pending') . '/' . $this->pendingName($path));
        }
    }

    private function pendingName(string $path): string
    {
        $basename = basename($path);
        $name = preg_replace('/\.\d+' . preg_quote(self::PROCESSING_SUFFIX, '/') . '$/', '', $basename);

        return is_string($name) && '' !== $name ? $name : $basename;
    }

    /**
     * @return array<string, mixed>
     */
    private function readPayload(string $path): array
    {
        if (false === ($content = @file_get_contents($path))) {
            throw new RuntimeException(r("Unable to read event queue payload '{file}'.", ['file' => $path]));
        }

        try {
            $payload = json_decode($content, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException(r("Invalid JSON in event queue payload '{file}': {error}", [
                'file' => $path,
                'error' => $e->getMessage(),
            ]), previous: $e);
        }

        return $payload;
    }

    private function moveFailed(string $path): void
    {
        @rename($path, $this->dir('failed') . '/' . basename($path));
    }
}
