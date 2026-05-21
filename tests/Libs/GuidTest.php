<?php

declare(strict_types=1);

namespace Tests\Libs;

use App\Libs\Config;
use App\Libs\Container;
use App\Libs\Exceptions\InvalidArgumentException;
use App\Libs\Extends\LogMessageProcessor;
use App\Libs\Guid;
use App\Libs\TestCase;
use Monolog\Handler\TestHandler;
use Monolog\LogRecord;
use Monolog\Logger;
use Psr\Log\LoggerInterface as iLogger;
use Symfony\Component\Yaml\Yaml;

class GuidTest extends TestCase
{
    protected Logger|null $logger = null;

    private function record(string $eventName, ?string $reason = null): ?LogRecord
    {
        foreach (array_reverse($this->handler->getRecords()) as $record) {
            if ($eventName !== ($record->context['event_name'] ?? null)) {
                continue;
            }

            if (null !== $reason && $reason !== ($record->context['reason'] ?? null)) {
                continue;
            }

            return $record;
        }

        return null;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->initTempDir();

        $this->handler = new TestHandler();
        $this->logger = new Logger('logger', processors: [new LogMessageProcessor()]);
        $this->logger->pushHandler($this->handler);

        Container::init();
        Container::add(iLogger::class, $this->logger);
        Guid::reparse();
        Guid::setLogger($this->logger);
    }

    public function test__construct()
    {
        $guid = Guid::fromArray(['guid_test' => 'ztt1234567']);
        $this->assertCount(0, $guid->getAll(), "Count should be 0 when the GUID is not supported.");

        $guid = Guid::fromArray(['guid_imdb' => null]);
        $this->assertCount(0, $guid->getAll(), "Count should be 0 when value of guid is null.");

        Guid::fromArray(['guid_tvdb' => INF], logger: $this->logger);
        $record = $this->record('guid.external_id.ignored', 'unexpected_value_type');
        $this->assertNotNull($record, 'Assert invalid external-id types are logged with stable context.');
        $this->assertSame('guid_tvdb', $record->context['guid_source'] ?? null);
        $this->handler->clear();

        Guid::fromArray(['guid_tvdb' => 'tt1234567']);
        $record = $this->record('guid.external_id.ignored', 'invalid_guid');
        $this->assertNotNull($record, 'Assert invalid external-id values are logged with stable context.');
        $this->assertSame('tt1234567', $record->context['guid_value'] ?? null);
        $this->handler->clear();
    }

    public function test_validation()
    {
        foreach (Guid::getValidators() as $guid => $validator) {
            foreach (ag($validator, 'tests.valid', []) as $value) {
                $this->assertTrue(
                    Guid::validate($guid, $value),
                    r("Failed to assert that '{value} test for '{guid}' returns true.", [
                        'guid' => $guid,
                        'value' => $value,
                    ])
                );
            }

            foreach (ag($validator, 'tests.invalid', []) as $value) {
                $this->checkException(
                    closure: fn() => Guid::validate($guid, $value),
                    reason: r("Failed to assert that invalid '{value}' test for '{guid}' throws an exception.", [
                        'guid' => $guid,
                        'value' => $value,
                    ]),
                    exception: InvalidArgumentException::class
                );
            }
        }

        $this->checkException(
            closure: fn() => Guid::validate('guid_not_set', '12345678'),
            reason: 'Failed to assert that an exception is thrown when the GUID is not supported.',
            exception: InvalidArgumentException::class,
            exceptionMessage: 'Invalid db'
        );

        $this->assertTrue(
            Guid::validate('guid_cmdb', '12345678'),
            'Assert supported guid with no validator returns true.'
        );
    }

    public function test_jsonSerialize()
    {
        Guid::reparse();
        $guid = Guid::fromArray(['guid_imdb' => 'tt1234567', 'guid_tvdb' => '123']);
        $this->assertJsonStringEqualsJsonString(
            json_encode(['guid_imdb' => 'tt1234567', 'guid_tvdb' => '123',]),
            json_encode($guid),
            "Failed to assert that the JSON serialization of the Guid object is correct."
        );
    }

    public function test__toString()
    {
        $guid = Guid::fromArray(['guid_imdb' => 'tt1234567', 'guid_tvdb' => '123']);
        $this->assertJsonStringEqualsJsonString(
            json_encode(['guid_imdb' => 'tt1234567', 'guid_tvdb' => '123',]),
            (string)$guid,
            "Failed to assert that the string representation of the Guid object is correct. {records}"
        );
    }

    public function test_parseGUIDFile()
    {
        $this->checkException(
            closure: fn() => Guid::parseGUIDFile('not_set.yml'),
            reason: "Failed to assert that the GUID file is not found.",
            exception: InvalidArgumentException::class,
            exceptionMessage: 'does not exist'
        );

        $tmpFile = self::$tmpPath . '/guid_' . uniqid();
        $this->checkException(
            closure: function () use ($tmpFile) {
                file_put_contents($tmpFile, 'fff: {_]');
                Guid::parseGUIDFile($tmpFile);
            },
            reason: "Failed to throw exception when the GUID file is invalid.",
            exception: InvalidArgumentException::class,
            exceptionMessage: 'Failed to parse GUIDs file'
        );

        $tmpFile = self::$tmpPath . '/guid_' . uniqid();
        $this->checkException(
            closure: function () use ($tmpFile) {
                file_put_contents($tmpFile, 'invalid');
                Guid::parseGUIDFile($tmpFile);
            },
            reason: "Failed to throw exception when the GUID file is invalid.",
            exception: InvalidArgumentException::class,
            exceptionMessage: 'is not an array'
        );

        $tmpFile = self::$tmpPath . '/guid_' . uniqid();
        $this->checkException(
            closure: function () use ($tmpFile) {
                file_put_contents($tmpFile, 'version: 2.0');
                Guid::parseGUIDFile($tmpFile);
            },
            reason: "Failed to throw exception when the GUID file version is not supported.",
            exception: InvalidArgumentException::class,
            exceptionMessage: 'Unsupported file version'
        );

        $tmpFile = self::$tmpPath . '/guid_' . uniqid();
        touch($tmpFile);
        Guid::parseGUIDFile($tmpFile);
        $record = $this->record('guid.mapping.ignored', 'empty_file');
        $this->assertNotNull($record, 'Failed to assert that the GUID file is empty.');
        $this->assertSame($tmpFile, $record->context['file'] ?? null);
        $this->handler->clear();

        $tmpFile = self::$tmpPath . '/guid_' . uniqid();
        $this->checkException(
            closure: function () use ($tmpFile) {
                file_put_contents($tmpFile, Yaml::dump(['guids' => []]));
                Guid::parseGUIDFile($tmpFile);
            },
            reason: "Should throw an exception when there are no GUIDs mapping.",
            exception: InvalidArgumentException::class,
            exceptionMessage: 'does not contain any GUIDs mapping'
        );

        $tmpFile = self::$tmpPath . '/guid_' . uniqid();
        file_put_contents($tmpFile, Yaml::dump(['guids' => ['guid_imdb' => 'tt1234567']]));
        Guid::parseGUIDFile($tmpFile);
        $record = $this->record('guid.mapping.ignored', 'invalid_link_value');
        $this->assertNotNull($record, 'Assert that GUID key is an array.');
        $this->assertSame('guids.guid_imdb', $record->context['mapping_key'] ?? null);
        $this->handler->clear();

        file_put_contents($tmpFile, Yaml::dump(['guids' => [['name' => 'imdb']]]));
        Guid::parseGUIDFile($tmpFile);
        $record = $this->record('guid.mapping.ignored', 'invalid_guid_type_name');
        $this->assertNotNull($record, 'Assert that GUID name starts with guid_.');
        $this->assertSame('imdb', $record->context['mapping_to'] ?? null);
        $this->handler->clear();

        file_put_contents($tmpFile, Yaml::dump(['guids' => [['name' => 'guid_imdb', 'type' => INF]]]));
        Guid::parseGUIDFile($tmpFile);
        $record = $this->record('guid.mapping.ignored', 'invalid_map_value');
        $this->assertNotNull($record, 'Assert guid type is string.');
        $this->assertSame('guid_imdb', $record->context['mapping_to'] ?? null);
        $this->handler->clear();

        $yaml = [
            'guids' => [
                [
                    'name' => 'guid_foobar',
                    'type' => 'string',
                    'validator' => []
                ]
            ]
        ];

        file_put_contents($tmpFile, Yaml::dump($yaml));

        Guid::parseGUIDFile($tmpFile);
        $record = $this->record('guid.mapping.ignored', 'invalid_map_value');
        $this->assertNotNull($record, 'Assert validator key is an object.');
        $this->assertSame('guid_foobar', $record->context['mapping_to'] ?? null);
        $this->handler->clear();

        $yaml = ag_set($yaml, 'guids.0.validator', ['pattern' => '\d']);
        file_put_contents($tmpFile, Yaml::dump($yaml));
        Guid::parseGUIDFile($tmpFile);
        $record = $this->record('guid.mapping.ignored', 'invalid_map_from');
        $this->assertNotNull($record, 'Assert a message is logged when the pattern is invalid.');
        $this->handler->clear();

        $yaml = ag_set($yaml, 'guids.0.validator', ['pattern' => '/^\d+$/']);
        file_put_contents($tmpFile, Yaml::dump($yaml));
        Guid::parseGUIDFile($tmpFile);
        $record = $this->record('guid.mapping.ignored', 'invalid_map_to');
        $this->assertNotNull($record, 'Assert a message is logged when the example is empty or not a string.');
        $this->handler->clear();

        $yaml = ag_set($yaml, 'guids.0.validator', ['pattern' => '/^\d+$/', 'example' => '(number)']);
        file_put_contents($tmpFile, Yaml::dump($yaml));
        Guid::parseGUIDFile($tmpFile);
        $record = $this->record('guid.mapping.ignored', 'invalid_map_value');
        $this->assertNotNull($record, 'Assert a message is logged when the test key is not an object.');
        $this->handler->clear();

        $yaml = ag_set($yaml, 'guids.0.validator.tests', ['valid' => 'foo']);
        file_put_contents($tmpFile, Yaml::dump($yaml));
        Guid::parseGUIDFile($tmpFile);
        $record = $this->record('guid.mapping.ignored', 'invalid_map_from');
        $this->assertNotNull($record, 'Assert a message is logged when the test key is not an object.');
        $this->handler->clear();

        $yaml = ag_set($yaml, 'guids.0.validator.tests.valid', ['d12345678']);
        file_put_contents($tmpFile, Yaml::dump($yaml));
        Guid::parseGUIDFile($tmpFile);
        $record = $this->record('guid.mapping.ignored', 'invalid_map_from');
        $this->assertNotNull($record, 'Assert a message is logged when valid test does not match the pattern.');
        $this->assertSame('d12345678', $record->context['mapping_from'] ?? null);
        $this->handler->clear();

        $yaml = ag_set($yaml, 'guids.0.validator.tests', [
            'valid' => ['12345678'],
            'invalid' => 'foo',
        ]);
        file_put_contents($tmpFile, Yaml::dump($yaml));
        Guid::parseGUIDFile($tmpFile);
        $record = $this->record('guid.mapping.ignored', 'invalid_map_to');
        $this->assertNotNull($record, 'Assert a message is logged when invalid test is not an array.');
        $this->handler->clear();

        $yaml = ag_set($yaml, 'guids.0.validator.tests', [
            'valid' => ['12345678'],
            'invalid' => ['12345678'],
        ]);
        file_put_contents($tmpFile, Yaml::dump($yaml));
        Guid::parseGUIDFile($tmpFile);
        $record = $this->record('guid.mapping.ignored', 'invalid_map_to');
        $this->assertNotNull($record, 'Assert a message is logged when invalid test match the pattern.');
        $this->assertSame('12345678', $record->context['mapping_from'] ?? null);
        $this->handler->clear();

        $yaml = ag_set($yaml, 'guids.0.validator.tests', [
            'valid' => ['12345678'],
            'invalid' => ['d12345678'],
        ]);

        file_put_contents($tmpFile, Yaml::dump($yaml));
        Guid::parseGUIDFile($tmpFile);

        $this->assertArrayHasKey(
            'guid_foobar',
            Guid::getValidators(),
            'Assert that the GUID is added to the validators.'
        );
        $this->assertArrayHasKey(
            'guid_foobar',
            Guid::getSupported(),
            'Assert that the GUID is added to the supported GUIDs.'
        );
    }

    public function test_reparse()
    {
        $tmpFile = self::$tmpPath . '/guid_' . uniqid();
        $oldGuidFile = Config::get('guid.file');
        try {
            file_put_contents($tmpFile, "{'foo' => 'too' }");
            Config::save('guid.file', $tmpFile);
            Guid::reparse();
            Guid::getSupported();
            $record = $this->record('guid.file.parse_failed');
            $this->assertNotNull($record, 'Failed to assert that GUID parse failures are logged.');
            $this->assertSame($tmpFile, $record->context['file'] ?? null);
        } finally {
            Config::save('guid.file', $oldGuidFile);
            Guid::reparse();
        }
    }

    public function test_getPointers()
    {
        $guid = Guid::fromArray(['guid_imdb' => 'tt1234567', 'guid_tvdb' => '123']);
        $this->assertEquals(
            ['guid_imdb://tt1234567', 'guid_tvdb://123'],
            $guid->getPointers(),
            "Failed to assert that the GUID pointers are correct."
        );
    }

    public function test_getValidators()
    {
        $tmpFile = self::$tmpPath . '/guid_' . uniqid();
        $oldGuidFile = Config::get('guid.file');

        $yaml = [
            'guids' => [
                [
                    'name' => 'guid_foobar',
                    'type' => 'string',
                    'validator' => [
                        'pattern' => '/^\d+$/',
                        'example' => '(number)',
                        'tests' => [
                            'valid' => ['12345678'],
                            'invalid' => ['d12345678'],
                        ]
                    ]
                ]
            ]
        ];

        try {
            file_put_contents($tmpFile, Yaml::dump($yaml));
            Config::save('guid.file', $tmpFile);
            Guid::reparse();
            $this->assertArrayHasKey(
                'guid_foobar',
                Guid::getValidators(),
                'Assert that the GUID is added to the validators.'
            );
        } finally {
            Config::save('guid.file', $oldGuidFile);
        }
    }

    public function test_guid_logger_from_container()
    {
        Guid::fromArray(['guid_tvdb' => INF]);
        $record = $this->record('guid.external_id.ignored', 'unexpected_value_type');
        $this->assertNotNull($record, 'Assert message logged when the value type does not match the expected type.');
        $this->assertSame('guid_tvdb', $record->context['guid_source'] ?? null);
    }

}
