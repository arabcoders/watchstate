<?php

declare(strict_types=1);

namespace Tests\Libs;

use App\Libs\Config;
use App\Libs\OidcService;
use App\Libs\TestCase;
use Firebase\JWT\JWT;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Psr16Cache;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class OidcServiceTest extends TestCase
{
    private const ISSUER = 'https://issuer.example';
    private const CLIENT = 'watchstate';

    protected function setUp(): void
    {
        parent::setUp();
        Config::init([]);
    }

    public function test_available(): void
    {
        $this->assertFalse($this->service()->isAvailable());
        Config::save('auth.oidc', $this->config());
        $this->assertFalse($this->service()->isAvailable());
        Config::save('system.user', 'admin');
        Config::save('system.password', 'secret');
        $this->assertTrue($this->service()->isAvailable());
    }

    public function test_authorization_pkce(): void
    {
        Config::save('auth.oidc', $this->config());
        $cache = new Psr16Cache(new ArrayAdapter());
        $service = $this->service($cache, new MockHttpClient([
            new MockResponse($this->discovery()),
        ]));
        parse_str((string) parse_url($service->authorization()['url'], PHP_URL_QUERY), $query);
        $pending = $cache->get('oidc.pending.' . hash('sha256', (string) $query['state']));
        $this->assertSame('code', $query['response_type']);
        $this->assertSame('openid profile email', $query['scope']);
        $this->assertSame('S256', $query['code_challenge_method']);
        $this->assertSame($this->challenge((string) $pending['verifier']), $query['code_challenge']);
        $this->assertSame($query['nonce'], $pending['nonce']);
    }

    public function test_callback_consume(): void
    {
        Config::save('auth.oidc', $this->config());
        $cache = new Psr16Cache(new ArrayAdapter());
        $key = $this->keys();
        $http = new MockHttpClient([
            new MockResponse($this->discovery()),
            new MockResponse(json_encode(['id_token' => $this->token($key['private'], [
                'iss' => self::ISSUER,
                'aud' => self::CLIENT,
                'nonce' => 'nonce',
                'sub' => 'user',
            ])], JSON_THROW_ON_ERROR)),
            new MockResponse(json_encode(['keys' => [$key['jwk']]], JSON_THROW_ON_ERROR)),
        ]);
        $service = $this->service($cache, $http);
        $cache->set('oidc.pending.' . hash('sha256', 'state'), ['nonce' => 'nonce', 'verifier' => 'verifier']);
        $exchange = $service->callback('code', 'state');
        $claims = $service->consume($exchange);
        $this->assertIsArray($claims);
        $this->assertSame('user', $claims['sub']);
        $this->assertNull($service->consume($exchange));
    }

    public function test_state_replay(): void
    {
        Config::save('auth.oidc', $this->config());
        $cache = new Psr16Cache(new ArrayAdapter());
        $service = $this->service($cache, new MockHttpClient());
        $this->expectExceptionMessage('Invalid or replayed OIDC state.');
        $service->callback('code', 'missing');
    }

    /** @return array<string,array<string,mixed>> */
    public static function invalidClaims(): array
    {
        return [
            ['issuer', ['iss' => 'wrong']],
            ['audience', ['aud' => 'wrong']],
            ['nonce', ['nonce' => 'wrong']],
            ['expired', ['exp' => time() - 10]],
            ['missing_expiry', ['exp' => null]],
            ['missing_issued_at', ['iat' => null]],
            ['future_issued_at', ['iat' => time() + 300]],
            ['signature', ['signature' => true]],
        ];
    }

    #[DataProvider('invalidClaims')]
    public function test_invalid(string $case, array $change): void
    {
        Config::save('auth.oidc', $this->config());
        $cache = new Psr16Cache(new ArrayAdapter());
        $key = $this->keys();
        $claims = array_replace(['iss' => self::ISSUER, 'aud' => self::CLIENT, 'nonce' => 'nonce', 'sub' => 'user'], $change);
        $private = $change['signature'] ?? false ? $this->keys()['private'] : $key['private'];
        $service = $this->service($cache, new MockHttpClient([
            new MockResponse($this->discovery()),
            new MockResponse(json_encode(['id_token' => $this->token($private, $claims)], JSON_THROW_ON_ERROR)),
            new MockResponse(json_encode(['keys' => [$key['jwk']]], JSON_THROW_ON_ERROR)),
            new MockResponse(json_encode(['keys' => [$key['jwk']]], JSON_THROW_ON_ERROR)),
        ]));
        $cache->set('oidc.pending.' . hash('sha256', 'state'), ['nonce' => 'nonce', 'verifier' => 'verifier']);
        $this->expectException(\RuntimeException::class);
        $service->callback('code', 'state');
    }

    private function service(?Psr16Cache $cache = null, ?MockHttpClient $http = null): OidcService
    {
        return new OidcService($http ?? new MockHttpClient(), $cache ?? new Psr16Cache(new ArrayAdapter()));
    }

    private function config(): array
    {
        return [
            'issuer' => self::ISSUER,
            'client_id' => self::CLIENT,
            'client_secret' => 'secret',
            'redirect_uri' => 'https://app.example/callback',
        ];
    }

    private function discovery(): string
    {
        return json_encode([
            'issuer' => self::ISSUER,
            'authorization_endpoint' => self::ISSUER . '/authorize',
            'token_endpoint' => self::ISSUER . '/token',
            'jwks_uri' => self::ISSUER . '/keys',
        ], JSON_THROW_ON_ERROR);
    }

    private function challenge(string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }

    private function token(string $private, array $claims): string
    {
        return JWT::encode($claims + ['iat' => time(), 'exp' => time() + 300], $private, 'RS256', 'key');
    }

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
