<?php

declare(strict_types=1);

namespace Tests\Libs;

use App\Libs\ConfigFile;
use App\Libs\Extends\LogMessageProcessor;
use App\Libs\TestCase;
use InvalidArgumentException;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Throwable;

class ConfigFileTest extends TestCase
{
    private array $data = ['foo' => 'bar', 'baz' => 'kaz', 'sub' => ['key' => 'val']];

    private array $params = [];
    private Logger|null $logger = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->handler = new TestHandler();
        $this->logger = new Logger('logger', processors: [new LogMessageProcessor()]);
        $this->logger->pushHandler($this->handler);

        $this->params = [
            'file' => __DIR__ . '/../Fixtures/test_servers.yaml',
            'type' => 'yaml',
            'autoSave' => false,
            'autoCreate' => false,
            'autoBackup' => false,
            'opts' => [
                'json_decode' => JSON_UNESCAPED_UNICODE,
                'json_encode' => JSON_UNESCAPED_UNICODE,
            ],
        ];
    }

    public function test_constructor()
    {
        $this->checkException(
            closure: fn() => new ConfigFile(
                'nonexistent.json',
                'json',
                autoSave: false,
                autoCreate: false,
                autoBackup: false
            ),
            reason: 'If file does not exist, and autoCreate is set to false, an exception should be thrown.',
            exception: InvalidArgumentException::class,
            exceptionMessage: "File 'nonexistent.json' does not exist.",
        );

        $this->checkException(
            closure: fn() => new ConfigFile(
                'nonexistent.json',
                'php',
                autoSave: false,
                autoCreate: false,
                autoBackup: false
            ),
            reason: 'If type is not supported, an exception should be thrown.',
            exception: InvalidArgumentException::class,
            exceptionMessage: "Invalid content type 'php'.",
        );

        $this->checkException(
            closure: fn() => new ConfigFile(
                '/root/test.json',
                'json',
                autoSave: false,
                autoCreate: true,
                autoBackup: false
            ),
            reason: 'If file is not writable, an exception should be thrown.',
            exception: InvalidArgumentException::class,
            exceptionMessage: "could not be created",
        );

        try {
            $class = new ConfigFile(...$this->params);
            $this->assertInstanceOf(ConfigFile::class, $class);
        } catch (Throwable $e) {
            $this->fail('If all conditions are met, NO exception should be been thrown. ' . $e->getMessage());
        }

        try {
            $class = ConfigFile::open(...$this->params);
            $this->assertInstanceOf(ConfigFile::class, $class);
        } catch (Throwable $e) {
            $this->fail('If all conditions are met, NO exception should be been thrown. ' . $e->getMessage());
        }
    }

    public function test_setLogger()
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'test');
        copy(__DIR__ . '/../Fixtures/test_servers.yaml', $tmpFile);
        $params = $this->params;
        $params['file'] = $tmpFile;
        $params['autoBackup'] = true;
        $class = new ConfigFile(...$params);
        try {
            $class->setLogger($this->logger);
            $class->set('foo', 'bar');
            $class->delete('kaz');
            // -- trigger external change
            ConfigFile::open(...$params)->set('bar', 'kaz')->persist();
            // -- should trigger warning.
            $class->persist();
            $this->assertStringContainsString(
                'has been modified since last load.',
                $this->handler->getRecords()[0]['message']
            );
        } catch (Throwable $e) {
            $this->fail('If correct logger is passed, no exception should be thrown. ' . $e->getMessage());
        } finally {
            if (file_exists($tmpFile)) {
                unlink($tmpFile);
            }
            if (file_exists($tmpFile . '.bak')) {
                unlink($tmpFile . '.bak');
            }
        }
    }

    public function test_delete()
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'test');
        copy(__DIR__ . '/../Fixtures/test_servers.yaml', $tmpFile);
        $params = $this->params;
        $params['file'] = $tmpFile;

        try {
            $this->assertArrayNotHasKey(
                'test_jellyfin',
                ConfigFile::open(...$params)->delete('test_jellyfin')->getAll(),
                '->delete: Failed to delete key from YAML file.'
            );

            $class = ConfigFile::open(...$params);
            unset($class['test_jellyfin']);
            $this->assertArrayNotHasKey(
                'test_jellyfin',
                $class->getAll(),
                'ArrayAccess: Failed to delete key from YAML file.'
            );
        } catch (Throwable $e) {
            $this->fail('If correct logger is passed, no exception should be thrown. ' . $e->getMessage());
        } finally {
            if (file_exists($tmpFile)) {
                unlink($tmpFile);
            }
        }
    }

    public function test_get()
    {
        $class = new ConfigFile(...$this->params);

        $this->assertArrayHasKey(
            'token',
            $class->get('test_plex', []),
            '->get: Invalid response from parsing YAML file.'
        );
        $this->assertArrayHasKey('token', $class['test_plex'], 'ArrayAccess: Invalid response from parsing YAML file.');

        $this->assertArrayNotHasKey(
            'token',
            $class->get('test_not_set', []),
            'Invalid response from parsing YAML file.'
        );
        $this->assertNull($class['test_not_set'], 'ArrayAccess: Must return null if key does not exist.');
    }

    public function test_has()
    {
        $class = new ConfigFile(...$this->params);
        $this->assertTrue($class->has('test_plex'), '->has: Must return true if key exists.');
        $this->assertTrue(isset($class['test_plex']), 'ArrayAccess: Must return true if key exists.');

        $this->assertFalse($class->has('test_not_set'), '->has: Must return false if key does not exist.');
        $this->assertFalse(isset($class['test_not_set']), 'ArrayAccess: Must return false if key does not exist.');
    }

    public function test_set()
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'test');
        copy(__DIR__ . '/../Fixtures/test_servers.yaml', $tmpFile);
        $params = $this->params;
        $params['file'] = $tmpFile;

        try {
            $this->assertArrayHasKey(
                'test_foo',
                ConfigFile::open(...$params)->set('test_foo', $this->data)->getAll(),
                '->set: Failed to set key in YAML file.'
            );

            $class = ConfigFile::open(...$params);
            $class['test_foo'] = $this->data;
            $this->assertArrayHasKey('test_foo', $class->getAll(), 'ArrayAccess: Failed to set key.');

            // -- test deep array.
            $class->set('test_plex.options.foo', 'bar');
            $this->assertArrayHasKey('foo', $class->get('test_plex.options', []), 'Failed to set deep key.');

            $class['foo'] = ['bar' => ['jaz' => ['kaz' => 'baz']]];
            $this->assertArrayHasKey('foo', $class->getAll(), 'ArrayAccess: Failed to set key.');
            $class->set('foo', ['bar' => ['tax' => 'max']]);

            $class->override()->persist();
            $class = ConfigFile::open(...$params);

            $this->assertArrayHasKey('test_foo', $class->getAll(), 'failed to persist changes.');
            $this->assertArrayHasKey('foo', $class->getAll(), 'ArrayAccess: failed to persist changes.');
            $this->assertArrayHasKey('bar', $class->get('foo', []), 'failed to persist changes.');
        } catch (Throwable $e) {
            $this->fail('If correct logger is passed, no exception should be thrown. ' . $e->getMessage());
        } finally {
            if (file_exists($tmpFile)) {
                unlink($tmpFile);
            }
        }
    }

    public function test_configFile_set_php_stream_wrapper()
    {
        file_put_contents('php://temp', file_get_contents(__DIR__ . '/../Fixtures/test_servers.yaml'));
        $params['file'] = 'php://temp';

        try {
            $class = new ConfigFile(...$params);
            $this->assertInstanceOf(ConfigFile::class, $class);
        } catch (Throwable $e) {
            $this->fail("it shouldn't throw exception for php:// streams. {$e->getMessage()}");
        }

        $class = ConfigFile::open(...$params);
        $this->assertInstanceOf(ConfigFile::class, $class);
        $this->assertEmpty($class->getAll(), 'php:// streams should be empty, when re-opened.');
    }

    public function test_configFile_set()
    {
        $params['file'] = tempnam(sys_get_temp_dir(), 'test');

        try {
            try {
                copy(__DIR__ . '/../Fixtures/test_servers.yaml', $params['file']);
                $class = new ConfigFile(...$params);
                $this->assertInstanceOf(ConfigFile::class, $class);
            } catch (Throwable $e) {
                $this->fail('If file exists and is readable, no exception should be thrown. ' . $e->getMessage());
            }

            $class = ConfigFile::open(...$params);
            $this->assertInstanceOf(ConfigFile::class, $class);

            $this->assertArrayHasKey('token', $class->get('test_plex', []), 'Invalid response from parsing YAML file.');
            $this->assertArrayNotHasKey(
                'token',
                $class->get('test_not_set', []),
                'Invalid response from parsing YAML file.'
            );
            $this->assertTrue($class->has('test_plex'), 'Must return true if key exists.');
            $this->assertFalse($class->has('test_not_set'), 'Must return false if key does not exist.');

            $this->assertArrayHasKey('token', $class['test_plex'], 'Failed to get arrayAccess key correctly.');
            $this->assertTrue(isset($class['test_plex']), 'Must return true if arrayAccess key exists.');
            $this->assertNull($class['test_not_set'], 'Must return null if arrayAccess key does not exist.');
            $this->assertFalse(isset($class['test_not_set']), 'Must return false if arrayAccess key does not exist.');
        } finally {
            if (file_exists($params['file'])) {
                unlink($params['file']);
            }
        }
    }

    public function test_addFilter()
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'test');
        copy(__DIR__ . '/../Fixtures/test_servers.yaml', $tmpFile);
        $params = $this->params;
        $params['file'] = $tmpFile;

        try {
            $class = ConfigFile::open(...$params);

            // Add a filter that uppercases all string values
            $class->addFilter('uppercase', function (array $data): array {
                return array_map(function ($value) {
                    if (is_string($value)) {
                        return strtoupper($value);
                    }
                    if (is_array($value)) {
                        return array_map(fn($v) => is_string($v) ? strtoupper($v) : $v, $value);
                    }
                    return $value;
                }, $data);
            });

            $class->set('test_filter', 'lowercase_value');
            $class->persist();

            // Reload and check that filter was applied
            $reloaded = ConfigFile::open(...$params);
            $this->assertEquals(
                'LOWERCASE_VALUE',
                $reloaded->get('test_filter'),
                'Filter should have uppercased the value during persist'
            );

            // Test multiple filters can be added
            $class2 = ConfigFile::open(...$params);
            $class2->addFilter('prefix', function (array $data): array {
                return array_map(function ($value) {
                    if (is_string($value)) {
                        return 'PREFIX_' . $value;
                    }
                    return $value;
                }, $data);
            });

            $class2->set('another_key', 'value');
            $class2->persist();

            $reloaded2 = ConfigFile::open(...$params);
            $this->assertEquals(
                'PREFIX_LOWERCASE_VALUE',
                $reloaded2->get('test_filter'),
                'Both filters should have been applied'
            );
            $this->assertEquals(
                'PREFIX_value',
                $reloaded2->get('another_key'),
                'Filter should apply to new keys'
            );

            // Test that filter receives array and returns array
            $class3 = ConfigFile::open(...$params);
            $filterCalled = false;
            $class3->addFilter('validator', function (array $data) use (&$filterCalled): array {
                $filterCalled = true;
                $this->assertIsArray($data, 'Filter must receive array');
                return $data;
            });
            $class3->set('test', 'value');
            $class3->persist();
            $this->assertTrue($filterCalled, 'Filter should have been called during persist');
        } catch (Throwable $e) {
            $this->fail('addFilter test should not throw exception: ' . $e->getMessage());
        } finally {
            if (file_exists($tmpFile)) {
                unlink($tmpFile);
            }
            if (file_exists($tmpFile . '.bak')) {
                unlink($tmpFile . '.bak');
            }
        }
    }

    public function test_removeFilter()
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'test');
        copy(__DIR__ . '/../Fixtures/test_servers.yaml', $tmpFile);
        $params = $this->params;
        $params['file'] = $tmpFile;

        try {
            $class = ConfigFile::open(...$params);

            // Add a filter
            $class->addFilter('uppercase', function (array $data): array {
                return array_map(function ($value) {
                    if (is_string($value)) {
                        return strtoupper($value);
                    }
                    return $value;
                }, $data);
            });

            // Remove the filter before persisting
            $class->removeFilter('uppercase');
            $class->set('test_value', 'should_stay_lowercase');
            $class->persist();

            // Reload and check that filter was NOT applied
            $reloaded = ConfigFile::open(...$params);
            $this->assertEquals(
                'should_stay_lowercase',
                $reloaded->get('test_value'),
                'Filter should not have been applied after removal'
            );

            // Test removing non-existent filter doesn't cause error
            $class2 = ConfigFile::open(...$params);
            try {
                $class2->removeFilter('nonexistent_filter');
                $this->assertTrue(true, 'Removing non-existent filter should not throw exception');
            } catch (Throwable $e) {
                $this->fail('removeFilter should not throw exception for non-existent filter: ' . $e->getMessage());
            }

            // Test removing one filter but keeping another
            $class3 = ConfigFile::open(...$params);
            $class3->addFilter('uppercase', function (array $data): array {
                return array_map(function ($value) {
                    if (is_string($value)) {
                        return strtoupper($value);
                    }
                    return $value;
                }, $data);
            });
            $class3->addFilter('prefix', function (array $data): array {
                return array_map(function ($value) {
                    if (is_string($value)) {
                        return 'PREFIX_' . $value;
                    }
                    return $value;
                }, $data);
            });

            // Remove only the uppercase filter
            $class3->removeFilter('uppercase');
            $class3->set('test_partial', 'value');
            $class3->persist();

            $reloaded3 = ConfigFile::open(...$params);
            $this->assertEquals(
                'PREFIX_value',
                $reloaded3->get('test_partial'),
                'Only prefix filter should be applied, not uppercase'
            );
        } catch (Throwable $e) {
            $this->fail('removeFilter test should not throw exception: ' . $e->getMessage());
        } finally {
            if (file_exists($tmpFile)) {
                unlink($tmpFile);
            }
            if (file_exists($tmpFile . '.bak')) {
                unlink($tmpFile . '.bak');
            }
        }
    }
}
