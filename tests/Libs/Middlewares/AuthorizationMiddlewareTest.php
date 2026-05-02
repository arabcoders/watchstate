<?php

declare(strict_types=1);

namespace Tests\Libs\Middlewares;

use App\API\System\Auth;
use App\API\System\HealthCheck;
use App\Libs\Config;
use App\Libs\Enums\Http\Method;
use App\Libs\Enums\Http\Status;
use App\Libs\Middlewares\AuthorizationMiddleware;
use App\Libs\TokenUtil;
use App\Libs\TestCase;
use Tests\Support\RequestResponseTrait;

class AuthorizationMiddlewareTest extends TestCase
{
    use RequestResponseTrait;

    public function __destruct()
    {
        Config::reset();
    }

    public function test_internal_request()
    {
        $result = new AuthorizationMiddleware()->process(
            request: $this->getRequest()->withAttribute('INTERNAL_REQUEST', true),
            handler: $this->getHandler()
        );
        $this->assertSame(200, $result->getStatusCode(), 'Internal request failed');
    }

    public function test_options_request()
    {
        $result = new AuthorizationMiddleware()->process(
            request: $this->getRequest(method: Method::OPTIONS),
            handler: $this->getHandler()
        );

        $this->assertSame(Status::OK, Status::from($result->getStatusCode()), 'Options request failed');
    }

    public function test_open_routes()
    {
        Config::save('api.prefix', '/v1/api');

        $routes = [
            HealthCheck::URL,
            Auth::URL . '/test'
        ];

        $routesSemiOpen = [
            '/webhook',
        ];

        foreach ($routes as $route) {
            $uri = parse_config_value($route);
            $result = new AuthorizationMiddleware()->process(
                request: $this->getRequest(uri: $uri),
                handler: $this->getHandler()
            );
            $this->assertSame(Status::OK, Status::from($result->getStatusCode()), "Open route '{$uri}' failed");
        }

        foreach ($routesSemiOpen as $route) {
            $uri = parse_config_value($route);
            $result = new AuthorizationMiddleware()->process(
                request: $this->getRequest(uri: $uri),
                handler: $this->getHandler()
            );
            $this->assertSame(Status::OK, Status::from($result->getStatusCode()), "Open route '{$uri}' failed");
        }

        Config::save('api.secure', true);

        foreach ($routesSemiOpen as $route) {
            $uri = parse_config_value($route);
            $result = new AuthorizationMiddleware()->process(
                request: $this->getRequest(uri: $uri)->withoutHeader('Authorization'),
                handler: $this->getHandler()
            );
            $this->assertSame(
                Status::BAD_REQUEST,
                Status::from($result->getStatusCode()),
                "Route '{$uri}' should fail without API key"
            );
        }

        foreach ($routesSemiOpen as $route) {
            $uri = parse_config_value($route);
            $result = new AuthorizationMiddleware()->process(
                request: $this->getRequest(uri: $uri)->withHeader('Authorization', 'Bearer api'),
                handler: $this->getHandler()
            );
            $this->assertSame(
                Status::UNAUTHORIZED,
                Status::from($result->getStatusCode()),
                "Route '{$uri}' should fail without correct API key"
            );
        }

        Config::save('api.key', 'api_test_token');
        foreach ($routesSemiOpen as $route) {
            $uri = parse_config_value($route);
            $result = new AuthorizationMiddleware()->process(
                request: $this->getRequest(uri: $uri, query: ['apikey' => 'api_test_token'])->withHeader(
                    'X-apikey',
                    'api_test_token'
                ),
                handler: $this->getHandler()
            );
            $this->assertSame(
                Status::OK,
                Status::from($result->getStatusCode()),
                "Route '{$uri}' should pass with correct API key"
            );
        }

        Config::reset();
    }

    public function test_expired_token_rejected(): void
    {
        Config::save('api.prefix', '/v1/api');
        Config::save('system.user', 'admin');
        Config::save('system.secret', TokenUtil::generateSecret(32));
        Config::save('auth.token_expiry', 60);

        $token = $this->makeUserToken([
            'username' => 'admin',
            'iat' => time() - 120,
            'exp' => time() - 60,
            'version' => get_app_version(),
        ]);

        $result = new AuthorizationMiddleware()->process(
            request: $this->getRequest(uri: '/v1/api/protected')->withHeader('Authorization', 'Token ' . $token),
            handler: $this->getHandler(),
        );

        $this->assertSame(Status::UNAUTHORIZED, Status::from($result->getStatusCode()));

        Config::reset();
    }

    public function test_legacy_token_expiry(): void
    {
        Config::save('api.prefix', '/v1/api');
        Config::save('system.user', 'admin');
        Config::save('system.secret', TokenUtil::generateSecret(32));
        Config::save('auth.token_expiry', 60);

        $token = $this->makeUserToken([
            'username' => 'admin',
            'iat' => time() - 10,
            'version' => get_app_version(),
        ]);

        $result = new AuthorizationMiddleware()->process(
            request: $this->getRequest(uri: '/v1/api/protected')->withHeader('Authorization', 'Token ' . $token),
            handler: $this->getHandler(),
        );

        $this->assertSame(Status::OK, Status::from($result->getStatusCode()));

        Config::reset();
    }

    private function makeUserToken(array $payload): string
    {
        $json = json_encode($payload);
        $this->assertNotFalse($json, 'User token payload JSON encoding should succeed in tests.');

        return TokenUtil::encode(TokenUtil::sign($json) . '.' . $json);
    }

}
