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
use Monolog\Level;
use Monolog\Logger;
use Psr\Log\LoggerInterface as iLogger;
use Symfony\Component\Yaml\Yaml;

class GuidTest extends TestCase
{
    protected ?Logger $logger = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initTempDir();

        $this->handler = new TestHandler();
        $this->logger = new Logger('logger', processors: [new LogMessageProcessor()]);
        $this->logger->pushHandler($this->handler);

        Container::init();
        Container::add(iLogger::class, $this->logger);
    }

    public function test__construct()
    {
        $guid = Guid::fromArray(['guid_test' => 'ztt1234567']);
        $this->assertCount(0, $guid->getAll(), 'Count should be 0 when the GUID is not supported.');

        $guid = Guid::fromArray(['guid_imdb' => null]);
        $this->assertCount(0, $guid->getAll(), 'Count should be 0 when value of guid is null.');

        Guid::fromArray(['guid_tvdb' => INF], logger: $this->logger);
        $this->assertTrue(
            $this->loggedWith(Level::Info, ['operation' => 'guid.validate', 'error' => 'unexpected_value_type'], true),
            'Assert message logged when the value type does not match the expected type.',
        );

        Guid::fromArray(['guid_tvdb' => 'tt1234567']);
        $this->assertTrue(
            $this->loggedWith(Level::Info, ['operation' => 'guid.validate', 'error' => 'unexpected_value'], true),
            'Assert message logged when the value does not match the expected pattern.',
        );
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
                    ]),
                );
            }

            foreach (ag($validator, 'tests.invalid', []) as $value) {
                $this->checkException(
                    closure: fn() => Guid::validate($guid, $value),
                    reason: r("Failed to assert that invalid '{value}' test for '{guid}' throws an exception.", [
                        'guid' => $guid,
                        'value' => $value,
                    ]),
                    exception: InvalidArgumentException::class,
                );
            }
        }

        $this->checkException(
            closure: fn() => Guid::validate('guid_not_set', '12345678'),
            reason: 'Failed to assert that an exception is thrown when the GUID is not supported.',
            exception: InvalidArgumentException::class,
            exceptionMessage: 'Invalid db',
        );

        $this->assertTrue(
            Guid::validate('guid_cmdb', '12345678'),
            'Assert supported guid with no validator returns true.',
        );
        $this->assertTrue(
            Guid::validate('path', '0123456789abcdef0123456789abcdef'),
            'Assert path guid validates through prefix normalization.',
        );
    }

    public function test_jsonSerialize()
    {
        Guid::reparse();
        $guid = Guid::fromArray(['guid_imdb' => 'tt1234567', 'guid_tvdb' => '123']);
        $this->assertJsonStringEqualsJsonString(
            json_encode(['guid_imdb' => 'tt1234567', 'guid_tvdb' => '123']),
            json_encode($guid),
            'Failed to assert that the JSON serialization of the Guid object is correct.',
        );
    }

    public function test__toString()
    {
        $guid = Guid::fromArray(['guid_imdb' => 'tt1234567', 'guid_tvdb' => '123']);
        $this->assertJsonStringEqualsJsonString(
            json_encode(['guid_imdb' => 'tt1234567', 'guid_tvdb' => '123']),
            (string) $guid,
            'Failed to assert that the string representation of the Guid object is correct. {records}',
        );
    }

    public function test_parseGUIDFile()
    {
        Guid::setLogger($this->logger);

        $this->checkException(
            closure: fn() => Guid::parseGUIDFile('not_set.yml'),
            reason: 'Failed to assert that the GUID file is not found.',
            exception: InvalidArgumentException::class,
            exceptionMessage: 'does not exist',
        );

        $tmpFile = self::$tmpPath . '/guid_' . uniqid();
        $this->checkException(
            closure: function () use ($tmpFile) {
                file_put_contents($tmpFile, 'fff: {_]');
                Guid::parseGUIDFile($tmpFile);
            },
            reason: 'Failed to throw exception when the GUID file is invalid.',
            exception: InvalidArgumentException::class,
            exceptionMessage: 'Failed to parse GUIDs file',
        );

        $tmpFile = self::$tmpPath . '/guid_' . uniqid();
        $this->checkException(
            closure: function () use ($tmpFile) {
                file_put_contents($tmpFile, 'invalid');
                Guid::parseGUIDFile($tmpFile);
            },
            reason: 'Failed to throw exception when the GUID file is invalid.',
            exception: InvalidArgumentException::class,
            exceptionMessage: 'is not an array',
        );

        $tmpFile = self::$tmpPath . '/guid_' . uniqid();
        $this->checkException(
            closure: function () use ($tmpFile) {
                file_put_contents($tmpFile, 'version: 2.0');
                Guid::parseGUIDFile($tmpFile);
            },
            reason: 'Failed to throw exception when the GUID file version is not supported.',
            exception: InvalidArgumentException::class,
            exceptionMessage: 'Unsupported file version',
        );

        $tmpFile = self::$tmpPath . '/guid_' . uniqid();
        touch($tmpFile);
        Guid::parseGUIDFile($tmpFile);
        $this->assertTrue(
            $this->loggedWith(Level::Info, ['operation' => 'guid.load_file'], true),
            'Failed to assert that the GUID file is empty.',
        );

        $tmpFile = self::$tmpPath . '/guid_' . uniqid();
        $this->checkException(
            closure: function () use ($tmpFile) {
                file_put_contents($tmpFile, Yaml::dump(['guids' => []]));
                Guid::parseGUIDFile($tmpFile);
            },
            reason: 'Should throw an exception when there are no GUIDs mapping.',
            exception: InvalidArgumentException::class,
            exceptionMessage: 'does not contain any GUIDs mapping',
        );

        $tmpFile = self::$tmpPath . '/guid_' . uniqid();
        file_put_contents($tmpFile, Yaml::dump(['guids' => ['guid_imdb' => 'tt1234567']]));
        Guid::parseGUIDFile($tmpFile);
        $this->assertTrue(
            $this->loggedWith(Level::Warning, ['operation' => 'guid.definition', 'error' => 'def_not_object'], true),
            'Assert that GUID key is an array.',
        );

        file_put_contents($tmpFile, Yaml::dump(['guids' => [['name' => 'imdb']]]));
        Guid::parseGUIDFile($tmpFile);
        $this->assertTrue(
            $this->loggedWith(Level::Warning, ['operation' => 'guid.definition', 'error' => 'def_name_invalid'], true),
            'Assert that GUID name starts with guid_',
        );

        file_put_contents($tmpFile, Yaml::dump(['guids' => [['name' => 'guid_imdb', 'type' => INF]]]));
        Guid::parseGUIDFile($tmpFile);
        $this->assertTrue(
            $this->loggedWith(Level::Warning, ['operation' => 'guid.definition', 'error' => 'def_type_not_string'], true),
            'Assert guid type is string.',
        );

        $yaml = [
            'guids' => [
                [
                    'name' => 'guid_foobar',
                    'type' => 'string',
                    'validator' => [],
                ],
            ],
        ];

        file_put_contents($tmpFile, Yaml::dump($yaml));

        Guid::parseGUIDFile($tmpFile);
        $this->assertTrue(
            $this->loggedWith(Level::Warning, ['operation' => 'guid.definition', 'error' => 'def_validator_not_object'], true),
            'Assert validator key is an object.',
        );

        $yaml = ag_set($yaml, 'guids.0.validator', ['pattern' => '\d']);
        file_put_contents($tmpFile, Yaml::dump($yaml));
        Guid::parseGUIDFile($tmpFile);
        $this->assertTrue(
            $this->loggedWith(Level::Warning, ['operation' => 'guid.definition', 'error' => 'def_pattern_invalid'], true),
            'Assert a message is logged when the pattern is invalid.',
        );

        $yaml = ag_set($yaml, 'guids.0.validator', ['pattern' => '/^\d+$/']);
        file_put_contents($tmpFile, Yaml::dump($yaml));
        Guid::parseGUIDFile($tmpFile);
        $this->assertTrue(
            $this->loggedWith(Level::Warning, ['operation' => 'guid.definition', 'error' => 'def_example_missing'], true),
            'Assert a message is logged when the example is empty or not a string.',
        );

        $yaml = ag_set($yaml, 'guids.0.validator', ['pattern' => '/^\d+$/', 'example' => '(number)']);
        file_put_contents($tmpFile, Yaml::dump($yaml));
        Guid::parseGUIDFile($tmpFile);
        $this->assertTrue(
            $this->loggedWith(Level::Warning, ['operation' => 'guid.definition', 'error' => 'def_tests_not_object'], true),
            'Assert a message is logged when the test key is not an object.',
        );

        $yaml = ag_set($yaml, 'guids.0.validator.tests', ['valid' => 'foo']);
        file_put_contents($tmpFile, Yaml::dump($yaml));
        Guid::parseGUIDFile($tmpFile);
        $this->assertTrue(
            $this->loggedWith(Level::Warning, ['operation' => 'guid.definition', 'error' => 'def_valid_tests_not_array'], true),
            'Assert a message is logged when the test key is not an object.',
        );

        $yaml = ag_set($yaml, 'guids.0.validator.tests.valid', ['d12345678']);
        file_put_contents($tmpFile, Yaml::dump($yaml));
        Guid::parseGUIDFile($tmpFile);
        $this->assertTrue(
            $this->loggedWith(Level::Warning, ['operation' => 'guid.definition', 'error' => 'def_valid_test_no_match'], true),
            'Assert a message is logged when valid test does not match the pattern.',
        );

        $yaml = ag_set($yaml, 'guids.0.validator.tests', [
            'valid' => ['12345678'],
            'invalid' => 'foo',
        ]);
        file_put_contents($tmpFile, Yaml::dump($yaml));
        Guid::parseGUIDFile($tmpFile);
        $this->assertTrue(
            $this->loggedWith(Level::Warning, ['operation' => 'guid.definition', 'error' => 'def_invalid_tests_not_array'], true),
            'Assert a message is logged when invalid test is not an array.',
        );

        $yaml = ag_set($yaml, 'guids.0.validator.tests', [
            'valid' => ['12345678'],
            'invalid' => ['12345678'],
        ]);
        file_put_contents($tmpFile, Yaml::dump($yaml));
        Guid::parseGUIDFile($tmpFile);
        $this->assertTrue(
            $this->loggedWith(Level::Warning, ['operation' => 'guid.definition', 'error' => 'def_invalid_test_matches'], true),
            'Assert a message is logged when invalid test match the pattern.',
        );

        $yaml = ag_set($yaml, 'guids.0.validator.tests', [
            'valid' => ['12345678'],
            'invalid' => ['d12345678'],
        ]);

        file_put_contents($tmpFile, Yaml::dump($yaml));
        Guid::parseGUIDFile($tmpFile);

        $this->assertArrayHasKey(
            'guid_foobar',
            Guid::getValidators(),
            'Assert that the GUID is added to the validators.',
        );
        $this->assertArrayHasKey(
            'guid_foobar',
            Guid::getSupported(),
            'Assert that the GUID is added to the supported GUIDs.',
        );
    }

    public function test_reparse()
    {
        $tmpFile = self::$tmpPath . '/guid_' . uniqid();
        $oldGuidFile = Config::get('guid.file');
        try {
            file_put_contents($tmpFile, "{'foo' => 'too' }");
            Config::save('guid.file', $tmpFile);
            Guid::setLogger($this->logger);
            Guid::reparse();
            Guid::getSupported();
            $this->assertTrue(
                $this->loggedWith(Level::Error, ['operation' => 'guid.load_file', 'error' => 'file_parse_failed'], true),
                'Failed to assert that the GUID file is empty.',
            );
        } finally {
            Config::save('guid.file', $oldGuidFile);
        }
    }

    public function test_getPointers()
    {
        $path = '0123456789abcdef0123456789abcdef';
        $guid = Guid::fromArray(['guid_imdb' => 'tt1234567', 'guid_tvdb' => '123', Guid::GUID_PATH => $path]);
        $this->assertEquals(
            ['guid_imdb://tt1234567', 'guid_tvdb://123', 'guid_path://' . $path],
            $guid->getPointers(),
            'Failed to assert that the GUID pointers are correct.',
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
                        ],
                    ],
                ],
            ],
        ];

        try {
            file_put_contents($tmpFile, Yaml::dump($yaml));
            Config::save('guid.file', $tmpFile);
            Guid::reparse();
            $this->assertArrayHasKey(
                'guid_foobar',
                Guid::getValidators(),
                'Assert that the GUID is added to the validators.',
            );
        } finally {
            Config::save('guid.file', $oldGuidFile);
        }
    }

    public function test_guid_logger_from_container()
    {
        Guid::setLogger($this->logger);
        Guid::fromArray(['guid_tvdb' => INF]);
        $this->assertTrue(
            $this->loggedWith(Level::Info, ['operation' => 'guid.validate', 'error' => 'unexpected_value_type'], true),
            'Assert message logged when the value type does not match the expected type.',
        );
    }
}
