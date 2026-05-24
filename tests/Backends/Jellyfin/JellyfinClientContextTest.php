<?php

declare(strict_types=1);

namespace Tests\Backends\Jellyfin;

use App\Backends\Common\Cache;
use App\Backends\Common\Context;
use App\Backends\Common\Error;
use App\Backends\Common\Levels;
use App\Backends\Common\Response;
use App\Backends\Jellyfin\Action\GetInfo;
use App\Backends\Jellyfin\JellyfinClient;
use App\Backends\Jellyfin\JellyfinGuid;
use App\Libs\Container;
use App\Libs\Exceptions\Backends\RuntimeException;
use App\Libs\TestCase;
use App\Libs\Uri;
use Monolog\Handler\NullHandler;
use Monolog\Logger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Psr16Cache;

final class JellyfinClientContextTest extends TestCase
{
    public function test_info_context(): void
    {
        Container::reinitialize();
        Container::add(GetInfo::class, fn() => new class() {
            public function __invoke(Context $context, array $opts = []): Response
            {
                return new Response(
                    status: false,
                    error: new Error(
                        message: 'Backend request failed.',
                        context: ['response' => ['reason' => 'Access denied']],
                        level: Levels::ERROR,
                        extra: ['error' => 'Access denied'],
                    ),
                );
            }
        });

        $logger = new Logger('test', [new NullHandler()]);
        $cache = new Cache($logger, new Psr16Cache(new ArrayAdapter()));
        $guid = new JellyfinGuid($logger);
        $userContext = $this->createUserContext(JellyfinClient::CLIENT_NAME);
        $client = new JellyfinClient($cache, $logger, $guid, $userContext);

        $context = new Context(
            clientName: JellyfinClient::CLIENT_NAME,
            backendName: 'test_jellyfin',
            backendUrl: new Uri('http://mediabrowser.test'),
            cache: $cache,
            userContext: $userContext,
            logger: $logger,
            backendId: 'backend-1',
            backendToken: 'token-1',
            backendUser: 'user-1',
        );

        $client = $client->withContext($context);

        try {
            $client->getInfo();
            self::fail('Expected backend runtime exception.');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('Access denied', $e->getMessage());
            self::assertSame('Access denied', $e->getContext('response.reason'));
        }
    }
}
