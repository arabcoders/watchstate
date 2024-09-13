<?php
/** @noinspection PhpUnhandledExceptionInspection */

declare(strict_types=1);

namespace Tests\Backends\Plex;

use App\Backends\Plex\PlexGuid;
use App\Libs\Config;
use App\Libs\Exceptions\Backends\InvalidArgumentException;
use App\Libs\Extends\LogMessageProcessor;
use App\Libs\Guid;
use App\Libs\TestCase;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger;
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
        return new PlexGuid($this->logger);
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
                    file_put_contents($tmpFile, Yaml::dump(['plex' => 'foo']));
                    $this->getClass()->parseGUIDFile($tmpFile);
                },
                reason: "Should throw an exception when there are no GUIDs mapping.",
                exception: InvalidArgumentException::class,
                exceptionMessage: 'plex sub key is not an array'
            );
        } finally {
            if (file_exists($tmpFile)) {
                unlink($tmpFile);
            }
        }

        $tmpFile = tempnam(sys_get_temp_dir(), 'guid');
        try {
            $this->handler->clear();
            $yaml = ['plex' => [[]]];
            file_put_contents($tmpFile, Yaml::dump($yaml));
            $this->getClass()->parseGUIDFile($tmpFile);
            $this->assertCount(0, $this->handler->getRecords(), "There should be no messages logged for empty list.");
            $this->handler->clear();


            file_put_contents($tmpFile, Yaml::dump(ag_set($yaml, 'plex.0', 'ff')));
            $this->getClass()->parseGUIDFile($tmpFile);
            $this->assertTrue(
                $this->logged(Level::Warning, 'Value must be an object.', true),
                'Assert replace key is an object.'
            );

            $yaml = ag_set($yaml, 'plex.0.replace', 'foo');
            file_put_contents($tmpFile, Yaml::dump($yaml));
            $this->getClass()->parseGUIDFile($tmpFile);
            $this->assertTrue(
                $this->logged(Level::Warning, 'replace value must be an object.', true),
                'Assert replace key is an object.'
            );

            $yaml = ag_set($yaml, 'plex.0', ['replace' => []]);
            file_put_contents($tmpFile, Yaml::dump($yaml));
            $this->getClass()->parseGUIDFile($tmpFile);
            $this->assertTrue(
                $this->logged(Level::Warning, 'replace.from field is empty or not a string.', true),
                'Assert to field is a string.'
            );

            $yaml = ag_set($yaml, 'plex.0.replace.from', 'foo');
            file_put_contents($tmpFile, Yaml::dump($yaml));
            $this->getClass()->parseGUIDFile($tmpFile);
            $this->assertTrue(
                $this->logged(Level::Warning, 'replacer.to field is not a string.', true),
                'Assert to field is a string.'
            );

            $yaml = ag_set($yaml, 'plex.0.replace.to', 'bar');
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
            $yaml = ag_set(['plex' => []], 'plex.0.map', 'foo');
            file_put_contents($tmpFile, Yaml::dump($yaml));
            $this->getClass()->parseGUIDFile($tmpFile);
            $this->assertTrue(
                $this->logged(Level::Warning, 'map value must be an object.', true),
                'Assert replace key is an object.'
            );

            $yaml = ag_set($yaml, 'plex.0', ['map' => []]);
            file_put_contents($tmpFile, Yaml::dump($yaml));
            $this->getClass()->parseGUIDFile($tmpFile);
            $this->assertTrue(
                $this->logged(Level::Warning, 'map.from field is empty or not a string.', true),
                'Assert to field is a string.'
            );

            $yaml = ag_set($yaml, 'plex.0.map.from', 'foo');
            file_put_contents($tmpFile, Yaml::dump($yaml));
            $this->getClass()->parseGUIDFile($tmpFile);
            $this->assertTrue(
                $this->logged(Level::Warning, 'map.to field is empty or not a string.', true),
                'Assert to field is a string.'
            );

            $yaml = ag_set($yaml, 'plex.0.map.to', 'foobar');
            file_put_contents($tmpFile, Yaml::dump($yaml));
            $this->getClass()->parseGUIDFile($tmpFile);
            $this->assertTrue(
                $this->logged(Level::Warning, 'field does not starts with', true),
                'Assert to field is a string.'
            );

            $yaml = ag_set($yaml, 'plex.0.map.to', 'guid_foobar');
            file_put_contents($tmpFile, Yaml::dump($yaml));
            $this->getClass()->parseGUIDFile($tmpFile);
            $this->assertTrue(
                $this->logged(Level::Warning, 'map.to field is not a supported', true),
                'Assert to field is a string.'
            );

            $yaml = ag_set($yaml, 'plex.0.map', [
                'from' => 'com.plexapp.agents.imdb',
                'to' => 'guid_imdb',
            ]);
            file_put_contents($tmpFile, Yaml::dump($yaml));
            $this->getClass()->parseGUIDFile($tmpFile);
            $this->assertTrue(
                $this->logged(Level::Warning, 'map.from already exists.', true),
                'Assert to field is a string.'
            );

            $yaml = ag_set($yaml, 'plex.0.map', [
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

            $yaml = ag_set($yaml, 'plex.0', [
                'legacy' => false,
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

}
