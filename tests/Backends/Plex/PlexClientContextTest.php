<?php

declare(strict_types=1);

namespace Tests\Backends\Plex;

use App\Backends\Common\Cache;
use App\Backends\Common\Context;
use App\Backends\Common\Error;
use App\Backends\Common\Levels;
use App\Backends\Common\Response;
use App\Backends\Plex\Action\GetInfo;
use App\Backends\Plex\PlexClient;
use App\Backends\Plex\PlexGuid;
use App\Libs\Container;
use App\Libs\Exceptions\Backends\RuntimeException;
use App\Libs\TestCase;
use App\Libs\Uri;
use Monolog\Handler\NullHandler;
use Monolog\Logger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Psr16Cache;

final class PlexClientContextTest extends TestCase
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
                        context: ['response' => ['reason' => 'Plex said no']],
                        level: Levels::ERROR,
                        extra: ['error' => 'Plex said no'],
                    ),
                );
            }
        });

        $logger = new Logger('test', [new NullHandler()]);
        $cache = new Cache($logger, new Psr16Cache(new ArrayAdapter()));
        $guid = new PlexGuid($logger);
        $userContext = $this->createUserContext(PlexClient::CLIENT_NAME);
        $client = new PlexClient($logger, $cache, $guid, $userContext);

        $context = new Context(
            clientName: PlexClient::CLIENT_NAME,
            backendName: 'test_plex',
            backendUrl: new Uri('http://plex.test'),
            cache: $cache,
            userContext: $userContext,
            logger: $logger,
            backendId: 'backend-1',
            backendToken: 'token-1',
            backendUser: 1,
        );

        $client = $client->withContext($context);

        try {
            $client->getInfo();
            self::fail('Expected backend runtime exception.');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('Plex said no', $e->getMessage());
            self::assertSame('Plex said no', $e->getContext('response.reason'));
        }
    }
}
