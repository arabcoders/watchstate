<?php

declare(strict_types=1);

namespace Tests\Backends\Jellyfin;

use App\Backends\Common\Cache;
use App\Backends\Common\Context;
use App\Backends\Jellyfin\Action\Backup;
use App\Backends\Jellyfin\Action\Export;
use App\Backends\Jellyfin\Action\Import;
use App\Libs\Extends\HttpClient;
use App\Libs\Extends\MockHttpClient;
use App\Libs\Extends\RetryableHttpClient;
use App\Libs\TestCase;
use App\Libs\Uri;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use ReflectionProperty;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Psr16Cache;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface as iResponse;
use Symfony\Contracts\HttpClient\ResponseStreamInterface as iResponseStream;

class ImportFlowTest extends TestCase
{
    private Logger $logger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->handler = new TestHandler();
        $this->logger = new Logger('test', [$this->handler]);
    }

    #[DataProvider('inheritedActions')]
    public function test_inherited_handle_streams(string $actionClass): void
    {
        $source = new MockHttpClient(new MockResponse('{"Items":[{"Id":"1","Type":"Movie"}]}'));
        $http = new HttpClient($source);
        $action = new $actionClass($http, $this->logger);
        $items = [];
        $response = $source->request('GET', 'http://jellyfin.test');
        $stream = $source->stream($response);
        $retryable = $this->createMock(RetryableHttpClient::class);
        $retryable->expects($this->once())->method('stream')->willReturn($stream);
        new ReflectionProperty($action, 'http')->setValue($action, $retryable);

        $this->invokeHandle(
            $action,
            $this->makeContext(),
            $response,
            static function (array $item, array $logContext = []) use (&$items): void {
                $items[] = $item;
            },
        );

        $this->assertCount(1, $items);
        $this->assertSame('1', $items[0]['Id']);
    }

    public function test_import_handle_replays_buffered_response(): void
    {
        $http = new class(new MockHttpClient(new MockResponse('{"Items":[{"Id":"1","Type":"Movie"}]}'))) extends HttpClient {
            public int $streamCalls = 0;

            public function stream(iterable|iResponse $responses, ?float $timeout = null): iResponseStream
            {
                $this->streamCalls++;
                return parent::stream($responses, $timeout);
            }
        };
        $action = new Import($http, $this->logger);
        $items = [];
        $response = $http->request('GET', 'http://jellyfin.test', [
            'body' => json_encode(['Items' => [['Id' => '1', 'Type' => 'Movie']]], JSON_THROW_ON_ERROR),
        ]);

        $this->invokeHandle(
            $action,
            $this->makeContext(),
            $response,
            static function (array $item, array $logContext = []) use (&$items): void {
                $items[] = $item;
            },
            [],
            http_client_chunks($response),
        );

        $this->assertSame(0, $http->streamCalls);
        $this->assertCount(1, $items);
        $this->assertSame('1', $items[0]['Id']);
    }

    public static function inheritedActions(): array
    {
        return [[Export::class], [Backup::class]];
    }

    private function makeContext(): Context
    {
        $cache = new Cache($this->logger, new Psr16Cache(new ArrayAdapter()));

        return new Context(
            clientName: 'Jellyfin',
            backendName: 'Jellyfin',
            backendUrl: new Uri('http://jellyfin.test'),
            cache: $cache,
            userContext: $this->createUserContext(name: 'Jellyfin', logger: $this->logger, cache: $cache->getInterface()),
            logger: $this->logger,
            backendId: 'jellyfin-server-1',
            backendToken: 'token-1',
            backendUser: 'user-1',
        );
    }

    private function invokeHandle(
        object $action,
        Context $context,
        iResponse $response,
        callable $callback,
        array $logContext = [],
        ?iterable $chunks = null,
    ): void {
        $method = new ReflectionMethod($action, 'handle');
        $method->invoke(
            $action,
            $context,
            $response,
            $callback,
            $logContext
            + [
                'library' => ['title' => 'Movies'],
                'segment' => ['number' => 1, 'of' => 1],
                'identity' => ['user' => 'Jellyfin', 'backend' => 'Jellyfin'],
            ],
            $chunks,
        );
    }
}
