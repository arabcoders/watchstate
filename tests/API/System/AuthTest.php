<?php

declare(strict_types=1);

namespace Tests\API\System;

use App\API\System\Auth;
use App\Libs\Config;
use App\Libs\Enums\Http\Method;
use App\Libs\Enums\Http\Status;
use App\Libs\TestCase;
use App\Libs\TokenUtil;
use Tests\Support\RequestResponseTrait;

final class AuthTest extends TestCase
{
    use RequestResponseTrait;

    protected function tearDown(): void
    {
        Config::reset();

        parent::tearDown();
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

    private function makeUserToken(array $payload): string
    {
        $json = json_encode($payload);
        $this->assertNotFalse($json, 'User token payload JSON encoding should succeed in tests.');

        return TokenUtil::encode(TokenUtil::sign($json) . '.' . $json);
    }
}
