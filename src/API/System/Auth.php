<?php

declare(strict_types=1);

namespace App\API\System;

use App\Libs\Attributes\Route\Get;
use App\Libs\Attributes\Route\Post;
use App\Libs\Config;
use App\Libs\DataUtil;
use App\Libs\Enums\Http\Method;
use App\Libs\Enums\Http\Status;
use App\Libs\TokenUtil;
use App\Libs\Traits\APITraits;
use Psr\Http\Message\ResponseInterface as iResponse;
use Psr\Http\Message\ServerRequestInterface as iRequest;

final class Auth
{
    use APITraits;

    public const string URL = '%{api.prefix}/system/auth';

    #[Get(self::URL . '/has_user[/]', name: 'system.auth.has_user')]
    public function has_user(): iResponse
    {
        $user = Config::get('system.user');
        $password = Config::get('system.password');

        return api_response(empty($user) || empty($password) ? Status::NO_CONTENT : Status::OK);
    }

    #[Post(self::URL . '/signup[/]', name: 'system.auth.signup')]
    public function do_signup(iRequest $request): iResponse
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

        $response = APIRequest(Method::POST, '/system/env/WS_SYSTEM_PASSWORD', ['value' => $password]);
        if (Status::OK !== $response->status) {
            $message = r("Failed to set system password. {status}: {message}", [
                'status' => $response->status->value,
                'message' => ag($response->body, 'error.message', 'Unknown error.')
            ]);
            return api_error($message, $response->status);
        }

        $response = APIRequest(Method::POST, '/system/env/WS_SYSTEM_USER', ['value' => $username]);

        if (Status::OK !== $response->status) {
            $message = r("Failed to set system user. {status}: {message}", [
                'status' => $response->status->value,
                'message' => ag($response->body, 'error.message', 'Unknown error.')
            ]);
            return api_error($message, $response->status);
        }

        return api_response(Status::CREATED);
    }

    #[Post(self::URL . '/login[/]', name: 'system.auth.login')]
    public function do_login(iRequest $request): iResponse
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

        $validUser = true === hash_equals($username, $system_user);
        $validPass = password_verify(
            $password,
            after($system_pass, Config::get('password.prefix', 'ws_hash@:'))
        );

        if (false === $validUser || false === $validPass) {
            return api_error('Invalid username or password.', Status::UNAUTHORIZED);
        }

        $payload = [
            'username' => $system_user,
            'iat' => time(),
            'version' => getAppVersion(),
        ];

        if (false === ($token = json_encode($payload))) {
            return api_error('Failed to encode token.', Status::INTERNAL_SERVER_ERROR);
        }

        if (false === ($token = TokenUtil::encode(TokenUtil::sign($token) . '.' . $token))) {
            return api_error('Failed to sign token.', Status::INTERNAL_SERVER_ERROR);
        }

        return api_response(Status::OK, ['token' => $token]);
    }
}
