<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Backends\Common\ClientInterface;
use App\Backends\Common\Context;
use App\Backends\Common\GuidInterface;
use App\Backends\Common\Request;
use App\Backends\Common\Response;
use App\Libs\Entity\StateEntity;
use App\Libs\Entity\StateInterface as iState;
use App\Libs\Enums\Http\Method;
use App\Libs\Guid;
use App\Libs\Mappers\ImportInterface as iImport;
use App\Libs\Options;
use App\Libs\QueueRequests;
use App\Libs\Uri;
use DateTimeInterface as iDate;
use Psr\Http\Message\ServerRequestInterface as iRequest;
use Psr\Http\Message\StreamInterface as iStream;
use Psr\Http\Message\UriInterface as iUri;
use Psr\Log\LoggerInterface as iLogger;
use Throwable;

class FakeBackendClient implements ClientInterface
{
    private ?Context $context = null;

    private ?iLogger $logger = null;

    private GuidInterface $guid;

    /** @var array<string,array<int,array<string,mixed>>> */
    private static array $calls = [
        'metadata' => [],
        'pull' => [],
        'backup' => [],
        'export' => [],
        'push' => [],
        'progress' => [],
        'update_state' => [],
        'proxy' => [],
    ];

    /** @var array<string,array<string,mixed>|Throwable> */
    private static array $metadataResponses = [];

    /** @var array<string,Throwable> */
    private static array $exportErrors = [];

    /** @var array<string,bool> */
    private static array $skipBackupWrites = [];

    /** @var array<string,int> */
    private static array $queuedExportRequests = [];

    /** @var array<string,Response|Throwable> */
    private static array $proxyResponses = [];

    public function __construct()
    {
        $this->guid = new FakeGuid();
    }

    public static function reset(): void
    {
        self::$calls = [
            'metadata' => [],
            'pull' => [],
            'backup' => [],
            'export' => [],
            'push' => [],
            'progress' => [],
            'update_state' => [],
            'proxy' => [],
        ];
        self::$metadataResponses = [];
        self::$exportErrors = [];
        self::$skipBackupWrites = [];
        self::$queuedExportRequests = [];
        self::$proxyResponses = [];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public static function getCalls(string $type): array
    {
        return self::$calls[$type] ?? [];
    }

    public static function setMetadataResponse(
        string $user,
        string $backend,
        string|int $id,
        array|Throwable $response,
    ): void {
        self::$metadataResponses[self::metadataKey($user, $backend, $id)] = $response;
    }

    public static function setExportError(string $user, string $backend, Throwable $error): void
    {
        self::$exportErrors[self::exportKey($user, $backend)] = $error;
    }

    public static function setSkipBackupWrite(string $user, string $backend, bool $skip = true): void
    {
        self::$skipBackupWrites[self::exportKey($user, $backend)] = $skip;
    }

    public static function setQueuedExportRequests(string $user, string $backend, int $count): void
    {
        self::$queuedExportRequests[self::exportKey($user, $backend)] = $count;
    }

    /**
     * Register a fixture Response (or Throwable) to be returned by proxy() for the given user/backend pair.
     *
     * @param Response|Throwable $response the response to return or exception to throw.
     */
    public static function setProxyResponse(string $user, string $backend, Response|Throwable $response): void
    {
        self::$proxyResponses[self::exportKey($user, $backend)] = $response;
    }

    public function withContext(Context $context): ClientInterface
    {
        $instance = clone $this;
        $instance->context = $context;
        $instance->guid = $this->guid->withContext($context);

        return $instance;
    }

    public function getContext(): Context
    {
        return $this->requireContext();
    }

    public function getName(): string
    {
        return $this->requireContext()->backendName;
    }

    public function getType(): string
    {
        return $this->requireContext()->clientName;
    }

    public function setLogger(iLogger $logger): ClientInterface
    {
        $this->logger = $logger;

        return $this;
    }

    public function processRequest(iRequest $request, array $opts = []): iRequest
    {
        $context = $this->requireContext();

        return $request
            ->withAttribute('backend', [
                'id' => $context->backendId,
                'name' => $context->backendName,
            ])
            ->withAttribute('user', [
                'id' => $context->backendUser,
                'name' => $context->userContext->name,
            ]);
    }

    public function parseWebhook(iRequest $request, array $opts = []): iState
    {
        return StateEntity::fromArray($this->makeEntityData());
    }

    public function pull(iImport $mapper, ?iDate $after = null): array
    {
        $context = $this->requireContext();

        self::record('pull', [
            'backend' => $context->backendName,
            'user' => $context->userContext->name,
            'after' => null === $after ? null : $after->getTimestamp(),
        ]);

        return [];
    }

    public function backup(iImport $mapper, ?iStream $writer = null, array $opts = []): array
    {
        $context = $this->requireContext();

        self::record('backup', [
            'backend' => $context->backendName,
            'user' => $context->userContext->name,
            'dry_run' => (bool) ag($opts, Options::DRY_RUN, false),
            'no_enhance' => (bool) ag($opts, 'no_enhance', false),
        ]);

        if (
            null !== $writer
            && false === (bool) ag($opts, Options::DRY_RUN, false)
            && true !== (self::$skipBackupWrites[self::exportKey($context->userContext->name, $context->backendName)] ?? false)
        ) {
            $payload = json_encode(
                [
                    'backend' => $context->backendName,
                    'user' => $context->userContext->name,
                ],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            );

            $writer->write($payload . ',');
        }

        return [];
    }

    public function export(iImport $mapper, QueueRequests $queue, ?iDate $after = null): array
    {
        $context = $this->requireContext();

        self::record('export', [
            'backend' => $context->backendName,
            'user' => $context->userContext->name,
            'after' => null === $after ? null : $after->getTimestamp(),
        ]);

        if (null !== ($error = self::$exportErrors[self::exportKey($context->userContext->name, $context->backendName)] ?? null)) {
            throw $error;
        }

        $count = self::$queuedExportRequests[self::exportKey($context->userContext->name, $context->backendName)] ?? 0;

        for ($i = 0; $i < $count; $i++) {
            $queue->add(new Request(
                method: Method::GET,
                url: new Uri(r('https://restore-{backend}-{i}.example.invalid', [
                    'backend' => $context->backendName,
                    'i' => $i,
                ])),
            ));
        }

        return [];
    }

    public function push(array $entities, QueueRequests $queue, ?iDate $after = null): array
    {
        $context = $this->requireContext();

        $this->logger?->debug('fake.push: hidden debug for {user}@{backend}', [
            'user' => $context->userContext->name,
            'backend' => $context->backendName,
        ]);
        $this->logger?->info('fake.push: visible info for {user}@{backend}', [
            'user' => $context->userContext->name,
            'backend' => $context->backendName,
        ]);

        self::record('push', [
            'backend' => $context->backendName,
            'user' => $context->userContext->name,
            'count' => count($entities),
            'after' => null === $after ? null : $after->getTimestamp(),
        ]);

        return [];
    }

    public function progress(array $entities, QueueRequests $queue, ?iDate $after = null): array
    {
        $context = $this->requireContext();

        $this->logger?->debug('fake.progress: hidden debug for {user}@{backend}', [
            'user' => $context->userContext->name,
            'backend' => $context->backendName,
        ]);
        $this->logger?->info('fake.progress: visible info for {user}@{backend}', [
            'user' => $context->userContext->name,
            'backend' => $context->backendName,
        ]);

        self::record('progress', [
            'backend' => $context->backendName,
            'user' => $context->userContext->name,
            'count' => count($entities),
            'after' => null === $after ? null : $after->getTimestamp(),
        ]);

        return [];
    }

    public function search(string $query, int $limit = 25, array $opts = []): array
    {
        return [];
    }

    public function searchId(string|int $id, array $opts = []): array
    {
        return [];
    }

    public function getMetadata(string|int $id, array $opts = []): array
    {
        $context = $this->requireContext();

        self::record('metadata', [
            'backend' => $context->backendName,
            'user' => $context->userContext->name,
            'id' => (string) $id,
        ]);

        $response = self::$metadataResponses[self::metadataKey($context->userContext->name, $context->backendName, $id)] ?? [
            'Id' => (string) $id,
        ];

        if ($response instanceof Throwable) {
            throw $response;
        }

        return $response;
    }

    public function getImagesUrl(string|int $id, array $opts = []): array
    {
        return [];
    }

    public function proxy(Method $method, iUri $uri, array|iStream $body = [], array $opts = []): Response
    {
        $context = $this->requireContext();

        self::record('proxy', [
            'backend' => $context->backendName,
            'user' => $context->userContext->name,
            'method' => $method->value,
            'url' => (string) $uri,
            'body' => is_array($body) ? $body : null,
            'headers' => ag($opts, 'headers', []),
        ]);

        $fixture = self::$proxyResponses[self::exportKey($context->userContext->name, $context->backendName)] ?? null;

        if ($fixture instanceof Throwable) {
            throw $fixture;
        }

        if ($fixture instanceof Response) {
            return $fixture;
        }

        return new Response(status: true);
    }

    public function getLibraryContent(string|int $libraryId, array $opts = []): array
    {
        return [];
    }

    public function getLibrary(string|int $id, array $opts = []): array
    {
        return [];
    }

    public function getIdentifier(bool $forceRefresh = false): int|string|null
    {
        return 'fake-' . $this->requireContext()->backendName;
    }

    public function getUsersList(array $opts = []): array
    {
        return [];
    }

    public function getPlaylistsList(array $opts = []): array
    {
        return [];
    }

    public function getPlaylist(string|int $id, array $opts = []): array
    {
        return [];
    }

    public function createPlaylist(string $title, array $itemIds = [], array $opts = []): array
    {
        return [];
    }

    public function deletePlaylist(string|int $id, array $opts = []): array
    {
        return [];
    }

    public function listLibraries(array $opts = []): array
    {
        return [];
    }

    public function fromRequest(array $config, iRequest $request): array
    {
        return $config;
    }

    public function validateContext(Context $context): bool
    {
        return true;
    }

    public function getSessions(array $opts = []): array
    {
        return ['sessions' => []];
    }

    public function getUserToken(int|string $userId, string $username, array $opts = []): string|bool
    {
        return false;
    }

    public function getWebUrl(string $type, int|string $id): iUri
    {
        return new Uri(r('https://example.invalid/{type}/{id}', [
            'type' => $type,
            'id' => $id,
        ]));
    }

    public function toEntity(array $item, array $opts = []): iState
    {
        return StateEntity::fromArray($this->makeEntityData());
    }

    public function getInfo(array $opts = []): array
    {
        return [
            'name' => $this->getName(),
            'type' => $this->getType(),
        ];
    }

    public function getVersion(array $opts = []): string
    {
        return '1.0.0';
    }

    public function generateAccessToken(string|int $identifier, string $password, array $opts = []): array
    {
        return ['accessToken' => 'fake-token'];
    }

    public function getGuid(): GuidInterface
    {
        return $this->guid;
    }

    public function updateState(array $entities, QueueRequests $queue, array $opts = []): void
    {
        $context = $this->requireContext();

        self::record('update_state', [
            'backend' => $context->backendName,
            'user' => $context->userContext->name,
            'count' => count($entities),
            'options' => $opts,
        ]);
    }

    public function addWebhook(string $webhookUrl, array $opts = []): Response
    {
        return new Response(status: true);
    }

    private function requireContext(): Context
    {
        assert($this->context instanceof Context, 'Expected backend context before invoking fake backend client.');

        return $this->context;
    }

    private static function metadataKey(string $user, string $backend, string|int $id): string
    {
        return r('{user}:{backend}:{id}', [
            'user' => $user,
            'backend' => $backend,
            'id' => $id,
        ]);
    }

    private static function exportKey(string $user, string $backend): string
    {
        return r('{user}:{backend}', [
            'user' => $user,
            'backend' => $backend,
        ]);
    }

    /**
     * @param array<string,mixed> $payload
     */
    private static function record(string $type, array $payload): void
    {
        self::$calls[$type][] = $payload;
    }

    /**
     * @return array<string,mixed>
     */
    private function makeEntityData(): array
    {
        $context = $this->requireContext();

        return [
            iState::COLUMN_ID => null,
            iState::COLUMN_TYPE => iState::TYPE_MOVIE,
            iState::COLUMN_UPDATED => 1,
            iState::COLUMN_WATCHED => 0,
            iState::COLUMN_VIA => $context->backendName,
            iState::COLUMN_TITLE => 'Fake Movie',
            iState::COLUMN_YEAR => 2024,
            iState::COLUMN_SEASON => null,
            iState::COLUMN_EPISODE => null,
            iState::COLUMN_PARENT => [],
            iState::COLUMN_GUIDS => [
                Guid::GUID_IMDB => 'tt-fake-' . $context->backendName,
            ],
            iState::COLUMN_META_DATA => [
                $context->backendName => [
                    iState::COLUMN_ID => 1,
                    iState::COLUMN_TYPE => iState::TYPE_MOVIE,
                    iState::COLUMN_WATCHED => 0,
                    iState::COLUMN_META_DATA_ADDED_AT => 1,
                ],
            ],
            iState::COLUMN_EXTRA => [],
            iState::COLUMN_CREATED_AT => 1,
            iState::COLUMN_UPDATED_AT => 1,
        ];
    }
}
