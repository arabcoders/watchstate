<?php

declare(strict_types=1);

namespace Tests\Backends\Jellyfin;

use App\Backends\Common\Response;
use App\Backends\Jellyfin\Action\Backup;
use App\Backends\Jellyfin\Action\Export;
use App\Backends\Jellyfin\Action\GenerateAccessToken;
use App\Backends\Jellyfin\Action\GetIdentifier;
use App\Backends\Jellyfin\Action\GetImagesUrl;
use App\Backends\Jellyfin\Action\GetInfo;
use App\Backends\Jellyfin\Action\GetLibrariesList;
use App\Backends\Jellyfin\Action\GetLibrary;
use App\Backends\Jellyfin\Action\GetMetaData;
use App\Backends\Jellyfin\Action\GetSessions;
use App\Backends\Jellyfin\Action\GetUsersList;
use App\Backends\Jellyfin\Action\GetVersion;
use App\Backends\Jellyfin\Action\GetWebUrl;
use App\Backends\Jellyfin\Action\Import;
use App\Backends\Jellyfin\Action\InspectRequest;
use App\Backends\Jellyfin\Action\ParseWebhook;
use App\Backends\Jellyfin\Action\Progress;
use App\Backends\Jellyfin\Action\Proxy;
use App\Backends\Jellyfin\Action\Push;
use App\Backends\Jellyfin\Action\SearchId;
use App\Backends\Jellyfin\Action\SearchQuery;
use App\Backends\Jellyfin\Action\ToEntity;
use App\Backends\Jellyfin\Action\UpdateState;
use App\Backends\Jellyfin\JellyfinClient;
use App\Backends\Jellyfin\JellyfinGuid;
use App\Libs\Container;
use App\Libs\Entity\StateEntity;
use App\Libs\Entity\StateInterface as iState;
use App\Libs\Enums\Http\Method;
use App\Libs\QueueRequests;
use App\Libs\Uri;
use Nyholm\Psr7\ServerRequest;
use Tests\Backends\MediaBrowser\MediaBrowserTestCase;

class JellyfinClientTest extends MediaBrowserTestCase
{
    public function test_basic_getters_and_identifier(): void
    {
        $context = $this->makeContext('Jellyfin');
        $client = $this->makeClient($context);

        $this->assertSame('Jellyfin', $client->getType());
        $this->assertSame($context->backendName, $client->getContext()->backendName);
        $this->assertSame('backend-1', $client->getIdentifier());

        $this->stubContextOpts(GetIdentifier::class, new Response(status: true, response: 'fresh-id'));
        $this->assertSame('fresh-id', $client->getIdentifier(true));
    }

    public function test_process_request_and_parse_webhook(): void
    {
        $context = $this->makeContext('Jellyfin');
        $client = $this->makeClient($context);

        $request = new ServerRequest('POST', new Uri('http://mediabrowser.test'));
        $this->stubContextRequest(InspectRequest::class, new Response(status: true, response: $request));
        $this->assertSame($request, $client->processRequest($request));

        $entity = StateEntity::fromArray([
            iState::COLUMN_ID => 1,
            iState::COLUMN_TYPE => iState::TYPE_MOVIE,
            iState::COLUMN_UPDATED => 1,
            iState::COLUMN_WATCHED => 0,
            iState::COLUMN_VIA => 'Jellyfin',
            iState::COLUMN_TITLE => 'Test Movie',
        ]);
        $this->stubContextGuidRequest(ParseWebhook::class, new Response(status: true, response: $entity));

        $this->assertSame($entity, $client->parseWebhook($request));
    }

    public function test_action_wrappers_return_data(): void
    {
        $context = $this->makeContext('Jellyfin');
        $client = $this->makeClient($context);

        $this->stubContextOpts(GetInfo::class, new Response(status: true, response: ['name' => 'Jellyfin']));
        $this->stubContextOpts(GetVersion::class, new Response(status: true, response: '10.9.0'));
        $this->stubContextOpts(GetUsersList::class, new Response(status: true, response: [['id' => 'u1']]));
        $this->stubContextOpts(GetSessions::class, new Response(status: true, response: ['sessions' => []]));
        $this->stubContextQuery(SearchQuery::class, new Response(status: true, response: [['title' => 'Test']]));
        $this->stubContextId(SearchId::class, new Response(status: true, response: ['title' => 'Test']));
        $this->stubContextId(GetMetaData::class, new Response(status: true, response: ['Id' => 'item-1']));
        $this->stubContextId(GetImagesUrl::class, new Response(status: true, response: ['poster' => 'p']));
        $this->stubContextOpts(GetLibrariesList::class, new Response(status: true, response: [['id' => 'lib-1']]));
        $this->stubContextGuidId(GetLibrary::class, new Response(status: true, response: [['id' => 'item-1']]));
        $this->stubContextMethodUri(Proxy::class, new Response(status: true, response: new \App\Libs\APIResponse(status: \App\Libs\Enums\Http\Status::OK)));
        $this->stubGenerateAccessToken(GenerateAccessToken::class, new Response(status: true, response: ['accesstoken' => 't']));
        $this->stubContextItem(ToEntity::class, new Response(status: true, response: StateEntity::fromArray([
            iState::COLUMN_ID => 1,
            iState::COLUMN_TYPE => iState::TYPE_MOVIE,
            iState::COLUMN_UPDATED => 1,
            iState::COLUMN_WATCHED => 0,
            iState::COLUMN_VIA => 'Jellyfin',
            iState::COLUMN_TITLE => 'Test Movie',
        ])));
        $this->stubContextWebUrl(GetWebUrl::class, new Response(status: true, response: new Uri('http://example.test')));

        $this->assertSame('Jellyfin', $client->getInfo()['name']);
        $this->assertSame('10.9.0', $client->getVersion());
        $this->assertSame('u1', $client->getUsersList()[0]['id']);
        $this->assertSame([], $client->getSessions()['sessions']);
        $this->assertSame('Test', $client->search('q')[0]['title']);
        $this->assertSame('Test', $client->searchId('id')['title']);
        $this->assertSame('item-1', $client->getMetadata('item-1')['Id']);
        $this->assertSame('p', $client->getImagesUrl('item-1')['poster']);
        $this->assertSame('lib-1', $client->listLibraries()[0]['id']);
        $this->assertSame('item-1', $client->getLibrary('lib-1')[0]['id']);
        $this->assertSame('token-1', $client->getUserToken('1', 'user'));
        $this->assertSame('t', $client->generateAccessToken('user', 'pass')['accesstoken']);
        $this->assertSame('http://example.test', (string) $client->getWebUrl('movie', '1'));
        $this->assertInstanceOf(Response::class, $client->proxy(Method::GET, new Uri('http://example.test')));
        $this->assertSame('Test Movie', $client->toEntity(['id' => 1])->title);
    }

    public function test_import_export_push_progress_update_state(): void
    {
        $context = $this->makeContext('Jellyfin');
        $client = $this->makeClient($context);

        $this->stubContextGuidMapper(Import::class, new Response(status: true, response: []));
        $this->stubContextGuidMapper(Backup::class, new Response(status: true, response: []));
        $this->stubContextGuidMapper(Export::class, new Response(status: true, response: []));
        $this->stubContextOpts(GetVersion::class, new Response(status: true, response: '10.9.0'));
        $this->stubContextEntitiesQueue(Push::class, new Response(status: true, response: []));
        $this->stubContextGuidEntitiesQueue(Progress::class, new Response(status: true, response: []));
        $this->stubContextEntitiesQueueOpts(UpdateState::class, new Response(status: true, response: []));

        $queue = new QueueRequests();
        $mapper = $context->userContext->mapper;

        $this->assertSame([], $client->pull($mapper));
        $this->assertSame([], $client->backup($mapper));
        $this->assertSame([], $client->export($mapper, $queue));
        $this->assertSame([], $client->push([], $queue));
        $this->assertSame([], $client->progress([], $queue));
        $client->updateState([], $queue);
    }

    private function makeClient(\App\Backends\Common\Context $context): JellyfinClient
    {
        $guid = new JellyfinGuid($this->logger);
        return (new JellyfinClient($context->cache, $this->logger, $guid, $context->userContext))
            ->withContext($context);
    }

    private function stubContextOpts(string $class, Response $response): void
    {
        Container::add($class, fn() => new class($response) {
            public function __construct(private Response $response)
            {
            }

            public function __invoke(\App\Backends\Common\Context $context, array $opts = []): Response
            {
                return $this->response;
            }
        });
    }

    private function stubContextId(string $class, Response $response): void
    {
        Container::add($class, fn() => new class($response) {
            public function __construct(private Response $response)
            {
            }

            public function __invoke(\App\Backends\Common\Context $context, string|int $id, array $opts = []): Response
            {
                return $this->response;
            }
        });
    }

    private function stubContextGuidId(string $class, Response $response): void
    {
        Container::add($class, fn() => new class($response) {
            public function __construct(private Response $response)
            {
            }

            public function __invoke(\App\Backends\Common\Context $context, \App\Backends\Common\GuidInterface $guid, string|int $id, array $opts = []): Response
            {
                return $this->response;
            }
        });
    }

    private function stubContextQuery(string $class, Response $response): void
    {
        Container::add($class, fn() => new class($response) {
            public function __construct(private Response $response)
            {
            }

            public function __invoke(\App\Backends\Common\Context $context, string $query, int $limit = 25, array $opts = []): Response
            {
                return $this->response;
            }
        });
    }

    private function stubContextItem(string $class, Response $response): void
    {
        Container::add($class, fn() => new class($response) {
            public function __construct(private Response $response)
            {
            }

            public function __invoke(\App\Backends\Common\Context $context, array $item, array $opts = []): Response
            {
                return $this->response;
            }
        });
    }

    private function stubContextMethodUri(string $class, Response $response): void
    {
        Container::add($class, fn() => new class($response) {
            public function __construct(private Response $response)
            {
            }

            public function __invoke(
                \App\Backends\Common\Context $context,
                Method $method,
                \Psr\Http\Message\UriInterface $uri,
                array|\Psr\Http\Message\StreamInterface $body = [],
                array $opts = [],
            ): Response {
                return $this->response;
            }
        });
    }

    private function stubContextRequest(string $class, Response $response): void
    {
        Container::add($class, fn() => new class($response) {
            public function __construct(private Response $response)
            {
            }

            public function __invoke(\App\Backends\Common\Context $context, \Psr\Http\Message\ServerRequestInterface $request): Response
            {
                return $this->response;
            }
        });
    }

    private function stubContextGuidRequest(string $class, Response $response): void
    {
        Container::add($class, fn() => new class($response) {
            public function __construct(private Response $response)
            {
            }

            public function __invoke(
                \App\Backends\Common\Context $context,
                \App\Backends\Common\GuidInterface $guid,
                \Psr\Http\Message\ServerRequestInterface $request,
                array $opts = [],
            ): Response {
                return $this->response;
            }
        });
    }

    private function stubContextGuidMapper(string $class, Response $response): void
    {
        Container::add($class, fn() => new class($response) {
            public function __construct(private Response $response)
            {
            }

            public function __invoke(
                \App\Backends\Common\Context $context,
                \App\Backends\Common\GuidInterface $guid,
                \App\Libs\Mappers\ImportInterface $mapper,
                ?\DateTimeInterface $after = null,
                array $opts = [],
            ): Response {
                return $this->response;
            }
        });
    }

    private function stubContextEntitiesQueue(string $class, Response $response): void
    {
        Container::add($class, fn() => new class($response) {
            public function __construct(private Response $response)
            {
            }

            public function __invoke(
                \App\Backends\Common\Context $context,
                array $entities,
                QueueRequests $queue,
                ?\DateTimeInterface $after = null,
            ): Response {
                return $this->response;
            }
        });
    }

    private function stubContextGuidEntitiesQueue(string $class, Response $response): void
    {
        Container::add($class, fn() => new class($response) {
            public function __construct(private Response $response)
            {
            }

            public function __invoke(
                \App\Backends\Common\Context $context,
                \App\Backends\Common\GuidInterface $guid,
                array $entities,
                QueueRequests $queue,
                ?\DateTimeInterface $after = null,
            ): Response {
                return $this->response;
            }
        });
    }

    private function stubContextEntitiesQueueOpts(string $class, Response $response): void
    {
        Container::add($class, fn() => new class($response) {
            public function __construct(private Response $response)
            {
            }

            public function __invoke(\App\Backends\Common\Context $context, array $entities, QueueRequests $queue, array $opts = []): Response
            {
                return $this->response;
            }
        });
    }

    private function stubGenerateAccessToken(string $class, Response $response): void
    {
        Container::add($class, fn() => new class($response) {
            public function __construct(private Response $response)
            {
            }

            public function __invoke(\App\Backends\Common\Context $context, string|int $identifier, string $password, array $opts = []): Response
            {
                return $this->response;
            }
        });
    }

    private function stubContextWebUrl(string $class, Response $response): void
    {
        Container::add($class, fn() => new class($response) {
            public function __construct(private Response $response)
            {
            }

            public function __invoke(\App\Backends\Common\Context $context, string $type, string|int $id): Response
            {
                return $this->response;
            }
        });
    }
}
