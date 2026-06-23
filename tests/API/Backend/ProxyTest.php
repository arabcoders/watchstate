<?php

declare(strict_types=1);

namespace Tests\API\Backend;

use App\API\Backend\Proxy;
use App\Backends\Common\Error;
use App\Backends\Common\Levels;
use App\Backends\Common\Response;
use App\Libs\APIResponse;
use App\Libs\Config;
use App\Libs\Enums\Http\Method;
use App\Libs\Enums\Http\Status;
use App\Libs\Mappers\ImportInterface as iImport;
use App\Libs\Stream;
use App\Libs\TestCase;
use Monolog\Logger;
use Tests\Support\FakeBackendClient;
use Tests\Support\RequestResponseTrait;

final class ProxyTest extends TestCase
{
    use RequestResponseTrait;

    protected function setUp(): void
    {
        parent::setUp();

        FakeBackendClient::reset();

        $this->initTempApp();
        $this->seedTestServersConfig();
        Config::save('supported.plex', FakeBackendClient::class);
    }

    public function test_proxies_get_request(): void
    {
        FakeBackendClient::setProxyResponse(
            user: 'main',
            backend: 'test_plex',
            response: new Response(
                status: true,
                response: new APIResponse(
                    status: Status::OK,
                    headers: ['content-type' => ['application/json']],
                    stream: Stream::create('{"ok":true}'),
                ),
            ),
        );

        $handler = new Proxy($this->createStub(iImport::class), new Logger('test'));

        $response = $handler(
            $this->getRequest(
                method: Method::POST,
                uri: '/v1/api/backend/test_plex/proxy',
                post: [
                    'method' => 'GET',
                    'path' => '/library/sections',
                    'query' => ['type' => 'movie'],
                ],
            ),
            'test_plex',
        );

        self::assertSame(Status::OK->value, $response->getStatusCode());

        $payload = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame('GET', ag($payload, 'request.method'));
        self::assertSame(
            'https://plex.example.invalid/library/sections?type=movie',
            ag($payload, 'request.url'),
        );
        self::assertSame(200, ag($payload, 'response.status'));
        self::assertSame('{"ok":true}', ag($payload, 'response.body'));
        self::assertSame('application/json', ag($payload, 'response.headers.content-type'));

        $calls = FakeBackendClient::getCalls('proxy');
        self::assertCount(1, $calls, 'Proxy call should be recorded once.');
        self::assertSame('GET', $calls[0]['method']);
        self::assertSame(
            'https://plex.example.invalid/library/sections?type=movie',
            $calls[0]['url'],
        );
    }

    public function test_proxies_post_with_body(): void
    {
        FakeBackendClient::setProxyResponse(
            user: 'main',
            backend: 'test_plex',
            response: new Response(
                status: true,
                response: new APIResponse(
                    status: Status::OK,
                    stream: Stream::create('{}'),
                ),
            ),
        );

        $handler = new Proxy($this->createStub(iImport::class), new Logger('test'));

        $body = ['title' => 'Watchlist', 'type' => 'movie'];

        $handler(
            $this->getRequest(
                method: Method::POST,
                uri: '/v1/api/backend/test_plex/proxy',
                post: [
                    'method' => 'POST',
                    'path' => '/playlists',
                    'body' => $body,
                ],
            ),
            'test_plex',
        );

        $calls = FakeBackendClient::getCalls('proxy');
        self::assertSame($body, $calls[0]['body'], 'POST body should be forwarded to the backend.');
    }

    public function test_get_drops_body(): void
    {
        FakeBackendClient::setProxyResponse('main', 'test_plex', new Response(status: true));

        $handler = new Proxy($this->createStub(iImport::class), new Logger('test'));

        $handler(
            $this->getRequest(
                method: Method::POST,
                uri: '/v1/api/backend/test_plex/proxy',
                post: [
                    'method' => 'GET',
                    'path' => '/items',
                    'body' => ['should' => 'be_dropped'],
                ],
            ),
            'test_plex',
        );

        $calls = FakeBackendClient::getCalls('proxy');
        self::assertSame([], $calls[0]['body'], 'GET requests must not forward a body.');
    }

    public function test_rejects_trace_method(): void
    {
        $handler = new Proxy($this->createStub(iImport::class), new Logger('test'));

        $response = $handler(
            $this->getRequest(
                method: Method::POST,
                uri: '/v1/api/backend/test_plex/proxy',
                post: [
                    'method' => 'TRACE',
                    'path' => '/',
                ],
            ),
            'test_plex',
        );

        self::assertSame(Status::BAD_REQUEST->value, $response->getStatusCode());
        self::assertSame([], FakeBackendClient::getCalls('proxy'), 'TRACE must never reach the backend.');
    }

    public function test_rejects_empty_path(): void
    {
        $handler = new Proxy($this->createStub(iImport::class), new Logger('test'));

        $response = $handler(
            $this->getRequest(
                method: Method::POST,
                uri: '/v1/api/backend/test_plex/proxy',
                post: ['method' => 'GET', 'path' => '   '],
            ),
            'test_plex',
        );

        self::assertSame(Status::BAD_REQUEST->value, $response->getStatusCode());
    }

    public function test_rejects_unknown_backend(): void
    {
        $handler = new Proxy($this->createStub(iImport::class), new Logger('test'));

        $response = $handler(
            $this->getRequest(
                method: Method::POST,
                uri: '/v1/api/backend/missing/proxy',
                post: ['method' => 'GET', 'path' => '/'],
            ),
            'missing',
        );

        self::assertSame(Status::NOT_FOUND->value, $response->getStatusCode());
    }

    public function test_rejects_absolute_url_path(): void
    {
        $handler = new Proxy($this->createStub(iImport::class), new Logger('test'));

        $response = $handler(
            $this->getRequest(
                method: Method::POST,
                uri: '/v1/api/backend/test_plex/proxy',
                post: ['method' => 'GET', 'path' => 'http://evil.example.invalid/'],
            ),
            'test_plex',
        );

        self::assertSame(Status::BAD_REQUEST->value, $response->getStatusCode());
        self::assertSame([], FakeBackendClient::getCalls('proxy'));
    }

    public function test_rejects_protocol_relative_path(): void
    {
        $handler = new Proxy($this->createStub(iImport::class), new Logger('test'));

        $response = $handler(
            $this->getRequest(
                method: Method::POST,
                uri: '/v1/api/backend/test_plex/proxy',
                post: ['method' => 'GET', 'path' => '//evil.example.invalid/'],
            ),
            'test_plex',
        );

        self::assertSame(Status::BAD_REQUEST->value, $response->getStatusCode());
        self::assertSame([], FakeBackendClient::getCalls('proxy'));
    }

    public function test_strips_denylist_headers(): void
    {
        FakeBackendClient::setProxyResponse('main', 'test_plex', new Response(status: true));

        $handler = new Proxy($this->createStub(iImport::class), new Logger('test'));

        $handler(
            $this->getRequest(
                method: Method::POST,
                uri: '/v1/api/backend/test_plex/proxy',
                post: [
                    'method' => 'GET',
                    'path' => '/',
                    'headers' => [
                        'Accept' => 'application/json',
                        'X-Plex-Token' => 'stolen',
                        'authorization' => 'Bearer stolen',
                        'X-Emby-Authorization' => 'stolen',
                        'Cookie' => 'session=stolen',
                    ],
                ],
            ),
            'test_plex',
        );

        $calls = FakeBackendClient::getCalls('proxy');
        $forwarded = $calls[0]['headers'];

        self::assertArrayHasKey('Accept', $forwarded, 'Non-auth header should be forwarded.');
        self::assertArrayNotHasKey('X-Plex-Token', $forwarded);
        self::assertArrayNotHasKey('authorization', $forwarded);
        self::assertArrayNotHasKey('X-Emby-Authorization', $forwarded);
        self::assertArrayNotHasKey('Cookie', $forwarded);
    }

    public function test_returns_error_envelope_on_exception(): void
    {
        $error = new class('Boom') extends \RuntimeException {};

        FakeBackendClient::setProxyResponse(user: 'main', backend: 'test_plex', response: $error);

        $handler = new Proxy($this->createStub(iImport::class), new Logger('test'));

        $response = $handler(
            $this->getRequest(
                method: Method::POST,
                uri: '/v1/api/backend/test_plex/proxy',
                post: ['method' => 'GET', 'path' => '/'],
            ),
            'test_plex',
        );

        self::assertSame(Status::OK->value, $response->getStatusCode());

        $payload = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(500, ag($payload, 'response.status'));
        self::assertSame('Boom', ag($payload, 'response.body'));
        self::assertSame('Boom', ag($payload, 'response.headers.WS-Error'));
    }

    public function test_returns_bad_request_on_unsuccessful_response(): void
    {
        $error = new Error(
            message: 'backend down',
            context: [],
            level: Levels::WARNING,
        );

        FakeBackendClient::setProxyResponse(
            user: 'main',
            backend: 'test_plex',
            response: new Response(status: false, error: $error),
        );

        $handler = new Proxy($this->createStub(iImport::class), new Logger('test'));

        $response = $handler(
            $this->getRequest(
                method: Method::POST,
                uri: '/v1/api/backend/test_plex/proxy',
                post: ['method' => 'GET', 'path' => '/'],
            ),
            'test_plex',
        );

        self::assertSame(Status::BAD_REQUEST->value, $response->getStatusCode());
    }

    public function test_uri_preserves_backend_host(): void
    {
        FakeBackendClient::setProxyResponse('main', 'test_plex', new Response(status: true));

        $handler = new Proxy($this->createStub(iImport::class), new Logger('test'));

        $handler(
            $this->getRequest(
                method: Method::POST,
                uri: '/v1/api/backend/test_plex/proxy',
                post: [
                    'method' => 'GET',
                    'path' => '/system/Info',
                ],
            ),
            'test_plex',
        );

        $calls = FakeBackendClient::getCalls('proxy');
        self::assertSame(
            'https://plex.example.invalid/system/Info',
            $calls[0]['url'],
            'Path must be resolved against the configured backend URL.',
        );
    }

    public function test_string_body_wrapped_as_stream(): void
    {
        FakeBackendClient::setProxyResponse(
            user: 'main',
            backend: 'test_plex',
            response: new Response(
                status: true,
                response: new APIResponse(status: Status::OK, stream: Stream::create('{}')),
            ),
        );

        $handler = new Proxy($this->createStub(iImport::class), new Logger('test'));

        $response = $handler(
            $this->getRequest(
                method: Method::POST,
                uri: '/v1/api/backend/test_plex/proxy',
                post: [
                    'method' => 'POST',
                    'path' => '/xml',
                    'body' => '<item/>',
                    'headers' => ['Content-Type' => 'application/xml'],
                ],
            ),
            'test_plex',
        );

        self::assertSame(Status::OK->value, $response->getStatusCode());

        $calls = FakeBackendClient::getCalls('proxy');
        self::assertCount(1, $calls, 'String body should not prevent the proxy call from being recorded.');
        self::assertNull(
            $calls[0]['body'],
            'Stream-wrapped bodies should not appear in the recorded body field (which only captures arrays).',
        );
    }
}
