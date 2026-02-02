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
use App\Libs\TokenUtil;
use App\Libs\Traits\APITraits;
use Psr\Http\Message\ResponseInterface as iResponse;
use Psr\Http\Message\ServerRequestInterface as iRequest;
use Throwable;

final class Auth
{
    use APITraits;

    public const string URL = '%{api.prefix}/system/auth';

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
            return api_response(Status::NO_CONTENT);
        }

        $localNet = Config::get('trust.local_net', []);
        if (true !== (bool) Config::get('trust.local', false) || count($localNet) < 1) {
            return api_response(Status::OK);
        }

        $localAddress = get_client_ip($request);

        if (false === IpUtils::checkIp($localAddress, $localNet)) {
            return api_response(Status::OK);
        }

        $payload = [
            'username' => Config::get('system.user'),
            'iat' => time(),
            'version' => get_app_version(),
        ];

        if (false === ($token = json_encode($payload))) {
            return api_error('Failed to encode token.', Status::INTERNAL_SERVER_ERROR);
        }

        return api_response(Status::OK, [
            'auto_login' => true,
            'token' => TokenUtil::encode(TokenUtil::sign($token) . '.' . $token),
        ]);
    }

    #[Get(self::URL . '/user[/]', name: 'system.auth.user')]
    public function user(iRequest $request): iResponse
    {
        $user = Config::get('system.user');
        $pass = Config::get('system.password');

        if (empty($user) || empty($pass)) {
            return api_error('System user or password is not configured.', Status::INTERNAL_SERVER_ERROR);
        }

        $token = null;
        foreach ($request->getHeader('Authorization') as $auth) {
            [$type, $value] = explode(' ', $auth, 2);
            $type = strtolower(trim($type));

            // @mago-expect lint:no-insecure-comparison
            if ('token' !== $type) {
                continue;
            }

            $token = trim($value);
            break;
        }

        if (empty($token) && ag_exists($request->getQueryParams(), AuthorizationMiddleware::TOKEN_NAME)) {
            $token = ag($request->getQueryParams(), AuthorizationMiddleware::TOKEN_NAME);
        }

        if (empty($token)) {
            return api_error('This endpoint only works with user tokens.', Status::UNAUTHORIZED);
        }

        $token = rawurldecode($token);

        try {
            $decoded = TokenUtil::decode($token);
            if (false === $decoded) {
                throw new \RuntimeException('Failed to decode token.');
            }
        } catch (Throwable) {
            return api_error('Failed to decode token.', Status::UNAUTHORIZED);
        }

        $parts = explode('.', $decoded, 2);
        if (2 !== count($parts)) {
            return api_error('Invalid token.', Status::UNAUTHORIZED);
        }

        [$signature, $payload] = $parts;

        if (false === TokenUtil::verify($payload, $signature)) {
            return api_error('Invalid token.', Status::UNAUTHORIZED);
        }

        try {
            $payload = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
            $tokenUser = ag($payload, 'username', TokenUtil::generateSecret(...));
            $systemUser = Config::get('system.user', TokenUtil::generateSecret(...));

            if (false === hash_equals($systemUser, $tokenUser)) {
                return api_error('Invalid token.', Status::UNAUTHORIZED);
            }

            return api_response(Status::OK, [
                'username' => ag($payload, 'username', '??'),
                'created_at' => make_date(ag($payload, 'iat', 0)),
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

    #[Post(self::URL . '/login[/]', name: 'system.auth.login')]
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

        $payload = [
            'username' => $system_user,
            'iat' => time(),
            'version' => get_app_version(),
        ];

        if (false === ($token = json_encode($payload))) {
            return api_error('Failed to encode token.', Status::INTERNAL_SERVER_ERROR);
        }

        $token = TokenUtil::encode(TokenUtil::sign($token) . '.' . $token);

        return api_response(Status::OK, ['token' => $token]);
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
}
