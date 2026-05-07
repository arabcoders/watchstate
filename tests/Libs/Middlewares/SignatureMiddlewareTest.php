<?php

declare(strict_types=1);

namespace Tests\Libs\Middlewares;

use App\Libs\Config;
use App\Libs\Enums\Http\Status;
use App\Libs\Middlewares\SignatureMiddleware;
use App\Libs\TokenUtil;
use App\Libs\TestCase;
use Nyholm\Psr7\Stream;
use Tests\Support\AuthTokenTestSupport;
use Tests\Support\RequestResponseTrait;

final class SignatureMiddlewareTest extends TestCase
{
    use AuthTokenTestSupport;
    use RequestResponseTrait;

    protected function tearDown(): void
    {
        Config::reset();
        parent::tearDown();
    }

    public function test_missing(): void
    {
        $result = new SignatureMiddleware()->process(
            request: $this->requestWithBody(),
            handler: $this->getHandler(),
        );

        self::assertSame(Status::BAD_REQUEST->value, $result->getStatusCode());
    }

    public function test_default_api(): void
    {
        Config::save('api.key', 'api_test_token');

        $result = new SignatureMiddleware()->process(
            request: $this->requestWithBody(headers: [
                'X-Signature' => $this->sign('{"command":"system:tasks"}', 'api_test_token'),
                'X-apikey' => 'api_test_token',
            ]),
            handler: $this->getHandler(),
        );

        self::assertSame(Status::OK->value, $result->getStatusCode());
    }

    public function test_api(): void
    {
        Config::save('api.key', 'api_test_token');

        $result = new SignatureMiddleware()->process(
            request: $this->requestWithBody(headers: [
                'X-apikey' => 'api_test_token',
                'X-Sign-With' => 'api',
                'X-Signature' => $this->sign('{"command":"system:tasks"}', 'api_test_token'),
            ]),
            handler: $this->getHandler(),
        );

        self::assertSame(Status::OK->value, $result->getStatusCode());
    }

    public function test_token(): void
    {
        Config::save('system.user', 'admin');
        Config::save('system.secret', TokenUtil::generateSecret(32));
        Config::save('auth.token_expiry', 3600);

        $token = $this->makeSignedUserToken();
        $result = new SignatureMiddleware()->process(
            request: $this->requestWithBody(headers: [
                'Authorization' => 'Token ' . $token,
                'X-Sign-With' => 'token',
                'X-Signature' => $this->sign('{"command":"system:tasks"}', $token),
            ]),
            handler: $this->getHandler(),
        );

        self::assertSame(Status::OK->value, $result->getStatusCode());
    }

    public function test_bad(): void
    {
        Config::save('api.key', 'api_test_token');

        $result = new SignatureMiddleware()->process(
            request: $this->requestWithBody(headers: [
                'X-apikey' => 'api_test_token',
                'X-Sign-With' => 'api',
                'X-Signature' => $this->sign('{"command":"system:tasks"}', 'wrong'),
            ]),
            handler: $this->getHandler(),
        );

        self::assertSame(Status::FORBIDDEN->value, $result->getStatusCode());
    }

    private function requestWithBody(array $headers = []): \Psr\Http\Message\ServerRequestInterface
    {
        return $this->getRequest(
            method: 'POST',
            uri: '/v1/api/system/command',
            headers: array_replace([
                'Content-Type' => 'application/json',
            ], $headers),
            body: Stream::create('{"command":"system:tasks"}'),
        );
    }

    private function sign(string $body, string $secret): string
    {
        return 'sha256=' . hash_hmac('sha256', $body, $secret);
    }

    private function makeSignedUserToken(): string
    {
        return $this->makeUserToken([
            'username' => 'admin',
            'iat' => time(),
            'exp' => time() + 3600,
            'version' => get_app_version(),
        ]);
    }
}
