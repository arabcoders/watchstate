<?php

declare(strict_types=1);

namespace App\Libs;

use App\Libs\Database\DBLayer;
use App\Libs\Database\PDO\PDOAdapter;
use App\Libs\Mappers\Import\DirectMapper;
use App\Libs\Mappers\ImportInterface;
use Closure;
use Monolog\Handler\NullHandler;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PDO;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Psr16Cache;
use Throwable;

class TestCase extends \PHPUnit\Framework\TestCase
{
    protected ?TestHandler $handler = null;

    /**
     * Checks if the given closure throws an exception.
     *
     * @param Closure $closure
     * @param string $reason
     * @param Throwable|string $exception Expected exception class
     * @param string $exceptionMessage (optional) Exception message
     * @param int|null $exceptionCode (optional) Exception code
     * @param callable{ TestCase, Throwable}|null $callback (optional) Custom callback to handle the exception
     * @return void
     */
    protected function checkException(
        Closure $closure,
        string $reason,
        Throwable|string $exception,
        string $exceptionMessage = '',
        ?int $exceptionCode = null,
        ?callable $callback = null,
    ): void {
        $caught = null;
        try {
            $closure();
        } catch (Throwable $e) {
            $caught = $e;
        }

        if (null !== $callback) {
            $callback($this, $caught);
            return;
        }

        if (null === $caught) {
            $this->fail('No exception was thrown. ' . $reason);
            return;
        }

        $this->assertSame(
            is_object($exception) ? $exception::class : $exception,
            is_object($caught) ? $caught::class : $caught,
            $reason . '.; ' . $caught->getMessage(),
        );
        if (!empty($exceptionMessage)) {
            $this->assertStringContainsString($exceptionMessage, $caught->getMessage(), $reason);
        }
        if (!empty($exceptionCode)) {
            $this->assertEquals($exceptionCode, $caught->getCode(), $reason);
        }
    }

    protected function createUserContext(
        string $name = 'test',
        ?ConfigFile $configFile = null,
        ?LoggerInterface $logger = null,
        ?CacheInterface $cache = null,
        ?PDOAdapter $db = null,
        ?ImportInterface $mapper = null,
        array $data = [],
    ): UserContext {
        static $instances = null;

        if (null !== ($instances[$name] ?? null)) {
            return $instances[$name];
        }

        $logger ??= new Logger('test', [new NullHandler()]);
        $cache ??= new Psr16Cache(new ArrayAdapter());
        if (null === $db) {
            $db = new PDOAdapter($logger, new DBLayer(new PDO('sqlite::memory:')));
            $db->migrations('up');
        }

        $filePath = TESTS_PATH . '/Fixtures/test_servers.yaml';
        $instances[$name] = new UserContext(
            name: $name,
            config: $configFile ?? new ConfigFile($filePath, 'yaml', false, false, false),
            mapper: $mapper ?? new DirectMapper(logger: $logger, db: $db, cache: $cache),
            cache: $cache,
            db: $db,
            data: $data,
        );

        return $instances[$name];
    }
}
