<?php
/** @noinspection PhpUnhandledExceptionInspection */

declare(strict_types=1);

namespace Tests\Backends\Plex;

use App\Backends\Common\Cache;
use App\Backends\Common\Context;
use App\Backends\Plex\PlexClient;
use App\Backends\Plex\PlexGuid;
use App\Libs\Config;
use App\Libs\Exceptions\Backends\InvalidArgumentException;
use App\Libs\Extends\LogMessageProcessor;
use App\Libs\Guid;
use App\Libs\TestCase;
use App\Libs\Uri;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Psr16Cache;
use Symfony\Component\Yaml\Yaml;

class PlexGuidTest extends TestCase
{
    protected Logger|null $logger = null;

    private function logged(Level $level, string $message, bool $clear = false): bool
    {
        try {
            foreach ($this->handler->getRecords() as $record) {
                if ($level !== $record->level) {
                    continue;
                }

                if (null !== $record->formatted && true === str_contains($record->formatted, $message)) {
                    return true;
                }

                if (true === str_contains($record->message, $message)) {
                    return true;
                }
            }

            return false;
        } finally {
            if (true === $clear) {
                $this->handler->clear();
            }
        }
    }

    private function getClass(): PlexGuid
    {
        $this->handler->clear();
        return new PlexGuid($this->logger)->withContext(
            new Context(
                clientName: PlexClient::CLIENT_NAME,
                backendName: 'test_plex',
                backendUrl: new Uri('http://127.0.0.1:34000'),
                cache: new Cache($this->logger, new Psr16Cache(new ArrayAdapter())),
                userContext: $this->createUserContext(PlexClient::CLIENT_NAME),
                logger: $this->logger,
                backendId: 's00000000000000000000000000000000000000p',
                backendToken: 't000000000000000000p',
                backendUser: '11111111',
            )
        );
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->handler = new TestHandler();
        $this->logger = new Logger('logger', processors: [new LogMessageProcessor()]);
        $this->logger->pushHandler($this->handler);

        Guid::setLogger($this->logger);
    }

    public function test__construct()
    {
        $oldGuidFile = Config::get('guid.file');

        $tmpFile = tempnam(sys_get_temp_dir(), 'guid');
        try {
            file_put_contents($tmpFile, "{'foo' => 'too' }");
            Config::save('guid.file', $tmpFile);
            $this->getClass();
            $this->assertTrue(
                $this->logged(Level::Error, 'Failed to parse GUIDs file', true),
                "Assert message logged when the value type does not match the expected type."
            );
        } finally {
            if (file_exists($tmpFile)) {
                unlink($tmpFile);
            }
            Config::save('guid.file', $oldGuidFile);
        }
    }

    public function test_parseGUIDFile()
    {
        Config::save('guid.file', null);

        $tmpFile = tempnam(sys_get_temp_dir(), 'guid');
        try {
            $this->checkException(
                closure: function () use ($tmpFile) {
                    file_put_contents($tmpFile, 'version: 99.0');
                    $this->getClass()->parseGUIDFile($tmpFile);
                },
                reason: "Failed to throw exception when the GUID file version is not supported.",
                exception: InvalidArgumentException::class,
                exceptionMessage: 'Unsupported file version'
            );
        } finally {
            if (file_exists($tmpFile)) {
                unlink($tmpFile);
            }
        }

        $this->checkException(
            closure: fn() => $this->getClass()->parseGUIDFile('not_set.yml'),
            reason: "Failed to assert that the GUID file is not found.",
            exception: InvalidArgumentException::class,
            exceptionMessage: 'does not exist'
        );

        $tmpFile = tempnam(sys_get_temp_dir(), 'guid');
        try {
            $this->checkException(
                closure: function () use ($tmpFile) {
                    file_put_contents($tmpFile, 'fff: {_]');
                    $this->getClass()->parseGUIDFile($tmpFile);
                },
                reason: "Failed to throw exception when the GUID file is invalid.",
                exception: InvalidArgumentException::class,
                exceptionMessage: 'Failed to parse GUIDs file'
            );
        } finally {
            if (file_exists($tmpFile)) {
                unlink($tmpFile);
            }
        }

        $tmpFile = tempnam(sys_get_temp_dir(), 'guid');
        try {
            $this->checkException(
                closure: function () use ($tmpFile) {
                    file_put_contents($tmpFile, 'invalid');
                    $this->getClass()->parseGUIDFile($tmpFile);
                },
                reason: "Failed to throw exception when the GUID file is invalid.",
                exception: InvalidArgumentException::class,
                exceptionMessage: 'is not an array'
            );
        } finally {
            if (file_exists($tmpFile)) {
                unlink($tmpFile);
            }
        }

        $tmpFile = tempnam(sys_get_temp_dir(), 'guid');
        try {
            $this->getClass()->parseGUIDFile($tmpFile);
            $this->assertTrue(
                $this->logged(Level::Info, 'is empty', true),
                "Failed to assert that the GUID file is empty."
            );
        } finally {
            if (file_exists($tmpFile)) {
                unlink($tmpFile);
            }
        }

        $tmpFile = tempnam(sys_get_temp_dir(), 'guid');
        try {
            $this->checkException(
                closure: function () use ($tmpFile) {
                    file_put_contents($tmpFile, Yaml::dump(['links' => 'foo']));
                    $this->getClass()->parseGUIDFile($tmpFile);
                },
                reason: "Should throw an exception when there are no GUIDs mapping.",
                exception: InvalidArgumentException::class,
                exceptionMessage: 'links sub key is not an array'
            );
        } finally {
            if (file_exists($tmpFile)) {
                unlink($tmpFile);
            }
        }

        $tmpFile = tempnam(sys_get_temp_dir(), 'guid');
        try {
            $this->handler->clear();
            $yaml = ['links' => [['type' => 'plex']]];
            file_put_contents($tmpFile, Yaml::dump($yaml));
            $this->getClass()->parseGUIDFile($tmpFile);
            $this->assertCount(0, $this->handler->getRecords(), "There should be no messages logged for empty list.");
            $this->handler->clear();


            file_put_contents($tmpFile, Yaml::dump(ag_set($yaml, 'links.0', 'ff')));
            $this->getClass()->parseGUIDFile($tmpFile);
            $this->assertTrue(
                $this->logged(Level::Warning, 'Value must be an object.', true),
                'Assert link value is an object.'
            );

            $yaml = ag_set($yaml, 'links.0.options.replace', 'foo');
            file_put_contents($tmpFile, Yaml::dump($yaml));
            $this->getClass()->parseGUIDFile($tmpFile);
            $this->assertTrue(
                $this->logged(Level::Warning, 'replace value must be an object.', true),
                'Assert replace key is an object.'
            );

            $yaml = ag_set($yaml, 'links.0.options.replace', []);
            file_put_contents($tmpFile, Yaml::dump($yaml));
            $this->getClass()->parseGUIDFile($tmpFile);
            $this->assertTrue(
                $this->logged(Level::Warning, 'options.replace.from field is empty or not a string.', true),
                'Assert to field is a string.'
            );

            $yaml = ag_set($yaml, 'links.0.options.replace.from', 'foo');
            file_put_contents($tmpFile, Yaml::dump($yaml));
            $this->getClass()->parseGUIDFile($tmpFile);
            $this->assertTrue(
                $this->logged(Level::Warning, 'options.replace.to field is not a string.', true),
                'Assert to field is a string.'
            );

            $yaml = ag_set($yaml, 'links.0.options.replace.to', 'bar');
            file_put_contents($tmpFile, Yaml::dump($yaml));
            $this->getClass()->parseGUIDFile($tmpFile);
            $this->assertCount(0, $this->handler->getRecords(), "There should be no error messages logged.");
        } finally {
            if (file_exists($tmpFile)) {
                unlink($tmpFile);
            }
        }

        $this->handler->clear();

        $tmpFile = tempnam(sys_get_temp_dir(), 'guid');
        try {
            $yaml = ag_set(['links' => [['type' => 'plex']]], 'links.0.map', 'foo');
            file_put_contents($tmpFile, Yaml::dump($yaml));
            $this->getClass()->parseGUIDFile($tmpFile);
            $this->assertTrue(
                $this->logged(Level::Warning, 'map value must be an object.', true),
                'Assert map key is an object.'
            );

            $yaml = ag_set($yaml, 'links.0.map', []);
            file_put_contents($tmpFile, Yaml::dump($yaml));
            $this->getClass()->parseGUIDFile($tmpFile);
            $this->assertTrue(
                $this->logged(Level::Warning, 'map.from field is empty or not a string.', true),
                'Assert to field is a string.'
            );

            $yaml = ag_set($yaml, 'links.0.map.from', 'foo');
            file_put_contents($tmpFile, Yaml::dump($yaml));
            $this->getClass()->parseGUIDFile($tmpFile);
            $this->assertTrue(
                $this->logged(Level::Warning, 'map.to field is empty or not a string.', true),
                'Assert to field is a string.'
            );

            $yaml = ag_set($yaml, 'links.0.map.to', 'foobar');
            file_put_contents($tmpFile, Yaml::dump($yaml));
            $this->getClass()->parseGUIDFile($tmpFile);
            $this->assertTrue(
                $this->logged(Level::Warning, 'field does not starts with', true),
                'Assert to field is a string.'
            );

            $yaml = ag_set($yaml, 'links.0.map.to', 'guid_foobar');
            file_put_contents($tmpFile, Yaml::dump($yaml));
            $this->getClass()->parseGUIDFile($tmpFile);
            $this->assertTrue(
                $this->logged(Level::Warning, 'map.to field is not a supported', true),
                'Assert to field is a string.'
            );

            $yaml = ag_set($yaml, 'links.0.map', [
                'from' => 'com.plexapp.agents.imdb',
                'to' => 'guid_imdb',
            ]);
            file_put_contents($tmpFile, Yaml::dump($yaml));
            $this->getClass()->parseGUIDFile($tmpFile);
            $this->assertTrue(
                $this->logged(Level::Warning, 'map.from already exists.', true),
                'Assert to field is a string.'
            );

            $yaml = ag_set($yaml, 'links.0.map', [
                'from' => 'com.plexapp.agents.ccdb',
                'to' => 'guid_imdb',
            ]);

            $this->handler->clear();

            file_put_contents($tmpFile, Yaml::dump($yaml));
            $class = $this->getClass();
            $class->parseGUIDFile($tmpFile);
            $this->assertArrayHasKey(
                'ccdb',
                ag($class->getConfig(), 'guidMapper', []),
                'Assert that the GUID mapping has been added.'
            );
            $this->handler->clear();

            $yaml = ag_set($yaml, 'links.0', [
                'type' => 'plex',
                'options' => [
                    'legacy' => false,
                ],
                'map' => [
                    'from' => 'com.plexapp.agents.imthedb',
                    'to' => 'guid_imdb',
                ]
            ]);
            file_put_contents($tmpFile, Yaml::dump($yaml));
            $class = $this->getClass();
            $class->parseGUIDFile($tmpFile);
            $this->assertArrayHasKey(
                'com.plexapp.agents.imthedb',
                ag($class->getConfig(), 'guidMapper', []),
                'Assert that the GUID mapping has been added.'
            );
        } finally {
            if (file_exists($tmpFile)) {
                unlink($tmpFile);
            }
        }
    }

    public function test_isLocal()
    {
        $this->assertTrue(
            $this->getClass()->isLocal('com.plexapp.agents.none://123456/1/1'),
            'Assert that the GUID is local.'
        );
        $this->assertFalse(
            $this->getClass()->isLocal('com.plexapp.agents.imdb://123456/1/1'),
            'Assert that the GUID is not local.'
        );
    }

    public function test_has()
    {
        $context = ['item' => ['id' => 123, 'type' => 'episode', 'title' => 'Test title', 'year' => 2021]];

        $this->assertTrue($this->getClass()->has([
            ['id' => 'com.plexapp.agents.imdb://123456'],
            ['id' => 'com.plexapp.agents.tvdb://123456'],
        ], $context), 'Assert that the GUID exists.');

        $this->assertFalse($this->getClass()->has([
            ['id' => ''],
            ['id' => 'com.plexapp.agents.none://123456'],
            ['id' => 'com.plexapp.agents.imdb'],
        ], $context), 'Assert that the GUID does not exist.');
    }

    public function test_parse()
    {
        $context = [
            'item' => [
                'id' => 123,
                'type' => 'episode',
                'title' => 'Test title',
                'year' => 2021,
            ],
        ];

        $this->assertEquals([
            Guid::GUID_IMDB => '123456',
            Guid::GUID_TMDB => '123456',
            Guid::GUID_ANIDB => '123456',
        ],
            $this->getClass()->parse([
                ['id' => 'com.plexapp.agents.imdb://123456'],
                ['id' => 'com.plexapp.agents.tmdb://123456'],
                ['id' => 'com.plexapp.agents.hama://anidb-123456'],
            ], $context),
            'Assert that the GUID exists.');

        $this->assertEquals([], $this->getClass()->parse([
            ['id' => ''],
            ['id' => 'com.plexapp.agents.none://123456'],
            ['id' => 'com.plexapp.agents.imdb'],
        ], $context), 'Assert that the GUID does not exist. for invalid GUIDs.');
    }

    public function test_get()
    {
        $context = ['item' => ['id' => 123, 'type' => 'episode', 'title' => 'Test title', 'year' => 2021]];

        $this->assertEquals([], $this->getClass()->get([
            ['id' => 'com.plexapp.agents.imdb'],
        ], $context), 'Assert invalid guid return empty array.');

        $this->assertTrue(
            $this->logged(Level::Info, 'Unable to parse', true),
            'Assert that the invalid GUID is logged.'
        );
        $this->assertEquals([Guid::GUID_IMDB => '1', Guid::GUID_CMDB => 'afa', Guid::GUID_TVDB => '123'],
            $this->getClass()->get([
                ['id' => 'com.plexapp.agents.imdb://2'],
                ['id' => 'com.plexapp.agents.imdb://1'],
                ['id' => 'com.plexapp.agents.cmdb://afa'],
                ['id' => 'com.plexapp.agents.cmdb://faf'],
                ['id' => 'com.plexapp.agents.hama://tvdb-123'],
                ['id' => 'com.plexapp.agents.hama://notSet-123'],
                ['id' => 'com.plexapp.agents.hama://notSet-'],
            ], $context),
            'Assert only the the oldest ID is returned for numeric GUIDs.'
        );

        $this->assertTrue(
            $this->logged(Level::Warning, 'reported multiple ids', true),
            'Assert that a log is raised when multiple GUIDs for the same provider are found.'
        );

        $this->assertEquals([Guid::GUID_IMDB => '1'], $this->getClass()->get([
            ['id' => 'com.plexapp.agents.imdb://1'],
            ['id' => 'com.plexapp.agents.imdb://2'],
        ], $context), 'Assert only the the oldest ID is returned for numeric GUIDs.');

        // -- as we cache the ignore list for each user now,
        // -- and no longer rely on config.ignore key, we needed a workaround to update the ignore list
        is_ignored_id(
            userContext: $this->createUserContext(PlexClient::CLIENT_NAME),
            backend: 'test_plex',
            type: 'show',
            db: 'imdb',
            id: '123',
            opts: [
                'reset' => true,
                'list' => [
                    (string)make_ignore_id('show://imdb:123@test_plex') => 1
                ]
            ]
        );

        $this->assertEquals([],
            $this->getClass()->get([
                ['id' => 'com.plexapp.agents.imdb://123'],
            ], ag_set($context, 'item.type', 'show')),
            'Assert only the the oldest ID is returned for numeric GUIDs.');

        $this->assertTrue(
            $this->logged(Level::Debug, 'PlexGuid: Ignoring', true),
            'Assert that a log is raised when the GUID is ignored by user choice.'
        );
    }
}
