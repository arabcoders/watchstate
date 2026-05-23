<?php

declare(strict_types=1);

namespace Tests\Backends\MediaBrowser;

use App\Backends\Common\Context;
use App\Backends\Common\Error;
use App\Backends\Common\Response;
use App\Backends\Jellyfin\Action\GetUser;
use App\Backends\Jellyfin\JellyfinClient;
use App\Backends\Jellyfin\JellyfinGuid;
use App\Backends\Jellyfin\JellyfinValidateContext;
use App\Libs\Container;
use App\Libs\Exceptions\Backends\InvalidContextException;

final class ValidateContextTest extends MediaBrowserTestCase
{
    public function test_logs_html(): void
    {
        Container::add(GetUser::class, fn() => new class() {
            public function __invoke(): Response
            {
                return new Response(status: true, response: ['id' => 'user-1']);
            }
        });

        $action = new JellyfinValidateContext(
            $this->makeHttpClient(
                $this->makeResponse('<!doctype html><html><body>Jellyfin</body></html>', 200, [
                    'content-type' => 'text/html',
                ]),
            ),
        );

        $this->checkException(
            closure: fn() => $action($this->makeContext('Jellyfin')),
            reason: 'Expected html payload validation failure.',
            exception: InvalidContextException::class,
            exceptionMessage: 'non-JSON response from /system/Info',
            callback: static function (self $test, ?\Throwable $e): void {
                $test->assertInstanceOf(InvalidContextException::class, $e);
                $test->assertSame(200, $e->getContext('http.status_code'));
                $test->assertSame('http://mediabrowser.test/system/Info', $e->getContext('http.url'));
                $test->assertSame('text/html', $e->getContext('response.content_type'));
                $test->assertStringContainsString('Jellyfin', (string) $e->getContext('response.reason'));
            },
        );
    }

    public function test_logs_401(): void
    {
        Container::add(GetUser::class, fn() => new class() {
            public function __invoke(): Response
            {
                return new Response(status: true, response: ['id' => 'user-1']);
            }
        });

        $action = new JellyfinValidateContext(
            $this->makeHttpClient(
                $this->makeResponse(['Message' => 'Access denied'], 401, [
                    'content-type' => 'application/json',
                ]),
            ),
        );

        $this->checkException(
            closure: fn() => $action($this->makeContext('Jellyfin')),
            reason: 'Expected 401 validation failure.',
            exception: InvalidContextException::class,
            exceptionMessage: 'Backend responded with 401',
            callback: static function (self $test, ?\Throwable $e): void {
                $test->assertInstanceOf(InvalidContextException::class, $e);
                $test->assertSame(401, $e->getContext('http.status_code'));
                $test->assertSame('application/json', $e->getContext('response.content_type'));
                $test->assertSame('Access denied', $e->getContext('response.reason'));
            },
        );
    }

    public function test_users_401_reason(): void
    {
        $action = new \App\Backends\Jellyfin\Action\GetUsersList(
            $this->makeHttpClient(
                $this->makeResponse(['Message' => 'Token invalid'], 401, [
                    'content-type' => 'application/json',
                ]),
            ),
            $this->logger,
        );

        $result = $action($this->makeContext('Jellyfin'));

        self::assertFalse($result->isSuccessful());
        self::assertSame('Token invalid', $result->error?->context['response']['reason'] ?? null);
    }

    public function test_user_error_context(): void
    {
        Container::add(GetUser::class, fn() => new class() {
            public function __invoke(): Response
            {
                return new Response(
                    status: false,
                    error: new Error(
                        message: 'User request failed.',
                        context: [
                            'http' => [
                                'url' => 'http://mediabrowser.test/Users/user-1',
                                'status_code' => 401,
                            ],
                            'response' => [
                                'reason' => 'Token invalid',
                            ],
                        ],
                    ),
                );
            }
        });

        $action = new JellyfinValidateContext(
            $this->makeHttpClient($this->makeResponse(['Id' => 'backend-1'])),
        );

        $this->checkException(
            closure: fn() => $action($this->makeContext('Jellyfin')),
            reason: 'Expected user validation context failure.',
            exception: InvalidContextException::class,
            exceptionMessage: 'Failed to get user info.',
            callback: static function (self $test, ?\Throwable $e): void {
                $test->assertInstanceOf(InvalidContextException::class, $e);
                $test->assertSame('http://mediabrowser.test/Users/user-1', $e->getContext('http.url'));
                $test->assertSame(401, $e->getContext('http.status_code'));
                $test->assertSame('Token invalid', $e->getContext('response.reason'));
            },
        );
    }

    public function test_client_normalizes_auth(): void
    {
        $validator = new class() {
            public ?Context $context = null;

            public function __invoke(Context $context): bool
            {
                $this->context = $context;
                return true;
            }
        };

        Container::add(JellyfinValidateContext::class, fn() => $validator);

        $context = $this->makeContext('Jellyfin');
        $client = new JellyfinClient($context->cache, $this->logger, new JellyfinGuid($this->logger), $context->userContext);

        self::assertTrue($client->validateContext($context));
        self::assertNotNull($validator->context);
        self::assertArrayHasKey('Authorization', $validator->context->backendHeaders);
        self::assertStringContainsString('UserId="user-1"', $validator->context->backendHeaders['Authorization']);
    }
}
