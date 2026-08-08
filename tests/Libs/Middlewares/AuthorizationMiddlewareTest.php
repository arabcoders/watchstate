<?php

declare(strict_types=1);

namespace Tests\Libs\Middlewares;

use App\API\Backends\PlexToken;
use App\API\Player\Index as PlayerIndex;
use App\API\System\Auth;
use App\API\System\HealthCheck;
use App\API\System\StaticFiles;
use App\API\WebHook;
use App\Libs\Config;
use App\Libs\Enums\Http\Method;
use App\Libs\Enums\Http\Status;
use App\Libs\Middlewares\AuthorizationMiddleware;
use App\Libs\TestCase;
use App\Libs\TokenUtil;
use Tests\Support\AuthTokenTestSupport;
use Tests\Support\RequestResponseTrait;

class AuthorizationMiddlewareTest extends TestCase
{
    use AuthTokenTestSupport;
    use RequestResponseTrait;

    public function __destruct()
    {
        Config::reset();
    }

    public function test_internal_request()
    {
        $result = new AuthorizationMiddleware()->process(
            request: $this->getRequest()->withAttribute('INTERNAL_REQUEST', true),
            handler: $this->getHandler(),
        );
        $this->assertSame(200, $result->getStatusCode(), 'Internal request failed');
    }

    public function test_options_request()
    {
        $result = new AuthorizationMiddleware()->process(
            request: $this->getRequest(method: Method::OPTIONS),
            handler: $this->getHandler(),
        );

        $this->assertSame(Status::OK, Status::from($result->getStatusCode()), 'Options request failed');
    }

    public function test_open_routes()
    {
        Config::save('api.prefix', '/v1/api');

        $routes = [
            HealthCheck::URL,
            Auth::URL . '/test',
            Auth::URL . '/has_user',
            Auth::URL . '/signup',
            Auth::URL . '/login',
            PlayerIndex::URL . '/stream/token',
            PlexToken::URL . '/generate',
            PlexToken::URL . '/check',
            StaticFiles::URL . '/app.js',
        ];

        $routesSemiOpen = [
            WebHook::URL,
        ];

        foreach ($routes as $route) {
            $uri = parse_config_value($route);
            $result = new AuthorizationMiddleware()->process(
                request: $this->getRequest(uri: $uri)->withoutHeader('Authorization'),
                handler: $this->getHandler(),
            );
            $this->assertSame(Status::OK, Status::from($result->getStatusCode()), "Open route '{$uri}' failed");
        }

        foreach ($routesSemiOpen as $route) {
            $uri = parse_config_value($route);
            $result = new AuthorizationMiddleware()->process(
                request: $this->getRequest(uri: $uri)->withoutHeader('Authorization'),
                handler: $this->getHandler(),
            );
            $this->assertSame(Status::OK, Status::from($result->getStatusCode()), "Open route '{$uri}' failed");
        }

        Config::save('api.secure', true);

        foreach ($routesSemiOpen as $route) {
            $uri = parse_config_value($route);
            $result = new AuthorizationMiddleware()->process(
                request: $this->getRequest(uri: $uri)->withoutHeader('Authorization'),
                handler: $this->getHandler(),
            );
            $this->assertSame(
                Status::BAD_REQUEST,
                Status::from($result->getStatusCode()),
                "Route '{$uri}' should fail without API key",
            );
        }

        foreach ($routesSemiOpen as $route) {
            $uri = parse_config_value($route);
            $result = new AuthorizationMiddleware()->process(
                request: $this->getRequest(uri: $uri)->withHeader('Authorization', 'Bearer api'),
                handler: $this->getHandler(),
            );
            $this->assertSame(
                Status::UNAUTHORIZED,
                Status::from($result->getStatusCode()),
                "Route '{$uri}' should fail without correct API key",
            );
        }

        Config::save('api.key', 'api_test_token');
        foreach ($routesSemiOpen as $route) {
            $uri = parse_config_value($route);
            $result = new AuthorizationMiddleware()->process(
                request: $this->getRequest(uri: $uri, query: ['apikey' => 'api_test_token'])->withHeader(
                    'X-apikey',
                    'api_test_token',
                ),
                handler: $this->getHandler(),
            );
            $this->assertSame(
                Status::OK,
                Status::from($result->getStatusCode()),
                "Route '{$uri}' should pass with correct API key",
            );
        }

        Config::reset();
    }

    public function test_open_route_trailing_slash(): void
    {
        Config::save('api.prefix', '/v1/api');

        $result = new AuthorizationMiddleware()->process(
            request: $this->getRequest(uri: parse_config_value(Auth::URL . '/test/'))->withoutHeader('Authorization'),
            handler: $this->getHandler(),
        );

        $this->assertSame(Status::OK, Status::from($result->getStatusCode()));

        Config::reset();
    }

    public function test_webhook_suffix_protected(): void
    {
        Config::save('api.prefix', '/v1/api');
        Config::save('api.secure', false);

        $result = new AuthorizationMiddleware()->process(
            request: $this->getRequest(uri: '/v1/api/backend/plex/webhook')->withoutHeader('Authorization'),
            handler: $this->getHandler(),
        );

        $this->assertSame(Status::BAD_REQUEST, Status::from($result->getStatusCode()));

        Config::reset();
    }

    public function test_open_route_boundaries(): void
    {
        Config::save('api.prefix', '/v1/api');
        Config::save('api.secure', false);

        $routes = [
            Auth::URL . '/login/admin',
            PlayerIndex::URL . '-admin',
            PlexToken::URL . '/generate/admin',
            StaticFiles::URL . '-private',
            WebHook::URL . '/admin',
        ];

        foreach ($routes as $route) {
            $uri = parse_config_value($route);
            $result = new AuthorizationMiddleware()->process(
                request: $this->getRequest(uri: $uri)->withoutHeader('Authorization'),
                handler: $this->getHandler(),
            );

            $this->assertSame(
                Status::BAD_REQUEST,
                Status::from($result->getStatusCode()),
                "Route '{$uri}' should require authorization",
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
}
