<?php

declare(strict_types=1);

namespace Tests\API\System;

use App\API\System\Auth;
use App\Libs\Config;
use App\Libs\Enums\Http\Method;
use App\Libs\Enums\Http\Status;
use App\Libs\Middlewares\AuthorizationMiddleware;
use App\Libs\OidcService;
use App\Libs\TestCase;
use App\Libs\TokenUtil;
use Firebase\JWT\JWT;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Psr16Cache;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Tests\Support\AuthTokenTestSupport;
use Tests\Support\RequestResponseTrait;

final class AuthTest extends TestCase
{
    use AuthTokenTestSupport;
    use RequestResponseTrait;

    protected function tearDown(): void
    {
        Config::reset();

        parent::tearDown();
    }

    public function test_has_user_no_cache(): void
    {
        Config::save('system.user', 'admin');
        Config::save('system.password', TokenUtil::generateSecret(32));

        $response = new Auth()->has_user($this->getRequest(), $this->oidc());

        $this->assertSame(Status::OK->value, $response->getStatusCode());
        $this->assertSame('no-store, no-cache, must-revalidate', $response->getHeaderLine('Cache-Control'));
        $this->assertSame('no-cache', $response->getHeaderLine('Pragma'));
        $this->assertSame('0', $response->getHeaderLine('Expires'));
    }

    public function test_has_user_missing(): void
    {
        Config::reset();

        $response = new Auth()->has_user($this->getRequest(), $this->oidc());

        $this->assertSame(Status::NO_CONTENT->value, $response->getStatusCode());
        $this->assertSame('no-store, no-cache, must-revalidate', $response->getHeaderLine('Cache-Control'));
        $this->assertSame('no-cache', $response->getHeaderLine('Pragma'));
        $this->assertSame('0', $response->getHeaderLine('Expires'));
    }

    public function test_has_user_oidc_availability(): void
    {
        Config::save('system.user', 'admin');
        Config::save('system.password', 'password');
        Config::init([
            'system' => ['user' => 'admin', 'password' => 'password'],
            'auth' => ['oidc' => [
                'issuer' => 'https://issuer.example',
                'client_id' => 'watchstate',
                'client_secret' => 'secret',
                'redirect_uri' => 'https://app.example/callback',
            ]],
        ]);
        Config::remove('auth.oidc.redirect_uri');

        $response = new Auth()->has_user($this->getRequest(), $this->oidc());
        $payload = json_decode((string) $response->getBody(), true);
        $this->assertFalse((bool) ag($payload, 'oidc_available'));
    }

    public function test_has_user_oidc_available(): void
    {
        Config::reset();
        Config::init([
            'system' => ['user' => 'admin', 'password' => 'password'],
            'auth' => ['oidc' => [
                'issuer' => 'https://issuer.example',
                'client_id' => 'watchstate',
                'client_secret' => 'secret',
                'redirect_uri' => 'https://app.example/callback',
            ]],
        ]);
        $payload = json_decode(
            (string) new Auth()
                ->has_user($this->getRequest(), $this->oidc())
                ->getBody(),
            true,
        );
        $this->assertTrue((bool) ag($payload, 'oidc_available'));
    }

    public function test_oidc_login_unavailable(): void
    {
        $this->assertSame(
            Status::NOT_FOUND->value,
            new Auth()
                ->oidc_login($this->oidc())
                ->getStatusCode(),
        );
    }

    public function test_oidc_login_redirect(): void
    {
        Config::save('system.user', 'admin');
        Config::save('system.password', 'password');
        $this->configureOidc();
        $oidc = $this->oidc(new MockHttpClient(new MockResponse($this->discovery())));

        $response = new Auth()->oidc_login($oidc);
        $this->assertSame(Status::FOUND->value, $response->getStatusCode());
        $this->assertStringStartsWith('https://issuer.example/authorize?', $response->getHeaderLine('Location'));
    }

    public function test_oidc_callback_error(): void
    {
        $cache = new Psr16Cache(new ArrayAdapter());
        $cache->set('oidc.pending.' . hash('sha256', 'state'), ['nonce' => 'nonce', 'verifier' => 'verifier'], 60);
        $oidc = $this->oidc(new MockHttpClient(), $cache);
        $this->assertSame(
            Status::UNAUTHORIZED->value,
            new Auth()
                ->oidc_callback(
                    $this->getRequest(query: ['error' => 'access_denied', 'state' => 'state']),
                    $oidc,
                    new NullLogger(),
                )
                ->getStatusCode(),
        );
        $this->assertNull($cache->get('oidc.pending.' . hash('sha256', 'state')));
    }

    public function test_oidc_callback_success(): void
    {
        Config::save('system.user', 'admin');
        Config::save('system.password', 'password');
        $this->configureOidc();
        $cache = new Psr16Cache(new ArrayAdapter());
        $key = $this->keys();
        $cache->set('oidc.pending.' . hash('sha256', 'state'), ['nonce' => 'nonce', 'verifier' => 'verifier']);
        $token = JWT::encode(
            [
                'iss' => 'https://issuer.example',
                'aud' => 'watchstate',
                'nonce' => 'nonce',
                'sub' => 'user',
                'iat' => time(),
                'exp' => time() + 300,
            ],
            $key['private'],
            'RS256',
            'key',
        );
        $http = new MockHttpClient([
            new MockResponse($this->discovery()),
            new MockResponse(json_encode(['id_token' => $token], JSON_THROW_ON_ERROR)),
            new MockResponse(json_encode(['keys' => [$key['jwk']]], JSON_THROW_ON_ERROR)),
        ]);
        $oidc = $this->oidc($http, $cache);

        $response = new Auth()->oidc_callback(
            $this->getRequest(query: ['code' => 'code', 'state' => 'state']),
            $oidc,
            new NullLogger(),
        );
        $this->assertSame(Status::FOUND->value, $response->getStatusCode());
        $this->assertMatchesRegularExpression('~^/auth#oidc_code=[A-Za-z0-9_-]+$~', $response->getHeaderLine('Location'));
    }

    public function test_oidc_callback_missing(): void
    {
        $this->assertSame(
            Status::BAD_REQUEST->value,
            new Auth()
                ->oidc_callback($this->getRequest(), $this->oidc(), new NullLogger())
                ->getStatusCode(),
        );
    }

    public function test_oidc_exchange_code_only(): void
    {
        Config::save('system.user', 'oidc-user');
        Config::save('system.password', 'configured');
        Config::save('system.secret', TokenUtil::generateSecret(32));
        Config::save('auth.token_expiry', 3_600);

        $cache = new Psr16Cache(new ArrayAdapter());
        $cache->set('oidc.exchange.' . hash('sha256', 'one-time-code'), ['sub' => 'provider-user']);
        $oidc = $this->oidc(new MockHttpClient(), $cache);

        $response = new Auth()->oidc_exchange(
            $this->getRequest(
                method: Method::POST,
                post: ['code' => 'one-time-code'],
            ),
            $oidc,
            new NullLogger(),
        );
        $payload = json_decode((string) $response->getBody(), true);
        $token = (string) ag($payload, 'token');

        $this->assertSame(Status::OK->value, $response->getStatusCode());
        [, $tokenPayload] = explode('.', (string) TokenUtil::decode($token), 2);
        $this->assertSame('oidc-user', ag(json_decode($tokenPayload, true), 'username'));
        $this->assertSame(
            Status::OK->value,
            new AuthorizationMiddleware()
                ->process(
                    $this->getRequest(uri: '/protected')->withHeader('Authorization', 'Token ' . $token),
                    $this->getHandler(),
                )
                ->getStatusCode(),
        );
        $this->assertSame(
            Status::UNAUTHORIZED->value,
            new Auth()
                ->oidc_exchange(
                    $this->getRequest(
                        method: Method::POST,
                        post: ['code' => 'one-time-code'],
                    ),
                    $oidc,
                    new NullLogger(),
                )
                ->getStatusCode(),
        );
    }

    public function test_has_user_remote_user(): void
    {
        Config::save('system.user', 'admin');
        Config::save('system.password', 'password');
        Config::save('system.secret', TokenUtil::generateSecret(32));
        Config::save('auth.remote_user.enabled', true);
        Config::save('auth.remote_user.header', 'Remote-User');
        Config::save('auth.remote_user.trusted_proxies', ['192.0.2.0/24']);

        $response = new Auth()->has_user($this->getRequest(
            headers: ['Remote-User' => 'proxy-user', 'X-Remote-User' => 'proxy-user'],
            server: ['REMOTE_ADDR' => '192.0.2.10'],
        ), $this->oidc());
        $payload = json_decode((string) $response->getBody(), true);

        $this->assertSame(Status::OK->value, $response->getStatusCode());
        $this->assertTrue((bool) ag($payload, 'auto_login'));
        $this->assertNotEmpty(ag($payload, 'token'));
    }

    public function test_refresh_near_expiry(): void
    {
        Config::save('system.user', 'admin');
        Config::save('system.password', TokenUtil::generateSecret(32));
        Config::save('system.secret', TokenUtil::generateSecret(32));
        Config::save('auth.token_expiry', 3_600);
        Config::save('auth.token_refresh_window', 300);

        $handler = new Auth();
        $token = $this->makeUserToken([
            'username' => 'admin',
            'iat' => time() - 3_300,
            'exp' => time() + 120,
            'version' => get_app_version(),
        ]);

        $response = $handler->refresh(
            $this->getRequest(
                method: Method::POST,
                uri: '/v1/api/system/auth/refresh',
                headers: ['Authorization' => 'Token ' . $token],
            ),
        );

        $payload = json_decode((string) $response->getBody(), true);

        $this->assertSame(Status::OK->value, $response->getStatusCode());
        $this->assertSame(true, ag($payload, 'refreshed'));
        $this->assertNotSame($token, ag($payload, 'token'));
    }

    public function test_refresh_not_near_expiry(): void
    {
        Config::save('system.user', 'admin');
        Config::save('system.password', TokenUtil::generateSecret(32));
        Config::save('system.secret', TokenUtil::generateSecret(32));
        Config::save('auth.token_expiry', 3_600);
        Config::save('auth.token_refresh_window', 300);

        $handler = new Auth();
        $token = $this->makeUserToken([
            'username' => 'admin',
            'iat' => time() - 60,
            'exp' => time() + 3_000,
            'version' => get_app_version(),
        ]);

        $response = $handler->refresh(
            $this->getRequest(
                method: Method::POST,
                uri: '/v1/api/system/auth/refresh',
                headers: ['Authorization' => 'Token ' . $token],
            ),
        );

        $payload = json_decode((string) $response->getBody(), true);

        $this->assertSame(Status::OK->value, $response->getStatusCode());
        $this->assertSame(false, ag($payload, 'refreshed'));
        $this->assertSame($token, ag($payload, 'token'));
    }

    private function oidc(?MockHttpClient $http = null, ?Psr16Cache $cache = null): OidcService
    {
        return new OidcService($http ?? new MockHttpClient(), $cache ?? new Psr16Cache(new ArrayAdapter()));
    }

    private function configureOidc(): void
    {
        Config::save('auth.oidc', [
            'issuer' => 'https://issuer.example',
            'client_id' => 'watchstate',
            'client_secret' => 'secret',
            'redirect_uri' => 'https://app.example/callback',
        ]);
    }

    private function discovery(): string
    {
        return json_encode([
            'issuer' => 'https://issuer.example',
            'authorization_endpoint' => 'https://issuer.example/authorize',
            'token_endpoint' => 'https://issuer.example/token',
            'jwks_uri' => 'https://issuer.example/keys',
        ], JSON_THROW_ON_ERROR);
    }

    /** @return array{private:string,jwk:array<string,string>} */
    private function keys(): array
    {
        $private = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
        openssl_pkey_export($private, $pem);
        $details = openssl_pkey_get_details($private);
        return [
            'private' => $pem,
            'jwk' => [
                'kty' => 'RSA',
                'kid' => 'key',
                'alg' => 'RS256',
                'use' => 'sig',
                'n' => rtrim(strtr(base64_encode($details['rsa']['n']), '+/', '-_'), '='),
                'e' => rtrim(strtr(base64_encode($details['rsa']['e']), '+/', '-_'), '='),
            ],
        ];
    }
}
