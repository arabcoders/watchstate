<?php

declare(strict_types=1);

namespace Tests\Libs\Identities;

use App\Libs\Config;
use App\Libs\Container;
use App\Libs\Database\PdoFactory;
use App\Libs\Identities\IdentityProvisionRequest;
use App\Libs\Identities\IdentityProvisionService;
use App\Libs\Options;
use App\Libs\TestCase;
use PDO;
use Psr\Log\LoggerInterface as iLogger;
use Symfony\Component\Yaml\Yaml;

final class IdentityProvisionServiceTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initTempDir();

        $this->tempDir = self::$tmpPath . '/identities';
        $configDir = $this->tempDir . '/config';
        $usersDir = $this->tempDir . '/users/alice';

        mkdir($configDir, 0o755, true);
        mkdir($usersDir, 0o755, true);

        Container::reset();
        Container::init();
        Config::init(require ROOT_PATH . '/config/config.php');

        foreach ((array) require ROOT_PATH . '/config/services.php' as $name => $definition) {
            Container::add($name, $definition);
        }

        Config::save('path', $this->tempDir);
        Config::save('tmpDir', $this->tempDir);
        Config::save('cache.path', $this->tempDir . '/cache');
        Config::save('backends_file', $configDir . '/servers.yaml');
        Config::save('mapper_file', $configDir . '/mapper.yaml');
        Config::save('database.file', $this->tempDir . '/db/' . PdoFactory::DB_FILE);
        Config::save('database.dsn', 'sqlite:' . $this->tempDir . '/db/' . PdoFactory::DB_FILE);

        file_put_contents(
            Config::get('backends_file'),
            Yaml::dump([
                'plex_main' => [
                    'type' => 'plex',
                    'url' => 'https://new-plex.example.com',
                    'token' => 'plex-main-token',
                    'uuid' => 'plex-main-uuid-new',
                    'user' => 'plex-main-user',
                    'import' => ['enabled' => true, 'lastSync' => '2024-01-01T00:00:00+00:00'],
                    'export' => ['enabled' => false, 'lastSync' => '2024-01-01T00:00:00+00:00'],
                    'options' => [
                        Options::ADMIN_TOKEN => 'plex-admin-token-new',
                        Options::IGNORE => false,
                        Options::LIBRARY_SEGMENT => 250,
                    ],
                ],
                'jellyfin_main' => [
                    'type' => 'jellyfin',
                    'url' => 'https://new-jellyfin.example.com',
                    'token' => 'jellyfin-main-token-new',
                    'uuid' => 'jellyfin-main-uuid-new',
                    'user' => 'jellyfin-main-user',
                    'import' => ['enabled' => true, 'lastSync' => '2024-01-01T00:00:00+00:00'],
                    'export' => ['enabled' => true, 'lastSync' => '2024-01-01T00:00:00+00:00'],
                    'options' => [
                        Options::IGNORE => true,
                        Options::LIBRARY_SEGMENT => 500,
                    ],
                ],
            ], 5, 2),
        );

        file_put_contents(
            $usersDir . '/servers.yaml',
            Yaml::dump([
                'plex_alice' => [
                    'type' => 'plex',
                    'url' => 'https://old-plex.example.com',
                    'token' => 'plex-child-token',
                    'uuid' => 'plex-child-uuid-old',
                    'user' => 'plex-child-user',
                    'import' => ['enabled' => false, 'lastSync' => '2025-01-02T03:04:05+00:00'],
                    'export' => ['enabled' => true, 'lastSync' => '2025-01-02T03:04:05+00:00'],
                    'options' => [
                        Options::ALT_NAME => 'plex_main',
                        Options::ALT_ID => 'plex-main-user-old',
                        Options::ADMIN_TOKEN => 'plex-admin-token-old',
                        Options::PLEX_USER_UUID => 'plex-child-user-uuid',
                        Options::PLEX_USER_NAME => 'alice',
                        Options::PLEX_USER_PIN => '1234',
                        Options::PLEX_EXTERNAL_USER => true,
                        'custom_option' => 'keep-me',
                    ],
                ],
                'jellyfin_alice' => [
                    'type' => 'jellyfin',
                    'url' => 'https://old-jellyfin.example.com',
                    'token' => 'jellyfin-main-token-old',
                    'uuid' => 'jellyfin-main-uuid-old',
                    'user' => 'jellyfin-child-user',
                    'import' => ['enabled' => false, 'lastSync' => '2025-02-03T04:05:06+00:00'],
                    'export' => ['enabled' => false, 'lastSync' => '2025-02-03T04:05:06+00:00'],
                    'options' => [
                        Options::ALT_NAME => 'jellyfin_main',
                        Options::ALT_ID => 'jellyfin-main-user-old',
                        Options::IGNORE => false,
                        Options::LIBRARY_SEGMENT => 50,
                        'custom_option' => 'keep-me-too',
                    ],
                ],
                'manual_backend' => [
                    'type' => 'jellyfin',
                    'url' => 'https://manual.example.com',
                    'token' => 'manual-token',
                    'uuid' => 'manual-uuid',
                    'user' => 'manual-user',
                    'import' => ['enabled' => false],
                    'export' => ['enabled' => false],
                    'options' => [
                        'custom_option' => 'manual',
                    ],
                ],
            ], 6, 2),
        );
    }

    protected function tearDown(): void
    {
        Config::reset();
        Container::reset();

        parent::tearDown();
    }

    public function test_syncBackends_dry_run(): void
    {
        $service = $this->makeService();
        $identityConfigPath = $this->tempDir . '/users/alice/servers.yaml';
        $before = file_get_contents($identityConfigPath);

        $result = $service->syncBackends(true);

        $this->assertCount(2, $result['updated'], 'Dry run should report linked backends that would be updated.');
        $this->assertCount(1, $result['skipped'], 'Dry run should skip unlinked backends.');
        $this->assertSame($before, file_get_contents($identityConfigPath), 'Dry run must not persist any identity config changes.');
    }

    public function test_syncBackends_preserves(): void
    {
        $service = $this->makeService();

        $result = $service->syncBackends(false);

        $this->assertCount(2, $result['updated'], 'Two linked identity backends should be updated.');
        $this->assertCount(1, $result['skipped'], 'One unlinked backend should be skipped.');
        $this->assertCount(0, $result['failed'], 'No backend sync failures are expected.');

        $data = Yaml::parseFile($this->tempDir . '/users/alice/servers.yaml');

        $this->assertSame('https://new-plex.example.com', ag($data, 'plex_alice.url'));
        $this->assertSame('plex-main-uuid-new', ag($data, 'plex_alice.uuid'));
        $this->assertSame('plex-child-token', ag($data, 'plex_alice.token'), 'Plex child token must be preserved.');
        $this->assertSame('plex-child-user', ag($data, 'plex_alice.user'), 'Identity-specific backend user must be preserved.');
        $this->assertSame('plex-admin-token-new', ag($data, 'plex_alice.options.' . Options::ADMIN_TOKEN));
        $this->assertSame('plex-child-user-uuid', ag($data, 'plex_alice.options.' . Options::PLEX_USER_UUID));
        $this->assertSame('alice', ag($data, 'plex_alice.options.' . Options::PLEX_USER_NAME));
        $this->assertSame('1234', ag($data, 'plex_alice.options.' . Options::PLEX_USER_PIN));
        $this->assertTrue((bool) ag($data, 'plex_alice.options.' . Options::PLEX_EXTERNAL_USER));
        $this->assertSame('keep-me', ag($data, 'plex_alice.options.custom_option'));
        $this->assertSame('plex_main', ag($data, 'plex_alice.options.' . Options::ALT_NAME));
        $this->assertSame('plex-main-user', ag($data, 'plex_alice.options.' . Options::ALT_ID));
        $this->assertTrue((bool) ag($data, 'plex_alice.import.enabled'));
        $this->assertSame('2025-01-02T03:04:05+00:00', ag($data, 'plex_alice.import.lastSync'));
        $this->assertFalse((bool) ag($data, 'plex_alice.export.enabled'));
        $this->assertSame('2025-01-02T03:04:05+00:00', ag($data, 'plex_alice.export.lastSync'));

        $this->assertSame('https://new-jellyfin.example.com', ag($data, 'jellyfin_alice.url'));
        $this->assertSame('jellyfin-main-uuid-new', ag($data, 'jellyfin_alice.uuid'));
        $this->assertSame('jellyfin-main-token-new', ag($data, 'jellyfin_alice.token'), 'Shared Jellyfin token should sync.');
        $this->assertSame('jellyfin-child-user', ag($data, 'jellyfin_alice.user'), 'Identity-specific backend user must be preserved.');
        $this->assertTrue((bool) ag($data, 'jellyfin_alice.options.' . Options::IGNORE));
        $this->assertSame(500, ag($data, 'jellyfin_alice.options.' . Options::LIBRARY_SEGMENT));
        $this->assertSame('keep-me-too', ag($data, 'jellyfin_alice.options.custom_option'));
        $this->assertSame('jellyfin_main', ag($data, 'jellyfin_alice.options.' . Options::ALT_NAME));
        $this->assertSame('jellyfin-main-user', ag($data, 'jellyfin_alice.options.' . Options::ALT_ID));
        $this->assertTrue((bool) ag($data, 'jellyfin_alice.import.enabled'));
        $this->assertSame('2025-02-03T04:05:06+00:00', ag($data, 'jellyfin_alice.import.lastSync'));
        $this->assertTrue((bool) ag($data, 'jellyfin_alice.export.enabled'));
        $this->assertSame('2025-02-03T04:05:06+00:00', ag($data, 'jellyfin_alice.export.lastSync'));

        $this->assertSame('https://manual.example.com', ag($data, 'manual_backend.url'), 'Unlinked backends must remain untouched.');
        $this->assertSame('manual-token', ag($data, 'manual_backend.token'));
    }

    public function test_createIdentities_initializes_new_identity_db(): void
    {
        $service = $this->makeService();
        $request = new IdentityProvisionRequest(mode: 'create');

        $service->createIdentities($request, [[
            'name' => 'bob',
            'backends' => [[
                'uuid' => 'plex-bob-uuid',
                'client_data' => [
                    'backendName' => 'plex_bob',
                    'type' => 'plex',
                    'name' => 'plex_main',
                    'url' => 'https://new-plex.example.com',
                    'token' => 'plex-bob-token',
                    'user' => 'plex-bob-user',
                    'options' => [
                        Options::ADMIN_TOKEN => 'plex-admin-token-new',
                    ],
                ],
            ]],
        ]]);

        $dbFile = $this->tempDir . '/users/bob/' . PdoFactory::DB_FILE;
        self::assertFileExists($dbFile);

        $pdo = new PDO('sqlite:' . $dbFile);
        $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' ORDER BY name")?->fetchAll(PDO::FETCH_COLUMN);

        self::assertContains('migration_version', $tables);
        self::assertContains('state', $tables);
        self::assertFalse(file_exists($this->tempDir . '/db/' . PdoFactory::DB_FILE));

        $config = Yaml::parseFile($this->tempDir . '/users/bob/servers.yaml');
        self::assertSame('https://new-plex.example.com', ag($config, 'plex_bob.url'));
    }

    private function makeService(): IdentityProvisionService
    {
        return new IdentityProvisionService(
            mapper: Container::get(\App\Libs\Mappers\ImportInterface::class),
            logger: Container::get(iLogger::class),
        );
    }
}
