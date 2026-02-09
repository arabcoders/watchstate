<?php

declare(strict_types=1);

namespace Tests\Backends\Plex;

use App\Backends\Common\Cache;
use App\Backends\Common\Context;
use App\Libs\ConfigFile;
use App\Libs\Container;
use App\Libs\Database\DBLayer;
use App\Libs\Database\PDO\PDOAdapter;
use App\Libs\Entity\StateEntity;
use App\Libs\Entity\StateInterface;
use App\Libs\Extends\HttpClient;
use App\Libs\Extends\MockHttpClient;
use App\Libs\Uri;
use App\Libs\UserContext;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PDO;
use Psr\Log\LoggerInterface;
use Psr\Http\Message\UriInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Psr16Cache;
use Symfony\Component\HttpClient\Response\MockResponse;

abstract class PlexTestCase extends \PHPUnit\Framework\TestCase
{
    protected TestHandler|null $handler = null;
    protected Logger|null $logger = null;

    private array $fixtures = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->handler = new TestHandler();
        $this->logger = new Logger('test', [$this->handler]);

        Container::reinitialize();
        Container::add(StateInterface::class, fn() => new StateEntity([]));
        Container::add(LoggerInterface::class, fn() => $this->logger);
        Container::add(UriInterface::class, fn() => new Uri(''));
    }

    protected function makeContext(array $options = []): Context
    {
        $cache = new Cache($this->logger, new Psr16Cache(new ArrayAdapter()));
        $db = new PDOAdapter($this->logger, new DBLayer(new PDO('sqlite::memory:')));
        $db->migrations('up');

        $userContext = new UserContext(
            name: 'Plex',
            config: new ConfigFile(
                file: __DIR__ . '/../../Fixtures/test_servers.yaml',
                autoSave: false,
                autoCreate: false,
                autoBackup: false,
            ),
            mapper: new \App\Libs\Mappers\Import\DirectMapper(
                logger: $this->logger,
                db: $db,
                cache: $cache->getInterface(),
            ),
            cache: $cache->getInterface(),
            db: $db,
        );

        return new Context(
            clientName: 'Plex',
            backendName: 'Plex',
            backendUrl: new Uri('http://plex.test'),
            cache: $cache,
            userContext: $userContext,
            logger: $this->logger,
            backendId: 'plex-server-1',
            backendToken: 'token-1',
            options: $options,
        );
    }

    protected function fixture(string $key): array
    {
        if (empty($this->fixtures)) {
            $data = json_decode(
                json: (string) file_get_contents(__DIR__ . '/../../Fixtures/plex_data.json'),
                associative: true,
            );
            $this->fixtures = is_array($data) ? $data : [];
        }

        return $this->fixtures[$key] ?? [];
    }

    protected function makeHttpClient(MockResponse ...$responses): HttpClient
    {
        return new HttpClient(new MockHttpClient($responses));
    }

    protected function makeResponse(array|string $body, int $status = 200): MockResponse
    {
        $payload = is_array($body) ? json_encode($body) : $body;

        return new MockResponse($payload, ['http_code' => $status]);
    }
}
