<?php

declare(strict_types=1);

namespace Tests\Libs\Middlewares;

use App\Libs\Config;
use App\Libs\Enums\Http\Method;
use App\Libs\Enums\Http\Status;
use App\Libs\Middlewares\RateLimitMiddleware;
use App\Libs\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Psr16Cache;
use Tests\Support\RequestResponseTrait;

final class RateLimitMiddlewareTest extends TestCase
{
    use RequestResponseTrait;

    private Psr16Cache $cache;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cache = new Psr16Cache(new ArrayAdapter());

        Config::save('api.prefix', '/v1/api');
        Config::save('rate_limit.enabled', true);
        Config::save('rate_limit.max_attempts', 2);
        Config::save('rate_limit.window', 60);
        Config::save('rate_limit.ban', 120);
    }

    protected function tearDown(): void
    {
        Config::reset();

        parent::tearDown();
    }

    public function test_failures_temp_ban(): void
    {
        $calls = 0;
        $middleware = new RateLimitMiddleware($this->cache);
        $uri = '/v1/api/test/rate-limit';

        $handler = $this->getHandler(function () use (&$calls) {
            $calls++;
            return api_error('Invalid username or password.', Status::UNAUTHORIZED);
        });

        $first = $middleware->process(
            $this->getRequest(method: Method::POST, uri: $uri)->withoutHeader('Authorization'),
            $handler,
        );
        $second = $middleware->process(
            $this->getRequest(method: Method::POST, uri: $uri)->withoutHeader('Authorization'),
            $handler,
        );
        $third = $middleware->process(
            $this->getRequest(method: Method::POST, uri: $uri)->withoutHeader('Authorization'),
            $handler,
        );

        $this->assertSame(Status::UNAUTHORIZED->value, $first->getStatusCode());
        $this->assertSame(Status::TOO_MANY_REQUESTS->value, $second->getStatusCode());
        $this->assertSame(Status::TOO_MANY_REQUESTS->value, $third->getStatusCode());
        $this->assertSame('120', $second->getHeaderLine('Retry-After'));
        $this->assertNotSame('', $third->getHeaderLine('Retry-After'));
        $this->assertSame(2, $calls, 'Banned requests should not reach the auth handler.');
    }

    public function test_success_resets_counter(): void
    {
        $calls = 0;
        $middleware = new RateLimitMiddleware($this->cache);
        $uri = '/v1/api/test/rate-limit';

        $handler = $this->getHandler(function () use (&$calls) {
            $calls++;

            return match ($calls) {
                1 => api_error('Invalid username or password.', Status::UNAUTHORIZED),
                default => api_response(Status::OK),
            };
        });

        $first = $middleware->process(
            $this->getRequest(method: Method::POST, uri: $uri)->withoutHeader('Authorization'),
            $handler,
        );
        $second = $middleware->process(
            $this->getRequest(method: Method::POST, uri: $uri)->withoutHeader('Authorization'),
            $handler,
        );
        $third = $middleware->process(
            $this->getRequest(method: Method::POST, uri: $uri)->withoutHeader('Authorization'),
            $handler,
        );

        $this->assertSame(Status::UNAUTHORIZED->value, $first->getStatusCode());
        $this->assertSame(Status::OK->value, $second->getStatusCode());
        $this->assertSame(Status::OK->value, $third->getStatusCode());
    }

    public function test_failures_per_endpoint(): void
    {
        $middleware = new RateLimitMiddleware($this->cache);
        $callsA = 0;
        $callsB = 0;

        $handlerA = $this->getHandler(function () use (&$callsA) {
            $callsA++;
            return api_error('System user and password is already configured.', Status::FORBIDDEN);
        });
        $handlerB = $this->getHandler(function () use (&$callsB) {
            $callsB++;
            return api_error('Invalid current password.', Status::UNAUTHORIZED);
        });

        $first = $middleware->process(
            $this->getRequest(method: Method::POST, uri: '/v1/api/test/a')->withoutHeader('Authorization'),
            $handlerA,
        );
        $second = $middleware->process(
            $this->getRequest(method: Method::POST, uri: '/v1/api/test/a')->withoutHeader('Authorization'),
            $handlerA,
        );
        $third = $middleware->process(
            $this->getRequest(method: Method::POST, uri: '/v1/api/test/b')->withoutHeader('Authorization'),
            $handlerB,
        );

        $this->assertSame(Status::FORBIDDEN->value, $first->getStatusCode());
        $this->assertSame(Status::TOO_MANY_REQUESTS->value, $second->getStatusCode());
        $this->assertSame(Status::UNAUTHORIZED->value, $third->getStatusCode());
        $this->assertSame(2, $callsA);
        $this->assertSame(1, $callsB);
    }

    public function test_identifier_client_ip(): void
    {
        Config::save('trust.proxy', true);
        Config::save('trust.header', 'X-Forwarded-For');

        $calls = 0;
        $middleware = new RateLimitMiddleware($this->cache);
        $uri = '/v1/api/test/rate-limit';

        $handler = $this->getHandler(function () use (&$calls) {
            $calls++;
            return api_error('Invalid username or password.', Status::UNAUTHORIZED);
        });

        $first = $middleware->process(
            $this->getRequest(
                method: Method::POST,
                uri: $uri,
                headers: ['X-Forwarded-For' => '198.51.100.24'],
                server: ['REMOTE_ADDR' => '10.0.0.10', 'HTTP_X_FORWARDED_FOR' => '198.51.100.24'],
            )->withoutHeader('Authorization'),
            $handler,
        );
        $second = $middleware->process(
            $this->getRequest(
                method: Method::POST,
                uri: $uri,
                headers: ['X-Forwarded-For' => '198.51.100.24'],
                server: ['REMOTE_ADDR' => '10.0.0.11', 'HTTP_X_FORWARDED_FOR' => '198.51.100.24'],
            )->withoutHeader('Authorization'),
            $handler,
        );

        $this->assertSame(Status::UNAUTHORIZED->value, $first->getStatusCode());
        $this->assertSame(Status::TOO_MANY_REQUESTS->value, $second->getStatusCode());
        $this->assertSame(2, $calls);
    }
}
