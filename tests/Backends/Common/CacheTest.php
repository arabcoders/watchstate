<?php
/** @noinspection PhpMultipleClassDeclarationsInspection */

declare(strict_types=1);

namespace Tests\Backends\Common;

use App\Backends\Common\Cache;
use App\Libs\TestCase;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;
use stdClass;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\CacheItem;
use Symfony\Component\Cache\Exception\InvalidArgumentException;
use Symfony\Component\Cache\Psr16Cache;

class CacheTest extends TestCase
{
    private Cache|null $cache = null;
    private PSRCache|null $psrCache = null;

    protected function setUp(): void
    {
        $this->psrCache = new PSRCache();

        $this->cache = new Cache(
            logger: new Logger('test'),
            cache: $this->psrCache,
        );
        $this->cache = $this->cache->withData('namespace', []);
        $this->handler = new TestHandler();
        $this->logger = new Logger('logger');
        $this->logger->pushHandler($this->handler);
        parent::setUp();
    }

    public function test_backend_cache_get(): void
    {
        $obj = new StdClass();
        $obj->foo = 'bar';
        $obj->bar = 'baz';

        $types = [
            'foo' => 'bar',
            'bar.baz' => 1,
            'bar.bar.kaz' => 1.1,
            'taz' => true,
            'faz' => false,
            'nay.foo' => null,
            'arr.a.y' => ['foo' => 'bar'],
            'o.b.j.e.c.t' => $obj,
        ];

        foreach ($types as $key => $value) {
            $this->cache->set($key, $value);
            $this->assertEquals(
                $value,
                $this->cache->get($key),
                'Assert value type is preserved'
            );
        }

        $this->assertEquals(
            'default_value',
            $this->cache->get('non_set', 'default_value'),
            'Assert default value is returned when key is not found'
        );
    }

    public function test_backend_cache_has(): void
    {
        $this->cache->set('key_name', 'value');
        $this->assertTrue(
            $this->cache->has('key_name'),
            'Assert key exists'
        );

        $this->assertFalse(
            $this->cache->has('non_set'),
            'Assert key does not exist'
        );
    }

    public function test_backend_cache_set(): void
    {
        $this->cache->set('foo.bar', 'value');
        $this->assertTrue(
            $this->cache->has('foo.bar'),
            'Assert key exists'
        );

        $this->assertEquals(
            'value',
            $this->cache->get('foo.bar'),
            'assert returned value is correct'
        );
    }

    public function test_backend_cache_remove(): void
    {
        $this->cache->set('foo.bar', 'value');
        $this->assertTrue(
            $this->cache->has('foo.bar'),
            'Assert key exists'
        );

        $this->assertTrue(
            $this->cache->remove('foo.bar'),
            'Assert remove() true is returned when key exists and is removed'
        );

        $this->assertFalse(
            $this->cache->remove('foo.taz'),
            'Assert remove() false is returned when key does not exist'
        );

        $this->assertFalse(
            $this->cache->has('foo.bar'),
            'Assert key does not exist'
        );
    }

    public function test_backend_cache_total(): void
    {
        $this->assertCount(
            0,
            $this->cache,
            'Assert cache is empty'
        );

        $this->cache->set('foo.bar', 'value');
        $this->assertCount(
            1,
            $this->cache,
            'Assert cache has 1 item'
        );

        $this->cache->set('foo.baz', 'value');
        $this->assertCount(
            1,
            $this->cache,
            'Assert count still at 1 when adding another item with same parent key'
        );

        $this->cache->set('bar', 'value');
        $this->assertCount(
            2,
            $this->cache,
            'There should be 2 items in the cache'
        );

        $this->cache->remove('foo.bar');
        $this->assertCount(
            2,
            $this->cache,
            'Assert count still at 2 when removing a single item from a parent key'
        );

        $this->cache->remove('foo');
        $this->cache->remove('bar');
        $this->assertCount(
            0,
            $this->cache,
            'Assert cache is empty after removing all items'
        );
    }

    public function test_backend_cache_withData_exception_handling(): void
    {
        $c = new Cache(
            logger: $this->logger,
            cache: new Psr16Cache(
                new class() extends ArrayAdapter {
                    public function getItem(mixed $key): CacheItem
                    {
                        throw new InvalidArgumentException('foo');
                    }
                }
            ),
        );

        $c->withData('namespace', []);

        $this->assertStringContainsString(
            'Failed to load cache data for key',
            $this->handler->getRecords()[0]['message'],
            'Assert exception is caught and logged'
        );
    }

    public function test_backend_cache__destruct(): void
    {
        $c = new Cache(
            logger: $this->logger,
            cache: new class($this->logger) extends PSRCache {
                public function __construct(private LoggerInterface $logger)
                {
                }

                public function set(string $key, mixed $value, \DateInterval|int|null $ttl = null): bool
                {
                    $this->logger->info('set() called');
                    throw new InvalidArgumentException('foo');
                }
            },
        );

        $c = $c->withData('namespaced');

        $c->set('foo', 'bar');
        $c->__destruct();

        $this->assertStringContainsString(
            'set() called',
            $this->handler->getRecords()[0]['message'],
            'assert set() is called and exception is caught'
        );
    }

    public function test_backend_cache__destruct_saved(): void
    {
        $c = $this->cache->withData('namespaced');

        $c->set('foo', 'bar');
        $c->__destruct();

        $this->assertCount(
            1,
            $this->psrCache->getData(),
            'Assert data is saved into backend.'
        );
    }
}

class PSRCache implements CacheInterface
{
    private array $data = [];

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    public function set(string $key, mixed $value, \DateInterval|int|null $ttl = null): bool
    {
        $this->data[$key] = $value;

        return true;
    }

    public function delete(string $key): bool
    {
        unset($this->data[$key]);

        return true;
    }

    public function clear(): bool
    {
        $this->data = [];

        return true;
    }

    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        return [];
    }

    public function setMultiple(iterable $values, \DateInterval|int|null $ttl = null): bool
    {
        return false;
    }

    public function deleteMultiple(iterable $keys): bool
    {
        return false;
    }

    public function has(string $key): bool
    {
        return isset($this->data[$key]);
    }

    /**
     * @return array
     */
    public function getData(): array
    {
        return $this->data;
    }
}
