<?php
/** @noinspection PhpUnhandledExceptionInspection */

declare(strict_types=1);

namespace Tests\Libs;

use App\Libs\Container;
use App\Libs\Database\PdoFactory;
use App\Libs\Entity\StateEntity;
use App\Libs\Events\DataEvent;
use App\Libs\Exceptions\RuntimeException;
use App\Libs\Mappers\Import\DirectMapper;
use App\Libs\Options;
use App\Libs\TestCase;
use App\Model\Events\Event as EventInfo;
use App\Model\Events\EventsRepository;
use App\Model\Events\EventsTable;
use App\Model\Events\EventStatus;
use Monolog\Logger;
use PDOException;
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

    public function test_queue_event_lock_fallback(): void
    {
        $cache = Container::get(CacheInterface::class);
        $cache->set('events', []);
        $modes = ['find', 'remove', 'save'];

        foreach ($modes as $mode) {
            $reference = 'ref://' . $mode;
            $item = new EventInfo([], true);
            $repo = $this->getMockBuilder(EventsRepository::class)
                ->disableOriginalConstructor()
                ->onlyMethods(['getObject', 'findByReference', 'remove', 'save'])
                ->getMock();

            $repo->expects($this->once())
                ->method('getObject')
                ->with([])
                ->willReturn($item);

            $optsMatcher = $this->callback(static function (array $opts) use ($reference): bool {
                return true === $opts[Options::FAIL_FAST_ON_LOCK]
                    && 'alice' === $opts[Options::CONTEXT_USER]
                    && $reference === $opts[EventsTable::COLUMN_REFERENCE]
                    && true === $opts['unique'];
            });

            if ('find' === $mode) {
                $repo->expects($this->once())
                    ->method('findByReference')
                    ->with($reference, [], $optsMatcher)
                    ->willThrowException(new PDOException('database is locked'));

                $repo->expects($this->never())->method('remove');
                $repo->expects($this->never())->method('save');
            }

            if ('remove' === $mode) {
                $existing = new EventInfo([], true);

                $repo->expects($this->once())
                    ->method('findByReference')
                    ->with($reference, [], $optsMatcher)
                    ->willReturn($existing);

                $repo->expects($this->once())
                    ->method('remove')
                    ->with($existing, $optsMatcher)
                    ->willThrowException(new PDOException('database is locked'));

                $repo->expects($this->never())->method('save');
            }

            if ('save' === $mode) {
                $repo->expects($this->once())
                    ->method('findByReference')
                    ->with($reference, [], $optsMatcher)
                    ->willReturn(null);

                $repo->expects($this->never())->method('remove');
                $repo->expects($this->once())
                    ->method('save')
                    ->with(
                        $this->callback(static function (EventInfo $event) use ($reference): bool {
                            return 'process_request' === $event->event
                                && EventStatus::PENDING === $event->status
                                && $reference === $event->reference
                                && ['ok' => true] === $event->event_data
                                && DataEvent::class === $event->options['class']
                                && 'alice' === $event->options[Options::CONTEXT_USER];
                        }),
                        $optsMatcher,
                    )
                    ->willThrowException(new PDOException('database is locked'));
            }

            $queued = queue_event('process_request', ['ok' => true], [
                EventsRepository::class => $repo,
                'unique' => true,
                EventsTable::COLUMN_REFERENCE => $reference,
                Options::FAIL_FAST_ON_LOCK => true,
                Options::CONTEXT_USER => 'alice',
            ]);

            self::assertSame('process_request', $queued->event);
            self::assertSame(EventStatus::PENDING, $queued->status);
            self::assertSame($reference, $queued->reference);
            self::assertSame(['ok' => true], $queued->event_data);
            self::assertSame(DataEvent::class, $queued->options['class']);
            self::assertSame('alice', $queued->options[Options::CONTEXT_USER]);
            self::assertTrue($queued->options[Options::FAIL_FAST_ON_LOCK]);
        }

        $events = $cache->get('events', []);

        self::assertCount(3, $events);

        foreach ($events as $index => $event) {
            self::assertSame('process_request', $event['event']);
            self::assertSame(['ok' => true], $event['data']);
            self::assertTrue($event['opts']['cached']);
            self::assertTrue($event['opts'][Options::FAIL_FAST_ON_LOCK]);
            self::assertSame('alice', $event['opts'][Options::CONTEXT_USER]);
            self::assertSame('ref://' . $modes[$index], $event['opts'][EventsTable::COLUMN_REFERENCE]);
            self::assertArrayNotHasKey(EventsRepository::class, $event['opts']);
        }
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
