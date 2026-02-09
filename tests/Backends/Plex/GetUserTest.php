<?php

declare(strict_types=1);

namespace Tests\Backends\Plex;

use App\Backends\Common\Response;
use App\Backends\Plex\Action\GetUser;
use App\Backends\Plex\Action\GetUsersList;
use App\Libs\Container;

class GetUserTest extends PlexTestCase
{
    public function test_get_user_success(): void
    {
        Container::add(GetUsersList::class, fn() => new class() {
            public function __invoke(): Response
            {
                return new Response(status: true, response: [
                    ['id' => 1, 'name' => 'Test User'],
                ]);
            }
        });

        $context = $this->makeContext();
        $context = new \App\Backends\Common\Context(
            clientName: $context->clientName,
            backendName: $context->backendName,
            backendUrl: $context->backendUrl,
            cache: $context->cache,
            userContext: $context->userContext,
            logger: $context->logger,
            backendId: $context->backendId,
            backendToken: $context->backendToken,
            backendUser: 1,
            backendHeaders: $context->backendHeaders,
            trace: $context->trace,
            options: $context->options,
        );

        $action = new GetUser($this->makeHttpClient(), $this->logger);
        $result = $action($context);

        $this->assertTrue($result->isSuccessful());
        $this->assertSame('Test User', $result->response['name']);
    }

    public function test_get_user_missing_id(): void
    {
        Container::add(GetUsersList::class, fn() => new class() {
            public function __invoke(): Response
            {
                return new Response(status: true, response: [
                    ['id' => 2, 'name' => 'Other User'],
                ]);
            }
        });

        $context = $this->makeContext();
        $context = new \App\Backends\Common\Context(
            clientName: $context->clientName,
            backendName: $context->backendName,
            backendUrl: $context->backendUrl,
            cache: $context->cache,
            userContext: $context->userContext,
            logger: $context->logger,
            backendId: $context->backendId,
            backendToken: $context->backendToken,
            backendUser: 1,
            backendHeaders: $context->backendHeaders,
            trace: $context->trace,
            options: $context->options,
        );

        $action = new GetUser($this->makeHttpClient(), $this->logger);
        $result = $action($context);

        $this->assertFalse($result->isSuccessful());
        $this->assertNotNull($result->error);
    }
}
