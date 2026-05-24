<?php

declare(strict_types=1);

namespace Tests\Backends\Plex;

use App\Backends\Common\Context;
use App\Backends\Common\Response;
use App\Backends\Plex\Action\GetUser;
use App\Backends\Plex\PlexValidateContext;
use App\Libs\Container;
use App\Libs\Exceptions\Backends\InvalidContextException;

final class ValidateContextTest extends PlexTestCase
{
    public function test_logs_html(): void
    {
        Container::add(GetUser::class, fn() => new class() {
            public function __invoke(): Response
            {
                return new Response(status: true, response: ['id' => 1]);
            }
        });

        $context = $this->makeContext();
        $context = new Context(
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

        $action = new PlexValidateContext(
            $this->makeHttpClient(
                $this->makeResponse('<html><body>Plex</body></html>', 200),
            ),
        );

        $this->checkException(
            closure: fn() => $action($context),
            reason: 'Expected html payload validation failure.',
            exception: InvalidContextException::class,
            exceptionMessage: 'non-JSON response from /',
            callback: static function (self $test, ?\Throwable $e): void {
                $test->assertInstanceOf(InvalidContextException::class, $e);
                $test->assertSame(200, $e->getContext('http.status_code'));
                $test->assertSame('http://plex.test/', $e->getContext('http.url'));
                $test->assertStringContainsString('Plex', (string) $e->getContext('response.reason'));
            },
        );
    }
}
