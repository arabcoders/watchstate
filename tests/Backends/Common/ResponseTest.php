<?php

declare(strict_types=1);

namespace Tests\Backends\Common;

use App\Backends\Common\Cache;
use App\Backends\Common\CommonTrait;
use App\Backends\Common\Context;
use App\Backends\Common\Error;
use App\Backends\Common\Levels;
use App\Libs\ConfigFile;
use App\Libs\Exceptions\Backends\RuntimeException;
use App\Libs\Mappers\Import\DirectMapper;
use App\Libs\TestCase;
use App\Libs\Uri;
use App\Libs\UserContext;
use Monolog\Handler\NullHandler;
use Monolog\Logger;
use Symfony\Component\Cache\Adapter\NullAdapter;
use Symfony\Component\Cache\Psr16Cache;

class ResponseTest extends TestCase
{
    use CommonTrait;

    public function test_tryResponse(): void
    {
        $logger = new Logger('test', [new NullHandler()]);
        $cache = new Cache($logger, new Psr16Cache(new NullAdapter()));
        $db = $this->createDb($logger);

        $context = new Context(
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
                mapper: new DirectMapper(
                    logger: $logger,
                    db: $db,
                    cache: $cache->getInterface()
                ),
                cache: $cache->getInterface(),
                db: $db
            ),
            trace: false,
        );

        $response = (fn() => $this->tryResponse(
            context: $context,
            fn: fn() => throw new RuntimeException('test', 500),
            action: 'test.action',
        ))();

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

        $this->assertSame(
            "test request failed for 'test' during test.action. test",
            $response->getError()->format(),
            'Response error should include the underlying exception message.'
        );

        $this->assertSame(
            Levels::WARNING,
            $response->getError()->level,
            'Backend client failures should remain warnings.'
        );

        $response = (fn() => $this->tryResponse($context, fn() => 'i am teapot'))();

        $this->assertSame(
            'i am teapot',
            $response->response,
            'Response object should have the same response as the one passed in the constructor.'
        );
    }

    public function test_reason_extract(): void
    {
        $this->assertSame('bad token', $this->getBackendResponseReason('{"Message":"bad token"}'));
        $this->assertSame(
            'Invalid authentication token.',
            $this->getBackendResponseReason('<?xml version="1.0" encoding="UTF-8"?><errors><error>Invalid authentication token.</error></errors>')
        );
        $this->assertSame('plain failure', $this->getBackendResponseReason('<p>plain failure</p>'));
    }

}
