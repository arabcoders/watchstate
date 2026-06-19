<?php

declare(strict_types=1);

namespace Tests\Backends\MediaBrowser;

use App\Backends\Common\Response;
use App\Backends\Jellyfin\Action\GetUser;
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
                $test->assertSame(200, $e->getContext('response.status_code'));
                $test->assertSame('http://mediabrowser.test/system/Info', $e->getContext('request.url'));
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
                $test->assertSame(401, $e->getContext('response.status_code'));
                $test->assertSame('application/json', $e->getContext('response.content_type'));
                $test->assertSame('Access denied', $e->getContext('response.reason'));
            },
        );
    }
}
