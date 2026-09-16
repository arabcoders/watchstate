<?php

declare(strict_types=1);

namespace App\Libs;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Psr\SimpleCache\CacheInterface;
use RuntimeException;
use SensitiveParameter;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

final class OidcService
{
    private const SCOPES = 'openid profile email';

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly CacheInterface $cache,
    ) {}

    /**
     * Check whether OIDC and the linked local account are configured.
     *
     * @return bool True when OIDC login is available.
     */
    public function isAvailable(): bool
    {
        return (
            $this->config() !== null
            && '' !== trim((string) Config::get('system.user', ''))
            && '' !== trim((string) Config::get('system.password', ''))
        );
    }

    /**
     * Create an OIDC authorization request.
     *
     * @return array{url:string} The provider authorization URL.
     */
    public function authorization(): array
    {
        $config = $this->requireConfig();
        $discovery = $this->discovery($config['issuer']);
        $state = $this->random();
        $nonce = $this->random();
        $verifier = TokenUtil::encode(random_bytes(32));
        $challenge = TokenUtil::encode(hash('sha256', $verifier, true));
        $this->cache->set('oidc.pending.' . hash('sha256', $state), compact('nonce', 'verifier'), 300);

        return [
            'url' => $discovery['authorization_endpoint']
                . '?'
                . http_build_query(
                    [
                        'response_type' => 'code',
                        'client_id' => $config['client_id'],
                        'redirect_uri' => $config['redirect_uri'],
                        'scope' => self::SCOPES,
                        'state' => $state,
                        'nonce' => $nonce,
                        'code_challenge' => $challenge,
                        'code_challenge_method' => 'S256',
                    ],
                    '',
                    '&',
                    PHP_QUERY_RFC3986,
                ),
        ];
    }

    /**
     * Validate an OIDC callback and create a frontend exchange code.
     *
     * @param string $code The provider authorization code.
     * @param string $state The authorization state.
     *
     * @return string The short-lived exchange code.
     */
    public function callback(string $code, string $state): string
    {
        $config = $this->requireConfig();
        $pendingKey = 'oidc.pending.' . hash('sha256', $state);
        $pending = $this->consumeKey($pendingKey);
        if (!is_array($pending) || !isset($pending['nonce'], $pending['verifier'])) {
            throw new RuntimeException('Invalid or replayed OIDC state.');
        }
        $discovery = $this->discovery($config['issuer']);
        $response = $this->http->request('POST', $discovery['token_endpoint'], [
            'body' => [
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => $config['redirect_uri'],
                'code_verifier' => $pending['verifier'],
            ],
            'auth_basic' => [$config['client_id'], $config['client_secret']],
        ])->toArray();
        if (!is_string($response['id_token'] ?? null)) {
            throw new RuntimeException('OIDC response did not contain an ID token.');
        }
        $claims = $this->validate((string) $response['id_token'], $discovery, $config, (string) $pending['nonce']);
        $exchange = $this->random();
        $this->cache->set('oidc.exchange.' . hash('sha256', $exchange), $claims, 60);
        return $exchange;
    }

    /**
     * Consume a frontend exchange code.
     *
     * @param string $exchange The exchange code.
     *
     * @return array<string,mixed>|null The validated ID token claims, or null when invalid.
     */
    public function consume(string $exchange): ?array
    {
        return $this->consumeKey('oidc.exchange.' . hash('sha256', $exchange));
    }

    /**
     * Cancel a pending authorization state.
     *
     * @param string $state The authorization state.
     */
    public function cancel(string $state): void
    {
        $this->consumeKey('oidc.pending.' . hash('sha256', $state));
    }

    /**
     * Read the OIDC client configuration.
     *
     * @return array{issuer:string,client_id:string,client_secret:string,redirect_uri:string}|null
     */
    private function config(): ?array
    {
        $oidc = Config::get('auth.oidc', []);
        if (!is_array($oidc)) {
            return null;
        }

        $values = [];
        foreach (['issuer', 'client_id', 'client_secret', 'redirect_uri'] as $key) {
            if (!is_string($oidc[$key] ?? null) || '' === trim($oidc[$key])) {
                return null;
            }
            $values[$key] = trim($oidc[$key]);
        }

        return $values;
    }

    /**
     * Read the configured OIDC client or fail.
     *
     * @return array{issuer:string,client_id:string,client_secret:string,redirect_uri:string} The OIDC client configuration.
     */
    private function requireConfig(): array
    {
        $config = $this->config();
        if (null === $config) {
            throw new RuntimeException('OIDC is not configured.');
        }
        return $config;
    }

    /**
     * Load the provider discovery document.
     *
     * @param string $issuer The configured issuer.
     *
     * @return array<string,mixed> The validated discovery document.
     */
    private function discovery(string $issuer): array
    {
        $key = 'oidc.discovery.' . hash('sha256', $issuer);
        $data = $this->cache->get($key);
        if (!is_array($data)) {
            $data = $this->http->request('GET', rtrim($issuer, '/') . '/.well-known/openid-configuration')->toArray();
            $this->cache->set($key, $data, 3600);
        }
        foreach (['issuer', 'authorization_endpoint', 'token_endpoint', 'jwks_uri'] as $required) {
            if (!is_string($data[$required] ?? null) || '' === trim($data[$required])) {
                throw new RuntimeException('OIDC discovery document is missing a required endpoint.');
            }
        }
        if ($data['issuer'] !== $issuer) {
            throw new RuntimeException('OIDC discovery issuer does not match configuration.');
        }
        return $data;
    }

    /**
     * Validate an OIDC ID token.
     *
     * @param string $token The encoded ID token.
     * @param array<string,mixed> $discovery The provider discovery document.
     * @param array<string,mixed> $config The OIDC client configuration.
     * @param string $nonce The expected nonce.
     *
     * @return array<string,mixed> The validated claims.
     */
    private function validate(#[SensitiveParameter] string $token, array $discovery, array $config, string $nonce): array
    {
        $parts = explode('.', $token);
        $encodedHeader = $parts[0] ?? '';
        $headerJson = '';
        if (3 !== count($parts) || '' === $encodedHeader || false === ($headerJson = TokenUtil::decode($encodedHeader))) {
            throw new RuntimeException('Invalid OIDC token header.');
        }
        $header = json_decode($headerJson, true);
        $supportedAlgorithms = ['RS256', 'RS384', 'RS512', 'PS256', 'PS384', 'PS512', 'ES256', 'ES384', 'ES512'];
        if (isset($discovery['id_token_signing_alg_values_supported'])) {
            $supportedAlgorithms = array_values(array_intersect(
                $supportedAlgorithms,
                is_array($discovery['id_token_signing_alg_values_supported'])
                    ? $discovery['id_token_signing_alg_values_supported']
                    : [],
            ));
        }
        if (!is_array($header) || !in_array($header['alg'] ?? '', $supportedAlgorithms, true)) {
            throw new RuntimeException('Unsupported OIDC signing algorithm.');
        }
        $jwksKey = 'oidc.jwks.' . hash('sha256', (string) $discovery['jwks_uri']);
        $jwks = $this->cache->get($jwksKey);
        if (!is_array($jwks)) {
            $jwks = $this->http->request('GET', $discovery['jwks_uri'])->toArray();
            $this->cache->set($jwksKey, $jwks, 3600);
        }
        try {
            $claims = (array) JWT::decode($token, JWK::parseKeySet($jwks));
        } catch (Throwable $e) {
            // Providers may rotate signing keys while the cached JWKS is still fresh.
            $jwks = $this->http->request('GET', $discovery['jwks_uri'])->toArray();
            $this->cache->set($jwksKey, $jwks, 3600);
            try {
                $claims = (array) JWT::decode($token, JWK::parseKeySet($jwks));
            } catch (Throwable $refreshException) {
                throw new RuntimeException('Invalid OIDC ID token.', 0, $refreshException);
            }
        }
        $audience = $claims['aud'] ?? null;
        $audienceValid = $audience === $config['client_id'] || is_array($audience) && in_array($config['client_id'], $audience, true);
        if (
            ($claims['iss'] ?? null) !== $config['issuer']
            || !$audienceValid
            || ($claims['nonce'] ?? null) !== $nonce
            || !is_int($claims['exp'])
            || !is_int($claims['iat'])
            || !is_string($claims['sub'])
            || '' === trim($claims['sub'])
        ) {
            throw new RuntimeException('Invalid OIDC ID token claims.');
        }
        if (is_array($audience) && count($audience) > 1 && ($claims['azp'] ?? null) !== $config['client_id']) {
            throw new RuntimeException('Invalid OIDC authorized party.');
        }
        if (isset($claims['azp']) && $claims['azp'] !== $config['client_id']) {
            throw new RuntimeException('Invalid OIDC authorized party.');
        }
        return $claims;
    }

    private function random(): string
    {
        return TokenUtil::encode(random_bytes(24));
    }

    /**
     * Read and delete a cached one-time value.
     *
     * @param string $key The cache key.
     *
     * @return array<string,mixed>|null The cached value, or null when missing.
     */
    private function consumeKey(string $key): ?array
    {
        $value = $this->cache->get($key);
        $this->cache->delete($key);
        return is_array($value) ? $value : null;
    }
}
