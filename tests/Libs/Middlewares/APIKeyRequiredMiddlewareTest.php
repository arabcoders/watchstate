<?php

declare(strict_types=1);

namespace Tests\Libs\Middlewares;

use App\API\Backends\AccessToken;
use App\API\System\AutoConfig;
use App\API\System\HealthCheck;
use App\Libs\Config;
use App\Libs\Enums\Http\Method;
use App\Libs\Enums\Http\Status;
use App\Libs\Middlewares\APIKeyRequiredMiddleware;
use App\Libs\TestCase;
use Tests\Support\RequestResponseTrait;

class APIKeyRequiredMiddlewareTest extends TestCase
{
    use RequestResponseTrait;

    public function __destruct()
    {
        Config::reset();
    }

    public function test_internal_request()
    {
        $result = new APIKeyRequiredMiddleware()->process(
            request: $this->getRequest()->withAttribute('INTERNAL_REQUEST', true),
            handler: $this->getHandler()
        );
        $this->assertSame(200, $result->getStatusCode(), 'Internal request failed');
    }

    public function test_options_request()
    {
        $result = new APIKeyRequiredMiddleware()->process(
            request: $this->getRequest(method: Method::OPTIONS),
            handler: $this->getHandler()
        );

        $this->assertSame(Status::OK->value, $result->getStatusCode(), 'Options request failed');
    }

    public function test_open_routes()
    {
        $routes = [
            HealthCheck::URL,
            AutoConfig::URL,
            AccessToken::URL,
        ];

        $routesSemiOpen = [
            '/webhook',
            '%{api.prefix}/player/',
        ];

        foreach ($routes as $route) {
            $uri = parseConfigValue($route);
            $result = new APIKeyRequiredMiddleware()->process(
                request: $this->getRequest(uri: $uri),
                handler: $this->getHandler()
            );
            $this->assertSame(Status::OK->value, $result->getStatusCode(), "Open route '{$route}' failed");
        }

        foreach ($routesSemiOpen as $route) {
            $uri = parseConfigValue($route);
            $result = new APIKeyRequiredMiddleware()->process(
                request: $this->getRequest(uri: $uri),
                handler: $this->getHandler()
            );
            $this->assertSame(Status::OK->value, $result->getStatusCode(), "Open route '{$route}' failed");
        }

        Config::save('api.secure', true);

        foreach ($routesSemiOpen as $route) {
            $uri = parseConfigValue($route);
            $result = new APIKeyRequiredMiddleware()->process(
                request: $this->getRequest(uri: $uri)->withoutHeader('Authorization'),
                handler: $this->getHandler()
            );
            $this->assertSame(
                Status::BAD_REQUEST->value,
                $result->getStatusCode(),
                "Route '{$route}' should fail without API key"
            );
        }

        foreach ($routesSemiOpen as $route) {
            $uri = parseConfigValue($route);
            $result = new APIKeyRequiredMiddleware()->process(
                request: $this->getRequest(uri: $uri)->withHeader('Authorization', 'Bearer api'),
                handler: $this->getHandler()
            );
            $this->assertSame(
                Status::FORBIDDEN->value,
                $result->getStatusCode(),
                "Route '{$route}' should fail without correct API key"
            );
        }

        Config::save('api.key', 'api_test_token');
        foreach ($routesSemiOpen as $route) {
            $uri = parseConfigValue($route);
            $result = new APIKeyRequiredMiddleware()->process(
                request: $this->getRequest(uri: $uri, query: ['apikey' => 'api_test_token'])->withHeader(
                    'X-apikey',
                    'api_test_token'
                ),
                handler: $this->getHandler()
            );
            $this->assertSame(
                Status::OK->value,
                $result->getStatusCode(),
                "Route '{$route}' should pass with correct API key"
            );
        }

        Config::reset();
    }

}
