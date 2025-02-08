<?php

declare(strict_types=1);

namespace Tests\Backends\Common;

use App\Backends\Common\Cache;
use App\Backends\Common\Error;
use App\Libs\ConfigFile;
use App\Libs\Database\DBLayer;
use App\Libs\Database\PDO\PDOAdapter;
use App\Libs\Exceptions\Backends\RuntimeException;
use App\Libs\Mappers\Import\MemoryMapper;
use App\Libs\TestCase;
use App\Libs\Uri;
use App\Libs\UserContext;
use Monolog\Handler\NullHandler;
use Monolog\Logger;
use PDO;
use Symfony\Component\Cache\Adapter\NullAdapter;
use Symfony\Component\Cache\Psr16Cache;

class ResponseTest extends TestCase
{
    use \App\Backends\Common\CommonTrait;

    public function test_backend_response_object(): void
    {
        $response = new \App\Backends\Common\Response(
            status: true,
            response: 'Hello World!',
            error: null,
            extra: [
                'foo' => 'bar'
            ],
        );

        $this->assertTrue(
            $response->isSuccessful(),
            'Response object should be successful if status is true.'
        );

        $this->assertFalse(
            $response->hasError(),
            'Response object should not have an error if error is null.'
        );

        $this->assertEquals(
            'Hello World!',
            $response->response,
            'Response object should have the same response as the one passed in the constructor.'
        );

        $this->assertEquals(
            ['foo' => 'bar'],
            $response->extra,
            'Response object should have the same extra as the one passed in the constructor.'
        );

        $this->assertInstanceOf(
            Error::class,
            $response->getError(),
            'getError() should return an Error object in all cases even if error is null.'
        );
    }

    public function test_tryResponse(): void
    {
        $logger = new Logger('test', [new NullHandler()]);
        $cache = new Cache($logger, new Psr16Cache(new NullAdapter()));
        $db = new PDOAdapter($logger, new DBLayer(new PDO('sqlite::memory:')));
        $db->migrations('up');

        $context = new \App\Backends\Common\Context(
            clientName: 'test',
            backendName: 'test',
            backendUrl: new Uri('https://example.com'),
            cache: $cache,
            userContext: new UserContext(
                name: 'test',
                config: new ConfigFile(
                    file: __DIR__ . '/../../Fixtures/test_servers.yaml',
                    autoSave: false,
                    autoCreate: false,
                    autoBackup: false
                ),
                mapper: new MemoryMapper(
                    logger: $logger,
                    db: $db,
                    cache: $cache->getInterface()
                ),
                cache: $cache->getInterface(),
                db: $db
            ),
            trace: false,
        );

        $response = (fn() => $this->tryResponse($context, fn() => throw new RuntimeException('test', 500)))();

        $this->assertTrue(
            $response->hasError(),
            'Response object should not have an error if error is null.'
        );

        $this->assertNull(
            $response->response,
            'Response object should have the same response as the one passed in the constructor.'
        );

        $this->assertInstanceOf(
            Error::class,
            $response->getError(),
            'getError() should return an Error object in all cases even if error is null.'
        );

        $response = (fn() => $this->tryResponse($context, fn() => 'i am teapot'))();

        $this->assertSame(
            'i am teapot',
            $response->response,
            'Response object should have the same response as the one passed in the constructor.'
        );
    }

}
