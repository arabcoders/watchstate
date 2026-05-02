<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Libs\Config;
use App\Libs\Container;
use App\Libs\Database\DatabaseInterface as iDB;
use App\Libs\Database\PackageMigrationFactory;
use App\Libs\Entity\StateEntity;
use App\Libs\Mappers\Import\DirectMapper;
use App\Libs\UserContext;
use Monolog\Logger;
use PDO;
use Psr\SimpleCache\CacheInterface as iCache;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Psr16Cache;
use Symfony\Component\Yaml\Yaml;

trait StateCommandTestSupport
{
    /**
     * @param array<string,mixed> $mainBackends
     * @param array<string,array<string,mixed>> $userBackends
     */
    private function initFakeBackendApp(array $mainBackends, array $userBackends = []): Logger
    {
        $this->initTempApp();
        Config::save('supported.fake', FakeBackendClient::class);
        FakeBackendClient::reset();

        $this->writeBackendsFile((string) Config::get('backends_file'), $mainBackends);

        foreach ($userBackends as $user => $config) {
            $userDir = self::$tmpPath . '/users/' . $user;
            if (!is_dir($userDir)) {
                mkdir($userDir, 0o755, true);
            }

            $this->writeBackendsFile($userDir . '/servers.yaml', $config);
        }

        return new Logger('test');
    }

    /**
     * @param array<string,mixed> $overrides
     *
     * @return array<string,array<string,mixed>>
     */
    private function fakeBackendConfig(string $name = 'fake_backend', array $overrides = []): array
    {
        return [
            $name => array_replace_recursive([
                'type' => 'fake',
                'url' => r('https://{name}.example.invalid', ['name' => $name]),
                'token' => 'token-' . $name,
                'user' => 'user-' . $name,
                'uuid' => 'uuid-' . $name,
                'import' => [
                    'enabled' => true,
                    'lastSync' => 1_700_000_000,
                ],
                'export' => [
                    'enabled' => true,
                    'lastSync' => 1_700_000_000,
                ],
                'options' => [],
            ], $overrides),
        ];
    }

    private function migrateMainDb(Logger $logger): iDB
    {
        $pdo = Container::get(PDO::class);
        $migrations = new PackageMigrationFactory();

        if (false === $migrations->isMigrated($pdo)) {
            $migrations->migrate($pdo, dryRun: false);
        }

        ensure_indexes($pdo, $logger);

        $db = Container::get(iDB::class);
        $db->setOptions(['class' => new StateEntity([])]);

        return $db;
    }

    private function createRuntimeMapper(Logger $logger): DirectMapper
    {
        return new DirectMapper($logger, Container::get(iDB::class), Container::get(iCache::class));
    }

    private function createArrayCache(): iCache
    {
        return new Psr16Cache(new ArrayAdapter());
    }

    private function makeUserContext(string $user, Logger $logger): UserContext
    {
        $this->migrateMainDb($logger);

        if ('main' !== $user) {
            ensure_migration(get_user_db($user));
        }

        $mapper = new DirectMapper($logger, Container::get(iDB::class), Container::get(iCache::class));
        $userContext = get_user_context($user, $mapper, $logger);
        $userContext->db->setOptions(['class' => new StateEntity([])]);

        return $userContext;
    }

    /**
     * @param array<string,mixed> $backends
     */
    private function writeBackendsFile(string $file, array $backends): void
    {
        file_put_contents($file, Yaml::dump($backends, 8, 2));
    }
}
