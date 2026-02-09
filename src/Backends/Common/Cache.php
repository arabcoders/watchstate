<?php

declare(strict_types=1);

namespace App\Backends\Common;

use App\Libs\Options;
use Countable;
use DateInterval;
use Psr\Log\LoggerInterface as iLogger;
use Psr\SimpleCache\CacheInterface as iCache;
use Psr\SimpleCache\InvalidArgumentException;

/**
 * Class Cache
 *
 * Class to handle backend cache.
 */
final class Cache implements Countable
{
    /**
     * @var array The data to be cached.
     */
    private array $data = [];

    /**
     * @var string|null The key to identify the data in the cache.
     */
    private ?string $key = null;

    /**
     * @var array The options for retrieving the data.
     */
    private array $options = [];

    /**
     * Class to handle backends cache.
     *
     * @param iLogger $logger
     * @param iCache $cache
     */
    public function __construct(
        private iLogger $logger,
        private iCache $cache,
    ) {}

    /**
     * Clone the object with the given logger and cache adapter.
     *
     * @param iLogger|null $logger The logger to use. If not provided, the current logger is used.
     * @param iCache|null $adapter The cache adapter to use. If not provided, the current cache adapter is used.
     *
     * @return Cache return new instance of Cache class.
     */
    public function with(?iLogger $logger = null, ?iCache $adapter = null): self
    {
        $cloned = clone $this;
        $cloned->logger = $logger ?? $this->logger;
        $cloned->cache = $adapter ?? $this->cache;

        return $cloned;
    }

    /**
     * Clone the object with the data retrieved from the cache based on the key.
     *
     * @param string $key The key to identify the data in the cache.
     * @param array $options options for retrieving the data.
     *
     * @return Cache The cloned object with the data.
     */
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

    /**
     * Checks if the given key exists in the cache.
     *
     * @param string $key The key to check.
     *
     * @return bool Returns true if the key exists, false otherwise.
     */
    public function has(string $key): bool
    {
        return ag_exists($this->data, $key);
    }

    /**
     * Retrieves a value from the cache based on the given key.
     *
     * @param string $key The key used to retrieve the value from the cache.
     * @param mixed $default Default value to return if the key is not found in the cache.
     *
     * @return mixed The value associated with the given key in the cache, or the default value if the key is not found.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return ag($this->data, $key, $default);
    }

    /**
     * Sets a value in the cache based on the given key.
     *
     * @param string $key The key used to set the value in the cache.
     * @param mixed $value The value to set in the cache.
     *
     * @return Cache Returns the current object.
     */
    public function set(string $key, mixed $value): self
    {
        $this->data = ag_set($this->data, $key, $value);

        return $this;
    }

    /**
     * Removes a value from the cache based on the given key.
     *
     * @param string $key The key used to remove the value from the cache.
     *
     * @return bool True if the value was successfully removed from the cache, false otherwise.
     */
    public function remove(string $key): bool
    {
        if (false === ag_exists($this->data, $key)) {
            return false;
        }

        $this->data = ag_delete($this->data, $key);
        return true;
    }

    /**
     * If key is set and there is a data within the data array and the dry run option is not set,
     * then the data is flushed to the cache backend.
     *
     * @return void
     */
    public function __destruct()
    {
        if (null === $this->key || $this->count() < 1 || true === (bool) ag($this->options, Options::DRY_RUN)) {
            return;
        }

        try {
            $this->cache->set($this->key, $this->data, new DateInterval('P3D'));
        } catch (InvalidArgumentException) {
        }
    }

    /**
     * Return the underlying cache interface.
     *
     * @return iCache The cache interface.
     */
    public function getInterface(): iCache
    {
        return $this->cache;
    }

    /**
     * Counts the number of elements in the data array.
     *
     * @return int The number of elements in the data array.
     */
    public function count(): int
    {
        return count($this->data);
    }
}
