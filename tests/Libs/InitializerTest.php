<?php

declare(strict_types=1);

namespace Tests\Libs;

use App\Libs\Initializer;
use App\Libs\TestCase;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
use ReflectionClass;
use ReflectionMethod;

final class InitializerTest extends TestCase
{
    public function test_access_text(): void
    {
        $this->initTempDir();

        $file = self::$tmpPath . '/access.log';
        $logger = new Logger('http', [$this->createHandler($file, 'text')]);

        $logger->info('10.0.0.2 - "GET / HTTP/1.1" 200 1 "-" "agent" "-"', [
            'request' => ['method' => 'GET'],
        ]);

        self::assertSame(
            '10.0.0.2 - "GET / HTTP/1.1" 200 1 "-" "agent" "-"' . PHP_EOL,
            file_get_contents($file),
        );
    }

    public function test_access_jsonl(): void
    {
        $this->initTempDir();

        $file = self::$tmpPath . '/access.jsonl';
        $logger = new Logger('http', [$this->createHandler($file, 'jsonl')]);

        $logger->info('10.0.0.2 - "GET / HTTP/1.1" 200 1 "-" "agent" "-"', [
            'request' => ['method' => 'GET'],
        ]);

        $payload = json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame('http', $payload['logger']);
        self::assertSame('info', $payload['level']);
        self::assertSame('GET', $payload['fields']['request.method']);
    }

    public function test_access_level(): void
    {
        $this->initTempDir();

        $file = self::$tmpPath . '/access.jsonl';
        $logger = new Logger('http', [$this->createHandler($file, 'jsonl', Level::Error)]);

        $logger->info('10.0.0.2 - "GET /ok HTTP/1.1" 200 1 "-" "agent" "-"');
        $logger->error('10.0.0.2 - "GET /fail HTTP/1.1" 500 1 "-" "agent" "-"');

        $payload = json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame('error', $payload['level']);
        self::assertStringContainsString('/fail', $payload['message']);
    }

    private function createHandler(string $file, string $format, Level $level = Level::Info): StreamHandler
    {
        $class = new ReflectionClass(Initializer::class);
        $initializer = $class->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(Initializer::class, 'createStreamHandler');

        return $method->invoke($initializer, $file, $level, true, $format);
    }
}
