<?php
/** @noinspection PhpUnhandledExceptionInspection */

declare(strict_types=1);

namespace Tests\Libs;

use App\Libs\Container;
use App\Libs\Database\PdoFactory;
use App\Libs\Entity\StateEntity;
use App\Libs\Exceptions\RuntimeException;
use App\Libs\Mappers\Import\DirectMapper;
use App\Libs\Options;
use App\Libs\TestCase;
use Monolog\Logger;
use Psr\SimpleCache\CacheInterface;

class UtilsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->initTempApp();

        mkdir(self::$tmpPath . '/users/alice', 0o755, true);
        mkdir(self::$tmpPath . '/users/bob', 0o755, true);
    }

    public function test_flat_array_empty(): void
    {
        self::assertSame([], flat_array([]));
    }

    public function test_flat_array_nested(): void
    {
        $input = [
            'user' => (object) [
                'name' => 'John',
                'address' => (object) ['city' => 'New York'],
            ],
            'items' => ['first', 'second'],
        ];

        self::assertSame([
            'user.name' => 'John',
            'user.address.city' => 'New York',
            'items.0' => 'first',
            'items.1' => 'second',
        ], flat_array($input));
    }

    public function test_flat_array_prefix(): void
    {
        $emptyObject = new \stdClass();

        $input = [
            'profile' => ['name' => 'John'],
            'settings' => [
                'enabled' => false,
                'count' => 0,
                'tags' => [],
            ],
            'empty' => $emptyObject,
        ];

        self::assertSame([
            'app::profile::name' => 'John',
            'app::settings::enabled' => false,
            'app::settings::count' => 0,
            'app::settings::tags' => [],
            'app::empty' => $emptyObject,
        ], flat_array($input, 'app', '::'));
    }

    public function test_validate_servers_valid(): void
    {
        $result = validate_servers_data([
            'plex_main' => $this->backendConfig('plex_main', [
                'export' => ['enabled' => 1, 'lastSync' => null],
                'import' => ['enabled' => true, 'lastSync' => '1234567890'],
                'options' => [
                    'LIBRARY_SEGMENT' => 500,
                    'ignore' => 'Library1,Library2',
                    'client' => [
                        'timeout' => 30,
                        'http_version' => 2.0,
                        'verify_host' => true,
                    ],
                ],
            ]),
        ]);

        self::assertTrue($result['valid']);
        self::assertArrayNotHasKey('errors', $result);
    }

    public function test_validate_servers_non_array(): void
    {
        $result = validate_servers_data(['plex_main' => 'not-an-array']);

        self::assertFalse($result['valid']);
        self::assertStringContainsString('must be an array', $result['errors'][0]);
    }

    public function test_validate_servers_name(): void
    {
        $result = validate_servers_data([
            'Invalid Name!' => $this->backendConfig('Invalid Name!'),
        ]);

        self::assertFalse($result['valid']);
        self::assertStringContainsString('Invalid Name!', $result['errors'][0]);
        self::assertStringContainsString('lowercase a-z, 0-9, _', $result['errors'][0]);
    }

    public function test_validate_servers_fields(): void
    {
        $result = validate_servers_data([
            'plex_main' => $this->backendConfig('plex_main', [
                'webhook' => 'http://example.com',
                'unknown_field' => 'value',
            ]),
        ]);

        $errors = implode("\n", $result['errors']);

        self::assertFalse($result['valid']);
        self::assertStringContainsString('webhook', $errors);
        self::assertStringContainsString('no longer supported', $errors);
        self::assertStringContainsString('unknown_field', $errors);
        self::assertStringContainsString('Unknown field', $errors);
    }

    public function test_validate_servers_type(): void
    {
        $result = validate_servers_data([
            'plex_main' => $this->backendConfig('plex_main', ['type' => 'kodi']),
        ]);

        $errors = implode("\n", $result['errors']);

        self::assertFalse($result['valid']);
        self::assertStringContainsString("Field 'type'", $errors);
        self::assertStringContainsString('plex, emby, jellyfin', $errors);
    }

    public function test_validate_servers_custom(): void
    {
        $result = validate_servers_data([
            'plex_main' => $this->backendConfig('plex_main', [
                'options' => ['LIBRARY_SEGMENT' => 250],
            ]),
        ]);

        $errors = implode("\n", $result['errors']);

        self::assertFalse($result['valid']);
        self::assertStringContainsString('LIBRARY_SEGMENT', $errors);
        self::assertStringContainsString('300', $errors);
    }

    public function test_validate_servers_scalars(): void
    {
        $result = validate_servers_data([
            'plex_main' => $this->backendConfig('plex_main', [
                'export' => ['enabled' => 'nope'],
                'import' => ['lastSync' => 'not_a_number'],
            ]),
        ]);

        $errors = implode("\n", $result['errors']);

        self::assertFalse($result['valid']);
        self::assertStringContainsString('export.enabled', $errors);
        self::assertStringContainsString('boolean', $errors);
        self::assertStringContainsString('import.lastSync', $errors);
        self::assertStringContainsString('integer', $errors);
    }

    public function test_validate_servers_immutable(): void
    {
        $result = validate_servers_data([
            'plex_main' => $this->backendConfig('plex_main'),
        ], ['validate_immutable' => true]);

        $errors = implode("\n", $result['errors']);

        self::assertFalse($result['valid']);
        self::assertStringContainsString("Field 'name' is immutable", $errors);
        self::assertStringContainsString("Field 'type' is immutable", $errors);
    }

    public function test_b64encode_urlsafe(): void
    {
        $cases = [
            'a' => 'YQ',
            'ab' => 'YWI',
            "\xff\xef" => '_-8',
        ];

        foreach ($cases as $input => $expected) {
            $encoded = urlsafe_b64encode($input);

            self::assertSame($expected, $encoded);
            self::assertStringNotContainsString('=', $encoded);
            self::assertStringNotContainsString('+', $encoded);
            self::assertStringNotContainsString('/', $encoded);
        }
    }

    public function test_b64decode_urlsafe(): void
    {
        $cases = [
            'YQ' => 'a',
            'YWI' => 'ab',
            'YWJj' => 'abc',
            strtr(base64_encode('test'), '+/', '-_') => 'test',
        ];

        foreach ($cases as $encoded => $expected) {
            self::assertSame($expected, urlsafe_b64decode($encoded));
        }
    }

    public function test_b64_roundtrip(): void
    {
        $cases = [
            'Hello World',
            'state:export --dry-run',
            "\x00\x7f\xffbinary",
        ];

        foreach ($cases as $original) {
            $encoded = urlsafe_b64encode($original);

            self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]*$/', $encoded);
            self::assertSame($original, urlsafe_b64decode($encoded));
        }
    }

    public function test_select_users(): void
    {
        self::assertSame(['main'], select_users('main'));
        self::assertSame(['alice'], select_users('alice'));
        self::assertEqualsCanonicalizing(['main', 'alice', 'bob'], select_users(null));
    }

    public function test_select_users_unknown(): void
    {
        $this->expectException(RuntimeException::class);
        select_users('carol');
    }

    public function test_get_user_context(): void
    {
        $logger = new Logger('test');
        $mapper = $this->createMapper($logger);

        $userContext = get_user_context('alice', $mapper, $logger);

        self::assertSame('alice', $userContext->name);
        self::assertSame('alice', $userContext->mapper->getOptions()[Options::ALT_NAME]);
        self::assertFileExists(self::$tmpPath . '/users/alice/' . PdoFactory::DB_FILE);
        self::assertFileDoesNotExist(self::$tmpPath . '/users/bob/' . PdoFactory::DB_FILE);
        self::assertFileDoesNotExist(self::$tmpPath . '/db/' . PdoFactory::DB_FILE);
    }

    public function test_get_users_context(): void
    {
        $logger = new Logger('test');
        $mapper = $this->createMapper($logger);

        $users = get_users_context($mapper, $logger, ['no_main_user' => true]);

        self::assertEqualsCanonicalizing(['alice', 'bob'], array_keys($users));
        self::assertSame('alice', $users['alice']->mapper->getOptions()[Options::ALT_NAME]);
        self::assertSame('bob', $users['bob']->mapper->getOptions()[Options::ALT_NAME]);
        self::assertFileExists(self::$tmpPath . '/users/alice/' . PdoFactory::DB_FILE);
        self::assertFileExists(self::$tmpPath . '/users/bob/' . PdoFactory::DB_FILE);
    }

    public function test_get_users_main_only(): void
    {
        $logger = new Logger('test');
        $mapper = $this->createMapper($logger);

        $users = get_users_context($mapper, $logger, ['main_user_only' => true]);

        self::assertSame(['main'], array_keys($users));
        self::assertSame('main', $users['main']->name);
    }

    public function test_exception_log_shape(): void
    {
        $e = new RuntimeException('Boom');

        $payload = exception_log($e);

        self::assertSame(RuntimeException::class, $payload['error']['type']);
        self::assertSame(RuntimeException::class, $payload['error']['kind']);
        self::assertSame('Boom', $payload['error']['message']);
        self::assertSame(after($e->getFile(), ROOT_PATH), $payload['error']['file']);
        self::assertSame($e->getLine(), $payload['error']['line']);
        self::assertSame(RuntimeException::class, $payload['exception']['type']);
        self::assertSame(RuntimeException::class, $payload['exception']['kind']);
        self::assertSame('Boom', $payload['exception']['message']);
        self::assertSame($e->getFile(), $payload['exception']['file']);
        self::assertSame($e->getLine(), $payload['exception']['line']);
        self::assertIsArray($payload['exception']['trace']);
    }

    private function createMapper(Logger $logger): DirectMapper
    {
        $db = $this->createDb($logger);
        $db->setOptions([
            Options::DEBUG_TRACE => true,
            'class' => new StateEntity([]),
        ]);

        return new DirectMapper(
            logger: $logger,
            db: $db,
            cache: Container::get(CacheInterface::class),
        );
    }

    private function backendConfig(string $name, array $overrides = []): array
    {
        return array_replace_recursive([
            'name' => $name,
            'type' => 'plex',
            'url' => 'http://localhost:32400',
            'token' => 'token',
            'uuid' => 'server-uuid',
            'user' => '1',
            'export' => ['enabled' => true, 'lastSync' => null],
            'import' => ['enabled' => true, 'lastSync' => null],
        ], $overrides);
    }
}
