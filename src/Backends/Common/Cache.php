<?php

declare(strict_types=1);

namespace App\Backends\Common;

use App\Libs\Options;
use Countable;
use DateInterval;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;
use Psr\SimpleCache\InvalidArgumentException;

final class Cache implements Countable
{
    private array $data = [];
    private string|null $key = null;
    private array $options = [];

    /**
     * Class to handle backends cache.
     *
     * @param LoggerInterface $logger
     * @param CacheInterface $cache
     */
    public function __construct(private LoggerInterface $logger, private CacheInterface $cache)
    {
    }

    public function withData(string $key, array $options = []): self
    {
        $cloned = clone $this;
        $cloned->key = $key;
        $cloned->options = $options;

        try {
            $cloned->data = $cloned->cache->get($key, []);
        } catch (InvalidArgumentException $e) {
            $this->logger->error('Failed to load cache data for key [{key}]. {message}', [
                'key' => $key,
                'message' => $e->getMessage(),
            ]);
            $cloned->data = [];
        }

        return $cloned;
    }

    public function has(string $key): bool
    {
        return ag_exists($this->data, $key);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return ag($this->data, $key, $default);
    }

    public function set(string $key, mixed $value): self
    {
        $this->data = ag_set($this->data, $key, $value);

        return $this;
    }

    public function remove(string $key): bool
    {
        if (false === ag_exists($this->data, $key)) {
            return false;
        }

        $this->data = ag_delete($this->data, $key);
        return true;
    }

    public function __destruct()
    {
        if (null === $this->key || $this->count() < 1 || true === (bool)ag($this->options, Options::DRY_RUN)) {
            return;
        }

        try {
            $this->cache->set($this->key, $this->data, new DateInterval('P3D'));
        } catch (InvalidArgumentException) {
        }
    }

    public function count(): int
    {
        return count($this->data);
    }
}
