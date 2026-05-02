<?php

declare(strict_types=1);

namespace App\Libs;

use App\Libs\Database\DBLayer;
use App\Libs\Database\PackageMigrationFactory;
use App\Libs\Database\PDO\PDOAdapter;
use App\Libs\Database\PdoFactory;
use App\Libs\Mappers\Import\DirectMapper;
use App\Libs\Mappers\ImportInterface;
use arabcoders\database\Connection as DatabaseConnection;
use arabcoders\database\Dialect\DialectFactory;
use Closure;
use Monolog\Handler\NullHandler;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PDO;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Psr16Cache;
use Throwable;

class TestCase extends \PHPUnit\Framework\TestCase
{
    protected ?TestHandler $handler = null;
    protected static ?string $tmpPath = null;

    protected function tearDown(): void
    {
        Config::reset();
        Container::reset();

        parent::tearDown();

        if (null === self::$tmpPath) {
            return;
        }

        $dir = self::$tmpPath;
        self::$tmpPath = null;

        if (!is_dir($dir)) {
            return;
        }

        $this->forceChmodRecursive($dir);
        $this->forceRemoveRecursive($dir);
    }

    protected function initTempDir(): void
    {
        if (null !== self::$tmpPath) {
            return;
        }

        self::$tmpPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ws-tests' . DIRECTORY_SEPARATOR . uniqid();
        if (!is_dir(self::$tmpPath) && !mkdir(self::$tmpPath, 0o777, true) && !is_dir(self::$tmpPath)) {
            throw new \RuntimeException(sprintf('Directory "%s" was not created', self::$tmpPath));
        }
    }

    protected function initTempApp(?string $path = null): string
    {
        $this->initTempDir();

        $path ??= self::$tmpPath;

        $configDir = $path . '/config';
        if (!is_dir($configDir) && !mkdir($configDir, 0o755, true) && !is_dir($configDir)) {
            throw new \RuntimeException(sprintf('Directory "%s" was not created', $configDir));
        }

        Config::init(require ROOT_PATH . '/config/config.php');
        Config::save('path', $path);
        Config::save('tmpDir', $path);
        Config::save('cache.path', $path . '/cache');
        Config::save('backends_file', $configDir . '/servers.yaml');
        Config::save('mapper_file', $configDir . '/mapper.yaml');
        Config::save('database.file', $path . '/db/' . PdoFactory::DB_FILE);
        Config::save('database.dsn', 'sqlite:' . $path . '/db/' . PdoFactory::DB_FILE);

        $this->initContainer();

        return $configDir;
    }

    protected function initContainer(): void
    {
        Container::reset();
        Container::init();

        foreach ((array) require ROOT_PATH . '/config/services.php' as $name => $definition) {
            Container::add($name, $definition);
        }
    }

    protected function seedTestServersConfig(?string $user = null): void
    {
        $target = (string) Config::get('backends_file');

        if (null !== $user) {
            $userDir = self::$tmpPath . '/users/' . $user;
            if (!is_dir($userDir) && !mkdir($userDir, 0o755, true) && !is_dir($userDir)) {
                throw new \RuntimeException(sprintf('Directory "%s" was not created', $userDir));
            }

            $target = $userDir . '/servers.yaml';
        }

        $fixture = file_get_contents(TESTS_PATH . '/Fixtures/test_servers.yaml');
        assert(false !== $fixture, 'Expected test backend fixture config.');

        file_put_contents($target, $fixture);
    }

    protected function createDb(?LoggerInterface $logger = null): PDOAdapter
    {
        $logger ??= new Logger('test', [new NullHandler()]);
        $pdo = new PDO('sqlite::memory:');
        $db = new PDOAdapter($logger, new DBLayer($this->createConnection($pdo)));

        $migrations = new PackageMigrationFactory();
        if (false === $migrations->isMigrated($pdo)) {
            $migrations->migrate($pdo, dryRun: false);
        }

        ensure_indexes($pdo, $logger);

        return $db;
    }

    protected function createConnection(PDO $pdo): DatabaseConnection
    {
        return new DatabaseConnection($pdo, DialectFactory::fromPdo($pdo));
    }

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
        static $instances = [];

        if (null !== ($instances[$name] ?? null)) {
            return $instances[$name];
        }

        $logger ??= new Logger('test', [new NullHandler()]);
        $cache ??= new Psr16Cache(new ArrayAdapter());
        $db ??= $this->createDb($logger);

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

    private function forceChmodRecursive(string $path): void
    {
        @chmod($path, 0o777);

        if (!is_dir($path)) {
            return;
        }

        if (false === ($items = @scandir($path))) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $full = $path . DIRECTORY_SEPARATOR . $item;

            if (is_dir($full)) {
                $this->forceChmodRecursive($full);
            } else {
                @chmod($full, 0o666);
            }
        }
    }

    private function forceRemoveRecursive(string $dir): void
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $file) {
            if ($file->isDir()) {
                rmdir($file->getPathname());
            } else {
                unlink($file->getPathname());
            }
        }

        rmdir($dir);
    }
}
