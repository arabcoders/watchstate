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

    public function test_remote_user_trusted(): void
    {
        Config::save('system.user', 'admin');
        Config::save('system.password', 'password');
        Config::save('auth.remote_user.enabled', true);
        Config::save('auth.remote_user.header', 'Remote-User');
        Config::save('auth.remote_user.trusted_proxies', ['192.0.2.0/24']);

        $result = new AuthorizationMiddleware()->process(
            $this->getRequest(
                headers: ['Remote-User' => 'proxy-user', 'X-Remote-User' => 'proxy-user'],
                server: ['REMOTE_ADDR' => '192.0.2.10', 'HTTP_X_FORWARDED_FOR' => '192.0.2.10'],
            ),
            $this->getHandler(),
        );

        $this->assertSame(Status::OK, Status::from($result->getStatusCode()));
    }

    public function test_remote_user_default_header(): void
    {
        Config::save('system.user', 'admin');
        Config::save('system.password', 'password');
        Config::save('auth.remote_user.enabled', true);
        Config::save('auth.remote_user.trusted_proxies', ['192.0.2.0/24']);

        $this->assertTrue(AuthorizationMiddleware::hasTrustedRemoteUser($this->getRequest(
            headers: ['Remote-User' => 'proxy-user'],
            server: ['REMOTE_ADDR' => '192.0.2.10', 'HTTP_X_FORWARDED_FOR' => '198.51.100.10'],
        )));
    }

    public function test_remote_user_disabled(): void
    {
        Config::save('system.user', 'admin');
        Config::save('system.password', 'password');
        Config::save('auth.remote_user.trusted_proxies', ['192.0.2.0/24']);

        $this->assertFalse(AuthorizationMiddleware::hasTrustedRemoteUser($this->getRequest(
            headers: ['Remote-User' => 'proxy-user'],
            server: ['REMOTE_ADDR' => '192.0.2.10'],
        )));
    }

    public function test_remote_user_custom_header(): void
    {
        Config::save('system.user', 'admin');
        Config::save('system.password', 'password');
        Config::save('auth.remote_user.enabled', true);
        Config::save('auth.remote_user.header', 'X-Remote-User');
        Config::save('auth.remote_user.trusted_proxies', ['192.0.2.0/24']);

        $request = $this->getRequest(
            headers: ['X-Remote-User' => 'proxy-user', 'Remote-User' => 'proxy-user'],
            server: ['REMOTE_ADDR' => '192.0.2.10'],
        );
        $this->assertTrue(AuthorizationMiddleware::hasTrustedRemoteUser($request));
        $this->assertFalse(AuthorizationMiddleware::hasTrustedRemoteUser($request->withoutHeader('X-Remote-User')));
    }

    public function test_remote_user_ipv6_trusted(): void
    {
        Config::save('system.user', 'admin');
        Config::save('system.password', 'password');
        Config::save('auth.remote_user.enabled', true);
        Config::save('auth.remote_user.trusted_proxies', ['2001:db8::/32']);

        $this->assertTrue(AuthorizationMiddleware::hasTrustedRemoteUser($this->getRequest(
            headers: ['Remote-User' => 'proxy-user'],
            server: ['REMOTE_ADDR' => '2001:db8::10'],
        )));
    }

    public function test_remote_user_missing_credentials(): void
    {
        Config::save('auth.remote_user.enabled', true);
        Config::save('auth.remote_user.trusted_proxies', ['192.0.2.0/24']);
        $request = $this->getRequest(
            headers: ['Remote-User' => 'proxy-user'],
            server: ['REMOTE_ADDR' => '192.0.2.10'],
        );

        $this->assertFalse(AuthorizationMiddleware::hasTrustedRemoteUser($request));
        Config::save('system.user', 'admin');
        $this->assertFalse(AuthorizationMiddleware::hasTrustedRemoteUser($request));
        Config::reset();
        Config::save('auth.remote_user.enabled', true);
        Config::save('auth.remote_user.trusted_proxies', ['192.0.2.0/24']);
        Config::save('system.password', 'password');
        $this->assertFalse(AuthorizationMiddleware::hasTrustedRemoteUser($request));
    }

    public function test_remote_user_duplicate_header(): void
    {
        Config::save('system.user', 'admin');
        Config::save('system.password', 'password');
        Config::save('auth.remote_user.enabled', true);
        Config::save('auth.remote_user.trusted_proxies', ['192.0.2.0/24']);

        $this->assertFalse(AuthorizationMiddleware::hasTrustedRemoteUser($this->getRequest(
            headers: ['Remote-User' => ['proxy-user', 'proxy-user']],
            server: ['REMOTE_ADDR' => '192.0.2.10'],
        )));
    }

    public function test_remote_user_rejects_untrusted_input(): void
    {
        Config::save('system.user', 'admin');
        Config::save('system.password', 'password');
        Config::save('auth.remote_user.enabled', true);
        Config::save('auth.remote_user.header', 'Remote-User');
        Config::save('auth.remote_user.trusted_proxies', ['192.0.2.0/24']);

        $this->assertFalse(AuthorizationMiddleware::hasTrustedRemoteUser($this->getRequest(
            headers: ['Remote-User' => 'proxy-user'],
            server: ['REMOTE_ADDR' => '198.51.100.10', 'HTTP_X_FORWARDED_FOR' => '192.0.2.10'],
        )));
        Config::save('auth.remote_user.trusted_proxies', []);
        $this->assertFalse(AuthorizationMiddleware::hasTrustedRemoteUser($this->getRequest(
            headers: ['Remote-User' => 'proxy-user'],
            server: ['REMOTE_ADDR' => '192.0.2.10'],
        )));
    }

    public function test_remote_user_rejects_invalid_configuration(): void
    {
        Config::save('system.user', 'admin');
        Config::save('system.password', 'password');
        Config::save('auth.remote_user.enabled', true);
        Config::save('auth.remote_user.header', 'Remote User');
        Config::save('auth.remote_user.trusted_proxies', ['192.0.2.0/24']);

        $this->assertFalse(AuthorizationMiddleware::hasTrustedRemoteUser($this->getRequest(
            headers: ['Remote-User' => 'proxy-user'],
            server: ['REMOTE_ADDR' => '192.0.2.10'],
        )));
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
            Auth::URL . '/oidc/login',
            Auth::URL . '/oidc/callback',
            Auth::URL . '/oidc/exchange',
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
            Auth::URL . '/oidc/login/admin',
            Auth::URL . '/oidc/callback/admin',
            Auth::URL . '/oidc/exchange/admin',
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
