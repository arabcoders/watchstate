<?php

declare(strict_types=1);

namespace Tests\API\Backend;

use App\API\Backend\Proxy;
use App\Backends\Common\Error;
use App\Backends\Common\Levels;
use App\Backends\Common\Response;
use App\Libs\APIResponse;
use App\Libs\Attributes\Route\Get;
use App\Libs\Attributes\Route\Post;
use App\Libs\Config;
use App\Libs\ConfigFile;
use App\Libs\Container;
use App\Libs\Enums\Http\Method;
use App\Libs\Enums\Http\Status;
use App\Libs\Extends\RouterStrategy;
use App\Libs\Mappers\ImportInterface as iImport;
use App\Libs\Stream;
use App\Libs\TestCase;
use League\Route\Router;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Psr\Http\Message\StreamInterface as iStream;
use Psr\Http\Message\UriInterface as iUri;
use ReflectionMethod;
use RuntimeException;
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
        $error = new class('Boom') extends RuntimeException {};

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

    /**
     * Preserve backend bytes, status and content type while isolating active content.
     */
    public function test_stream_response(): void
    {
        $handler = new Proxy($this->createStub(iImport::class), new Logger('test'));

        foreach ([
            [Status::OK,        'image/jpeg',               "\xff\xd8\x00\xfe\xff\xd9"],
            [Status::OK,        'image/png',                "\x89PNG\r\n\x1a\n\x00\xff"],
            [Status::NOT_FOUND, 'application/json',         '{"error":"missing"}'],
            [Status::FOUND,     'text/html; charset=utf-8', '<script>alert(document.cookie)</script>'],
            [Status::OK,        'image/svg+xml',            '<svg onload="alert(document.cookie)"/>'],
            [Status::OK,        'application/xml',          '<?xml version="1.0"?><item/>'],
        ] as [$status, $contentType, $body]) {
            $stream = Stream::create($body);
            FakeBackendClient::setProxyResponse('main', 'test_plex', new Response(
                status: true,
                response: new APIResponse(
                    status: $status,
                    headers: [
                        'Content-Type' => [$contentType],
                        'Set-Cookie' => ['session=backend'],
                        'Location' => ['https://evil.example.invalid/'],
                        'Refresh' => ['0;url=https://evil.example.invalid/'],
                        'Connection' => ['keep-alive'],
                        'Transfer-Encoding' => ['chunked'],
                        'Content-Encoding' => ['gzip'],
                        'Cache-Control' => ['public, max-age=86400'],
                    ],
                    stream: $stream,
                ),
            ));

            $response = $handler->get(
                $this->getRequest(uri: '/v1/api/backend/test_plex/proxy/library/metadata/42/thumb'),
                'test_plex',
                'library/metadata/42/thumb',
            );

            self::assertSame($status->value, $response->getStatusCode());
            self::assertSame($stream, $response->getBody());
            self::assertSame($body, (string) $response->getBody());
            self::assertSame($contentType, $response->getHeaderLine('Content-Type'));
            self::assertSame('private, no-store', $response->getHeaderLine('Cache-Control'));
            self::assertSame("sandbox; default-src 'none'", $response->getHeaderLine('Content-Security-Policy'));
            self::assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));

            foreach (['Set-Cookie', 'Location', 'Refresh', 'Connection', 'Transfer-Encoding', 'Content-Encoding'] as $header) {
                self::assertFalse($response->hasHeader($header), $header);
            }
        }
    }

    /**
     * Dispatch nested encoded paths and the existing POST endpoint through the configured router.
     */
    public function test_proxy_routes(): void
    {
        Config::save('api.prefix', '/test/api');
        FakeBackendClient::setProxyResponse('main', 'test_plex', new Response(
            status: true,
            response: new APIResponse(
                status: Status::OK,
                headers: ['content-type' => ['image/jpeg']],
                stream: Stream::create('poster'),
            ),
        ));
        $handler = new Proxy($this->createStub(iImport::class), new Logger('test'));
        $router = new Router();
        $strategy = new RouterStrategy();
        $strategy->setContainer(Container::getContainer());
        $router->setStrategy($strategy);

        foreach (Config::get('api.pattern_match', []) as $alias => $pattern) {
            $router->addPatternMatcher($alias, $pattern);
        }

        foreach (['get' => Get::class, '__invoke' => Post::class] as $method => $attributeClass) {
            $attribute = new ReflectionMethod($handler, $method)->getAttributes($attributeClass)[0]->newInstance();

            foreach ($attribute->methods as $httpMethod) {
                $router->map($httpMethod, $attribute->pattern, [$handler, $method]);
            }
        }

        $response = $router->dispatch($this->getRequest(
            uri: '/test/api/backend/test_plex/proxy/library/metadata/42/thumb/poster%20one.jpg',
        ));

        self::assertSame(Status::OK->value, $response->getStatusCode());
        self::assertSame('poster', (string) $response->getBody());
        self::assertSame('image/jpeg', $response->getHeaderLine('Content-Type'));

        $response = $router->dispatch($this->getRequest(
            method: Method::POST,
            uri: '/test/api/backend/test_plex/proxy',
            post: ['path' => '/library/sections'],
        ));
        $payload = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(Status::OK->value, $response->getStatusCode());
        self::assertSame('poster', ag($payload, 'response.body'));
        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));
        $calls = FakeBackendClient::getCalls('proxy');
        self::assertCount(2, $calls);
        self::assertSame(
            'https://plex.example.invalid/library/metadata/42/thumb/poster%20one.jpg',
            $calls[0]['url'],
        );
        self::assertSame('https://plex.example.invalid/library/sections', $calls[1]['url']);
    }

    /**
     * Keep the raw backend query and configured base path without forwarding WatchState credentials.
     */
    public function test_stream_query(): void
    {
        ConfigFile::open(Config::get('backends_file'), 'yaml')
            ->set('test_plex.url', 'https://plex.example.invalid:32400/plex')
            ->persist();
        FakeBackendClient::setProxyResponse('main', 'test_plex', new Response(
            status: true,
            response: new APIResponse(status: Status::OK, stream: Stream::create('poster')),
        ));
        $handler = new Proxy($this->createStub(iImport::class), new Logger('test'));
        $query = 'width=300&tag=a%2Fb&tag=c+d&empty=&apikey=secret&ws_token=token&user=main&ws%5Ftoken=encoded';

        $response = $handler->get(
            $this->getRequest(
                uri: '/v1/api/backend/test_plex/proxy/library/metadata/42/thumb?' . $query,
                headers: ['Authorization' => 'Bearer browser', 'Cookie' => 'session=browser', 'Connection' => 'close'],
                server: ['QUERY_STRING' => $query],
            ),
            'test_plex',
            'library/metadata/42/thumb',
        );

        self::assertSame(Status::OK->value, $response->getStatusCode());
        self::assertSame('application/octet-stream', $response->getHeaderLine('Content-Type'));
        $calls = FakeBackendClient::getCalls('proxy');
        self::assertCount(1, $calls);
        self::assertSame('GET', $calls[0]['method']);
        self::assertSame(
            'https://plex.example.invalid:32400/plex/library/metadata/42/thumb?width=300&tag=a%2Fb&tag=c+d&empty=',
            $calls[0]['url'],
        );
        self::assertSame([], $calls[0]['body']);
        self::assertSame([], $calls[0]['headers']);
    }

    /**
     * Reject alternate authorities and encoded path characters before calling the backend.
     */
    public function test_stream_paths(): void
    {
        $handler = new Proxy($this->createStub(iImport::class), new Logger('test'));

        foreach ([
            '',
            ' ',
            'https://evil.example.invalid/image',
            '/https://evil.example.invalid/image',
            'https:evil.example.invalid/image',
            '//evil.example.invalid/image',
            '\\\\evil.example.invalid\\image',
            '/\\evil.example.invalid/image',
            "image\0.jpg",
            "image\r\nInjected: yes",
            "image\x7f.jpg",
            '%2F%2Fevil.example.invalid/image',
            '/%2Fevil.example.invalid/image',
            '%252f%252fevil.example.invalid/image',
            'https%3A%2F%2Fevil.example.invalid/image',
            '%5C%5Cevil.example.invalid/image',
            '/image%0d%0aInjected.jpg',
            '/image%2500.jpg',
            '../image',
            './image',
            'library/../image',
            'library/./image',
            '%2e%2e/image',
            'library/%252e%252e/image',
            'library%2f..%2fimage',
        ] as $path) {
            $response = $handler->get(
                $this->getRequest(uri: '/v1/api/backend/test_plex/proxy/image'),
                'test_plex',
                $path,
            );

            self::assertSame(Status::BAD_REQUEST->value, $response->getStatusCode(), $path);
            self::assertSame('private, no-store', $response->getHeaderLine('Cache-Control'));
        }

        self::assertSame([], FakeBackendClient::getCalls('proxy'));
    }

    /**
     * Bound request duration and prevent redirects from carrying backend credentials to another host.
     */
    public function test_stream_options(): void
    {
        $client = new class extends FakeBackendClient {
            public static array $options = [];

            public static string|int|null $token = null;

            /**
             * Capture the HTTP options that the backend proxy action receives.
             */
            public function proxy(Method $method, iUri $uri, array|iStream $body = [], array $opts = []): Response
            {
                self::$options = $this->getContext()->getHttpOptions();
                self::$token = $this->getContext()->backendToken;

                return parent::proxy($method, $uri, $body, $opts);
            }
        };
        Config::save('supported.plex', $client::class);
        ConfigFile::open(Config::get('backends_file'), 'yaml')
            ->set('test_plex.options.client', ['timeout' => 300.0, 'max_duration' => 300.0, 'max_redirects' => 20])
            ->persist();
        FakeBackendClient::setProxyResponse('main', 'test_plex', new Response(
            status: true,
            response: new APIResponse(
                status: Status::TEMPORARY_REDIRECT,
                headers: ['location' => ['https://evil.example.invalid/']],
                stream: Stream::create('redirect'),
            ),
        ));
        $handler = new Proxy($this->createStub(iImport::class), new Logger('test'));

        $response = $handler->get($this->getRequest(), 'test_plex', 'image');

        self::assertSame(10.0, $client::$options['timeout']);
        self::assertSame(10.0, $client::$options['max_duration']);
        self::assertSame(0, $client::$options['max_redirects']);
        self::assertSame('t000000000000000000p', $client::$token);
        self::assertSame(Status::TEMPORARY_REDIRECT->value, $response->getStatusCode());
        self::assertSame('redirect', (string) $response->getBody());
        self::assertFalse($response->hasHeader('Location'));
        self::assertCount(1, FakeBackendClient::getCalls('proxy'));
    }

    /**
     * Resolve the selected identity for both header and image URL query requests.
     */
    public function test_stream_identity(): void
    {
        $this->seedTestServersConfig('alice');
        ConfigFile::open(self::$tmpPath . '/users/alice/servers.yaml', 'yaml')
            ->set('test_plex.url', 'https://alice-plex.example.invalid')
            ->persist();
        FakeBackendClient::setProxyResponse('main', 'test_plex', new Response(
            status: true,
            response: new APIResponse(status: Status::OK, stream: Stream::create('main poster')),
        ));
        FakeBackendClient::setProxyResponse('alice', 'test_plex', new Response(
            status: true,
            response: new APIResponse(status: Status::OK, stream: Stream::create('alice poster')),
        ));
        $handler = new Proxy($this->createStub(iImport::class), new Logger('test'));

        foreach ([
            $this->getRequest(headers: ['X-User' => 'alice']),
            $this->getRequest(
                uri: '/?user=alice&width=300',
                query: ['user' => 'alice', 'width' => '300'],
                server: ['QUERY_STRING' => 'user=alice&width=300'],
            ),
        ] as $request) {
            $response = $handler->get($request, 'test_plex', 'image');

            self::assertSame('alice poster', (string) $response->getBody());
            self::assertSame('private, no-store', $response->getHeaderLine('Cache-Control'));
        }

        $calls = FakeBackendClient::getCalls('proxy');
        self::assertSame(['alice', 'alice'], array_column($calls, 'user'));
        self::assertSame('https://alice-plex.example.invalid/image?width=300', $calls[1]['url']);
    }

    /**
     * Reject missing identities and backends without issuing a backend request.
     */
    public function test_stream_missing(): void
    {
        $handler = new Proxy($this->createStub(iImport::class), new Logger('test'));

        foreach ([
            [$this->getRequest(), 'missing'],
            [$this->getRequest(headers: ['X-User' => 'missing']), 'test_plex'],
        ] as [$request, $backend]) {
            $response = $handler->get($request, $backend, 'image');

            self::assertSame(Status::NOT_FOUND->value, $response->getStatusCode());
            self::assertSame('private, no-store', $response->getHeaderLine('Cache-Control'));
        }

        self::assertSame([], FakeBackendClient::getCalls('proxy'));
    }

    /**
     * Distinguish transport failures and malformed responses from backend HTTP error responses.
     */
    public function test_stream_failure(): void
    {
        ConfigFile::open(Config::get('backends_file'), 'yaml')
            ->set('test_plex.url', 'https://username:password@plex.example.invalid')
            ->persist();
        $logs = new TestHandler();
        $handler = new Proxy($this->createStub(iImport::class), new Logger('test', [$logs]));

        foreach ([
            new RuntimeException('Backend unavailable.'),
            new Response(status: false, error: new Error(message: 'backend down', context: [], level: Levels::WARNING)),
            new Response(status: true),
        ] as $fixture) {
            FakeBackendClient::setProxyResponse('main', 'test_plex', $fixture);

            $response = $handler->get($this->getRequest(), 'test_plex', 'image');

            self::assertSame(Status::BAD_GATEWAY->value, $response->getStatusCode());
            self::assertSame('private, no-store', $response->getHeaderLine('Cache-Control'));
            self::assertStringNotContainsString('Backend unavailable.', (string) $response->getBody());
        }

        foreach ($logs->getRecords() as $record) {
            self::assertSame('https://plex.example.invalid/image', $record->context['request']['url']);
        }

        self::assertSame(
            'https://username:password@plex.example.invalid/image',
            FakeBackendClient::getCalls('proxy')[0]['url'],
        );
    }
}
