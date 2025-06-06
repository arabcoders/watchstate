<?php

declare(strict_types=1);

namespace Tests\Backends\Plex;

use App\Backends\Common\Cache;
use App\Backends\Common\Context;
use App\Backends\Plex\Action\GetLibrariesList;
use App\Backends\Plex\PlexClient;
use App\Libs\ConfigFile;
use App\Libs\Database\DBLayer;
use App\Libs\Database\PDO\PDOAdapter;
use App\Libs\Extends\LogMessageProcessor;
use App\Libs\Extends\MockHttpClient;
use App\Libs\Mappers\Import\DirectMapper;
use App\Libs\Options;
use App\Libs\Stream;
use App\Libs\TestCase;
use App\Libs\Uri;
use App\Libs\UserContext;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PDO;
use Psr\Log\LoggerInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Psr16Cache;
use Symfony\Component\HttpClient\Response\MockResponse;

class GetLibrariesListTest extends TestCase
{
    protected TestHandler|null $handler = null;
    protected LoggerInterface|null $logger = null;
    protected Context|null $context = null;
    protected array $data = [];

    public function setUp(): void
    {
        $this->handler = new TestHandler();
        $this->logger = new Logger('test', [$this->handler], [new LogMessageProcessor()]);
        $this->data = json_decode(
            json: (string)Stream::make(__DIR__ . '/../../Fixtures/plex_data.json', 'r'),
            associative: true
        );

        $cache = new Cache($this->logger, new Psr16Cache(new ArrayAdapter()));
        $db = new PDOAdapter($this->logger, new DBLayer(new PDO('sqlite::memory:')));
        $db->migrations('up');

        $this->context = new Context(
            clientName: PlexClient::CLIENT_NAME,
            backendName: PlexClient::CLIENT_NAME,
            backendUrl: new Uri('http://plex-test.example.com'),
            cache: $cache,
            userContext: new UserContext(
                name: PlexClient::CLIENT_NAME,
                config: new ConfigFile(
                    file: __DIR__ . '/../../Fixtures/test_servers.yaml',
                    autoSave: false,
                    autoCreate: false,
                    autoBackup: false
                ),
                mapper: new DirectMapper(
                    logger: $this->logger,
                    db: $db,
                    cache: $cache->getInterface()
                ),
                cache: $cache->getInterface(),
                db: $db
            ),
            backendId: 'za3g3543d0d637b4f9144099965f8f785cdf9f26',
            backendToken: 'fake-plex-token',
            backendUser: 1,
        );
    }

    public function test_correct_response(): void
    {
        $json = ag($this->data, 'sections_get_200');

        $resp = new MockResponse(json_encode(ag($json, 'response.body')), [
            'http_code' => (int)ag($json, 'response.http_code'),
            'response_headers' => ag($json, 'response.headers', [])
        ]);

        $client = new MockHttpClient($resp);
        $response = new GetLibrariesList($client, $this->logger)($this->context);

        $this->assertTrue($response->status);
        $this->assertNull($response->error);
        $this->assertIsArray($response->response);
        $this->assertCount(3, $response->response);

        $expected = [];

        foreach (ag($json, 'response.body.MediaContainer.Directory', []) as $item) {
            $key = (int)ag($item, 'key');
            $type = ag($item, 'type', 'unknown');
            $agent = ag($item, 'agent', 'unknown');
            $supportedType = PlexClient::TYPE_MOVIE === $type || PlexClient::TYPE_SHOW === $type;
            $webUrl = $this->context->backendUrl->withPath('/web/index.html')->withFragment(
                r('!/media/{backend_id}/com.plexapp.plugins.library?source={key}', [
                    'key' => $key,
                    'backend_id' => $this->context->backendId,
                ])
            );

            $expected[$key] = [
                'id' => $key,
                'title' => ag($item, 'title'),
                'type' => ucfirst($type),
                'ignored' => false,
                'supported' => $supportedType && true === in_array($agent, PlexClient::SUPPORTED_AGENTS),
                'agent' => $agent,
                'scanner' => ag($item, 'scanner'),
                'contentType' => strtolower($type),
                'webUrl' => (string)$webUrl,
            ];
        }

        $x = 0;
        foreach ($response->response as $item) {
            $this->assertSame(
                ag($expected, $item['id']),
                $item,
                r('Assert response item payload:{number} is formatted as expected', ['number' => $x])
            );
            $x++;
        }
    }

    public function test_401_response_with_invalid_token(): void
    {
        $json = ag($this->data, 'sections_get_401');

        $resp = new MockResponse(json_encode(ag($json, 'response.body')), [
            'http_code' => (int)ag($json, 'response.http_code'),
            'response_headers' => ag($json, 'response.headers', [])
        ]);

        $client = new MockHttpClient($resp);
        $list = new GetLibrariesList($client, $this->logger);
        $response = $list($this->context, [Options::NO_CACHE => true]);

        $this->assertFalse($response->status);
        $this->assertNotNull($response->error);
        $this->assertStringContainsString(
            "libraries returned with unexpected '401' status code",
            (string)$response->error
        );

        $this->assertNull($response->response);
        $this->assertTrue($response->error->hasException());
    }
}
