<?php

declare(strict_types=1);

namespace App\API\System;

use App\Libs\Attributes\Route\Delete;
use App\Libs\Attributes\Route\Get;
use App\Libs\Attributes\Route\Post;
use App\Libs\Attributes\Route\Put;
use App\Libs\Config;
use App\Libs\DataUtil;
use App\Libs\Enums\Http\Method;
use App\Libs\Enums\Http\Status;
use App\Libs\IpUtils;
use App\Libs\Middlewares\AuthorizationMiddleware;
use App\Libs\Middlewares\RateLimitMiddleware;
use App\Libs\TokenUtil;
use App\Libs\Traits\APITraits;
use Psr\Http\Message\ResponseInterface as iResponse;
use Psr\Http\Message\ServerRequestInterface as iRequest;
use SensitiveParameter;
use Throwable;

final class Auth
{
    use APITraits;

    public const string URL = '%{api.prefix}/system/auth';

    private const array NO_CACHE_HEADERS = [
        'Cache-Control' => 'no-store, no-cache, must-revalidate',
        'Pragma' => 'no-cache',
        'Expires' => '0',
    ];

    #[Get(self::URL . '/test[/]', name: 'system.auth.test')]
    public function test_open(): iResponse
    {
        return api_response(Status::OK);
    }

    #[Get(self::URL . '/has_user[/]', name: 'system.auth.has_user')]
    public function has_user(iRequest $request): iResponse
    {
        $user = Config::get('system.user');
        $password = Config::get('system.password');

        if (empty($user) || empty($password)) {
            return api_response(Status::NO_CONTENT, headers: self::NO_CACHE_HEADERS);
        }

        $localNet = Config::get('trust.local_net', []);
        if (true !== (bool) Config::get('trust.local', false) || count($localNet) < 1) {
            return api_response(Status::OK, headers: self::NO_CACHE_HEADERS);
        }

        $localAddress = get_client_ip($request);

        if (false === IpUtils::checkIp($localAddress, $localNet)) {
            return api_response(Status::OK, headers: self::NO_CACHE_HEADERS);
        }

        try {
            $token = $this->makeToken((string) Config::get('system.user'));
        } catch (Throwable) {
            return api_error('Failed to encode token.', Status::INTERNAL_SERVER_ERROR, headers: self::NO_CACHE_HEADERS);
        }

        return api_response(Status::OK, ['auto_login' => true, 'token' => $token], self::NO_CACHE_HEADERS);
    }

    #[Get(self::URL . '/user[/]', name: 'system.auth.user')]
    public function user(iRequest $request): iResponse
    {
        $user = Config::get('system.user');
        $pass = Config::get('system.password');

        if (empty($user) || empty($pass)) {
            return api_error('System user or password is not configured.', Status::INTERNAL_SERVER_ERROR);
        }

        $token = $this->getRequestToken($request);

        if (empty($token)) {
            return api_error('This endpoint only works with user tokens.', Status::UNAUTHORIZED);
        }

        $token = rawurldecode($token);

        if (false === AuthorizationMiddleware::validateToken($token)) {
            return api_error('Invalid or expired token.', Status::UNAUTHORIZED);
        }

        try {
            $decoded = TokenUtil::decode($token);
            if (false === $decoded) {
                throw new \RuntimeException('Failed to decode token.');
            }

            [, $payload] = explode('.', $decoded, 2);
            $payload = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
            $expiresAt = $this->getTokenExpiresAt($payload);

            return api_response(Status::OK, [
                'username' => ag($payload, 'username', '??'),
                'created_at' => make_date(ag($payload, 'iat', 0)),
                'expires_at' => make_date($expiresAt),
                'refresh_required' => $this->shouldRefreshToken($payload),
            ]);
        } catch (Throwable) {
            return api_error('Failed to decode payload.', Status::UNAUTHORIZED);
        }
    }

    #[Post(self::URL . '/signup[/]', name: 'system.auth.signup')]
    public function signup(iRequest $request): iResponse
    {
        $user = Config::get('system.user');
        $pass = Config::get('system.password');

        if (!empty($user) && !empty($pass)) {
            return api_error('System user and password is already configured.', Status::FORBIDDEN);
        }

        $data = DataUtil::fromRequest($request);

        $username = $data->get('username');
        $password = $data->get('password');

        if (empty($username) || empty($password)) {
            return api_error('Username and password are required.', Status::BAD_REQUEST);
        }

        $response = api_request(Method::POST, '/system/env/WS_SYSTEM_PASSWORD', ['value' => $password]);
        if (Status::OK !== $response->status) {
            $message = r('Failed to set system password. {status}: {message}', [
                'status' => $response->status->value,
                'message' => ag($response->body, 'error.message', 'Unknown error.'),
            ]);
            return api_error($message, $response->status);
        }

        $response = api_request(Method::POST, '/system/env/WS_SYSTEM_USER', ['value' => $username]);

        if (Status::OK !== $response->status) {
            $message = r('Failed to set system user. {status}: {message}', [
                'status' => $response->status->value,
                'message' => ag($response->body, 'error.message', 'Unknown error.'),
            ]);
            return api_error($message, $response->status);
        }

        return api_response(Status::CREATED);
    }

    #[Post(self::URL . '/login[/]', middleware: RateLimitMiddleware::class, name: 'system.auth.login')]
    public function login(iRequest $request): iResponse
    {
        $data = DataUtil::fromRequest($request);

        $username = $data->get('username');
        $password = $data->get('password');

        if (empty($username) || empty($password)) {
            return api_error('Username and password are required.', Status::BAD_REQUEST);
        }

        $system_user = Config::get('system.user');
        $system_pass = Config::get('system.password');

        if (empty($system_user) || empty($system_pass)) {
            return api_error('System user or password is not configured.', Status::INTERNAL_SERVER_ERROR);
        }

        $system_pass = after($system_pass, Config::get('password.prefix', 'ws_hash@:'));

        $validUser = true === hash_equals($username, $system_user);
        $validPass = password_verify($password, $system_pass);

        if (false === $validUser || false === $validPass) {
            return api_error('Invalid username or password.', Status::UNAUTHORIZED);
        }

        $algo = Config::get('password.algo', PASSWORD_BCRYPT);
        $opts = Config::get('password.options', []);

        if (true === password_needs_rehash($system_pass, $algo, $opts)) {
            api_request(Method::POST, '/system/env/WS_SYSTEM_PASSWORD', ['value' => $password]);
        }

        try {
            $token = $this->makeToken($system_user);
        } catch (Throwable) {
            return api_error('Failed to encode token.', Status::INTERNAL_SERVER_ERROR);
        }

        return api_response(Status::OK, ['token' => $token]);
    }

    #[Post(self::URL . '/refresh[/]', name: 'system.auth.refresh')]
    public function refresh(iRequest $request): iResponse
    {
        $user = Config::get('system.user');
        $pass = Config::get('system.password');

        if (empty($user) || empty($pass)) {
            return api_error('System user or password is not configured.', Status::INTERNAL_SERVER_ERROR);
        }

        $token = $this->getRequestToken($request);

        if (empty($token)) {
            return api_error('This endpoint only works with user tokens.', Status::UNAUTHORIZED);
        }

        $token = rawurldecode($token);

        if (false === AuthorizationMiddleware::validateToken($token)) {
            return api_error('Invalid or expired token.', Status::UNAUTHORIZED);
        }

        try {
            $payload = $this->getTokenPayload($token);
            $username = (string) ag($payload, 'username', $user);
            $createdAt = (int) ag($payload, 'iat', 0);
            $expiresAt = $this->getTokenExpiresAt($payload);
            $refreshed = false;

            if ($this->shouldRefreshToken($payload)) {
                $token = $this->makeToken($username);
                $payload = $this->getTokenPayload($token);
                $createdAt = (int) ag($payload, 'iat', 0);
                $expiresAt = $this->getTokenExpiresAt($payload);
                $refreshed = true;
            }

            return api_response(Status::OK, [
                'token' => $token,
                'username' => $username,
                'created_at' => make_date($createdAt),
                'expires_at' => make_date($expiresAt),
                'refreshed' => $refreshed,
            ]);
        } catch (Throwable) {
            return api_error('Failed to decode payload.', Status::UNAUTHORIZED);
        }
    }

    #[Put(self::URL . '/change_password[/]', name: 'system.auth.change_password')]
    public function change_password(iRequest $request): iResponse
    {
        $data = DataUtil::fromRequest($request);

        $current_password = $data->get('current_password');
        $new_password = $data->get('new_password');

        if (empty($new_password) || empty($current_password)) {
            return api_error('current_password and new_password fields are required.', Status::BAD_REQUEST);
        }

        $system_pass = Config::get('system.password');

        if (empty($system_pass)) {
            return api_error('System password is not configured.', Status::INTERNAL_SERVER_ERROR);
        }

        $system_pass = after($system_pass, Config::get('password.prefix', 'ws_hash@:'));

        if (false === password_verify($current_password, $system_pass)) {
            return api_error('Invalid current password.', Status::UNAUTHORIZED);
        }

        $response = api_request(Method::POST, '/system/env/WS_SYSTEM_PASSWORD', ['value' => $new_password]);
        if (Status::OK !== $response->status) {
            return api_error('Failed to set new password.', Status::INTERNAL_SERVER_ERROR);
        }

        return api_message('Password changed successfully.', Status::OK);
    }

    #[Delete(self::URL . '/sessions[/]', name: 'system.auth.sessions')]
    public function invalidate_sessions(): iResponse
    {
        $response = api_request(Method::POST, '/system/env/WS_SYSTEM_SECRET', ['value' => TokenUtil::generateSecret()]);
        if (Status::OK !== $response->status) {
            return api_error('Failed to invalidate sessions.', Status::INTERNAL_SERVER_ERROR);
        }

        return api_message('Sessions invalidated successfully.', Status::OK);
    }

    private function makeToken(string $username): string
    {
        $issuedAt = time();
        $payload = [
            'username' => $username,
            'iat' => $issuedAt,
            'exp' => $issuedAt + $this->getTokenExpiry(),
            'version' => get_app_version(),
        ];

        if (false === ($token = json_encode($payload))) {
            throw new \RuntimeException('Failed to encode token.');
        }

        return TokenUtil::encode(TokenUtil::sign($token) . '.' . $token);
    }

    private function getTokenExpiry(): int
    {
        return max(1, (int) Config::get('auth.token_expiry'));
    }

    private function getTokenRefreshWindow(): int
    {
        return max(1, (int) Config::get('auth.token_refresh_window', min(86_400, $this->getTokenExpiry())));
    }

    private function getTokenExpiresAt(array $payload): int
    {
        $issuedAt = (int) ag($payload, 'iat', 0);

        return (int) ag(
            $payload,
            'exp',
            $issuedAt > 0 ? $issuedAt + $this->getTokenExpiry() : 0,
        );
    }

    private function shouldRefreshToken(array $payload): bool
    {
        $expiresAt = $this->getTokenExpiresAt($payload);

        if ($expiresAt < 1) {
            return false;
        }

        return ($expiresAt - time()) <= $this->getTokenRefreshWindow();
    }

    private function getRequestToken(iRequest $request): ?string
    {
        foreach ($request->getHeader('Authorization') as $auth) {
            [$type, $value] = explode(' ', $auth, 2);
            $type = strtolower(trim($type));

            // @mago-expect lint:no-insecure-comparison
            if ('token' !== $type) {
                continue;
            }

            return trim($value);
        }

        if (ag_exists($request->getQueryParams(), AuthorizationMiddleware::TOKEN_NAME)) {
            return (string) ag($request->getQueryParams(), AuthorizationMiddleware::TOKEN_NAME);
        }

        return null;
    }

    private function getTokenPayload(#[SensitiveParameter] string $token): array
    {
        if (false === ($decoded = TokenUtil::decode($token))) {
            throw new \RuntimeException('Failed to decode token.');
        }

        [, $payload] = explode('.', $decoded, 2);

        return json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
    }
}
