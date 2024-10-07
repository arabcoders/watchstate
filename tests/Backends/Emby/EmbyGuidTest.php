<?php
/** @noinspection PhpUnhandledExceptionInspection */

declare(strict_types=1);

namespace Tests\Backends\Emby;

use App\Backends\Common\Cache;
use App\Backends\Common\Context;
use App\Backends\Emby\EmbyClient;
use App\Backends\Emby\EmbyGuid;
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

class EmbyGuidTest extends TestCase
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

    private function getClass(): EmbyGuid
    {
        $this->handler->clear();
        return (new EmbyGuid($this->logger))->withContext(
            new Context(
                clientName: EmbyClient::CLIENT_NAME,
                backendName: 'test_emby',
                backendUrl: new Uri('http://127.0.0.1:8096'),
                cache: new Cache($this->logger, new Psr16Cache(new ArrayAdapter())),
                logger: $this->logger,
                backendId: 's000000000000000000000000000000e',
                backendToken: 't000000000000000000000000000000e',
                backendUser: 'u000000000000000000000000000000e',
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
                    file_put_contents($tmpFile, 'version: 2.0');
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
            $yaml = ['emby' => []];
            file_put_contents($tmpFile, Yaml::dump($yaml));
            $this->assertCount(0, $this->handler->getRecords(), "There should be no messages logged for empty list.");
            $this->handler->clear();


            file_put_contents($tmpFile, Yaml::dump(ag_set($yaml, 'links.0', 'ff')));
            $this->getClass()->parseGUIDFile($tmpFile);
            $this->assertTrue(
                $this->logged(Level::Warning, 'Value must be an object.', true),
                'Assert links.0 key is an object.'
            );
        } finally {
            if (file_exists($tmpFile)) {
                unlink($tmpFile);
            }
        }

        $this->handler->clear();

        $tmpFile = tempnam(sys_get_temp_dir(), 'guid');
        try {
            $yaml = ag_set(['links' => [['type' => 'emby']]], 'links.0.map', 'foo');
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
                'from' => 'tsdb',
                'to' => Guid::GUID_IMDB,
            ]);

            $this->handler->clear();

            file_put_contents($tmpFile, Yaml::dump($yaml));
            $class = $this->getClass();
            $class->parseGUIDFile($tmpFile);
            $this->assertArrayHasKey(
                'tsdb',
                ag($class->getConfig(), 'guidMapper', []),
                'Assert that the GUID mapping has been added.'
            );
            $this->handler->clear();

            $yaml = ag_set($yaml, 'links.0.map', [
                'from' => 'imthedb',
                'to' => 'guid_imdb',
            ]);

            file_put_contents($tmpFile, Yaml::dump($yaml));
            $class = $this->getClass();
            $class->parseGUIDFile($tmpFile);

            $this->assertArrayHasKey(
                'imthedb',
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
        $this->assertFalse(
            $this->getClass()->isLocal('test://123456/1/1'),
            'Should always return false, as emby does not have local GUIDs.'
        );
    }

    public function test_has()
    {
        $context = [
            'item' => [
                'id' => 123,
                'type' => EmbyClient::TYPE_EPISODE,
                'title' => 'Test title',
                'year' => 2021
            ]
        ];

        $this->assertTrue($this->getClass()->has([
            'imdb' => '123456',
            'tvdb' => '123456',
        ], $context), 'Assert that the GUID exists.');

        $this->assertFalse($this->getClass()->has([
            ['none' => '123456'],
            ['imdb' => ''],
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
                'imdb' => '123456',
                'tmdb' => '123456',
                'anidb' => '123456',
            ], $context),
            'Assert that the GUID exists.'
        );

        $this->assertEquals([], $this->getClass()->parse([
            '' => '',
            'none' => '123456',
            'imdb' => ''
        ], $context), 'Assert that the GUID does not exist. for invalid GUIDs.');
    }

    public function test_get()
    {
        $context = ['item' => ['id' => 123, 'type' => 'episode', 'title' => 'Test title', 'year' => 2021]];

        $this->assertEquals([], $this->getClass()->get([
            ['imdb' => ''],
        ], $context), 'Assert invalid guid return empty array.');

        $this->assertEquals([Guid::GUID_IMDB => '1', Guid::GUID_CMDB => 'afa', Guid::GUID_TVDB => '123'],
            $this->getClass()->get([
                'imdb' => '1',
                'cmdb' => 'afa',
                'tvdb' => '123',
                'none' => '123',
            ], $context),
            'Assert only the the oldest ID is returned for numeric GUIDs.'
        );
    }

    public function test_get_ignore()
    {
        $context = [
            'item' => [
                'id' => 123,
                'type' => EmbyClient::TYPE_SHOW,
                'title' => 'Test title',
                'year' => 2021
            ]
        ];
        Config::save('ignore', [(string)makeIgnoreId('show://imdb:123@test_emby') => 1]);

        $this->assertEquals([],
            $this->getClass()->get(['imdb' => '123'], $context),
            'Assert only the the oldest ID is returned for numeric GUIDs.');

        $this->assertTrue(
            $this->logged(Level::Debug, 'EmbyGuid: Ignoring', true),
            'Assert that a log is raised when the GUID is ignored by user choice.'
        );
    }
}
