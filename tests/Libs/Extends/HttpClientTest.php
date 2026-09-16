<?php

declare(strict_types=1);

namespace Tests\Libs\Extends;

use App\Libs\Enums\Http\Method;
use App\Libs\Extends\HttpClient;
use App\Libs\Extends\MockHttpClient;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Response\MockResponse;

final class HttpClientTest extends TestCase
{
    public function test_logger_query_params(): void
    {
        $handler = new TestHandler();
        $logger = new Logger('test', [$handler]);

        $client = new HttpClient(new MockHttpClient(new MockResponse('ok', ['http_code' => 200])));
        $client->setLogger($logger);

        $client->request(
            Method::GET,
            'https://example.test/items?userId=user-1&enableUserData=true&enableImages=false',
            ['headers' => ['Authorization' => 'secret']],
        );

        $records = $handler->getRecords();

        self::assertCount(1, $records);
        self::assertSame(
            'https://example.test/items?userId=user-1&enableUserData=true&enableImages=false',
            $records[0]['context']['url'],
        );
    }

    public function test_logger_redacts_auth_and_body(): void
    {
        $handler = new TestHandler();
        $logger = new Logger('test', [$handler]);
        $received = [];
        $source = new MockHttpClient(function (string $method, string $url, array $options) use (&$received): MockResponse {
            $received = $options;

            return new MockResponse('ok', ['http_code' => 200]);
        });
        $options = [
            'auth_basic' => ['client', 'client-secret'],
            'body' => [
                'client_secret' => 'client-secret',
                'code' => 'authorization-code',
                'code_verifier' => 'verifier',
                'access_token' => 'access-token',
                'refresh_token' => 'refresh-token',
                'id_token' => 'id-token',
                'grant_type' => 'authorization_code',
            ],
        ];
        $client = new HttpClient($source);
        $client->setLogger($logger);

        $client->request(Method::POST, 'https://example.test/token', $options);

        self::assertStringContainsString('client_secret=client-secret', $received['body']);
        self::assertStringContainsString('code=authorization-code', $received['body']);
        self::assertStringContainsString('code_verifier=verifier', $received['body']);
        self::assertContains('Authorization: Basic ' . base64_encode('client:client-secret'), $received['headers']);
        $logged = $handler->getRecords()[0]['context']['options'];
        self::assertSame('**hidden**', $logged['auth_basic']);
        self::assertSame('**hidden**', $logged['body']['client_secret']);
        self::assertSame('authorization_code', $logged['body']['grant_type']);
        foreach (['client-secret', 'authorization-code', 'access-token', 'refresh-token', 'id-token'] as $secret) {
            self::assertStringNotContainsString($secret, json_encode($logged, JSON_THROW_ON_ERROR));
        }
        self::assertSame('**hidden**', $logged['body']['code_verifier']);
    }
}
