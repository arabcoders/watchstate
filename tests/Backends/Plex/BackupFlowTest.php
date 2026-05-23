<?php

declare(strict_types=1);

namespace Tests\Backends\Plex;

use App\Backends\Plex\Action\Backup;
use App\Backends\Plex\Action\GetMetaData;
use App\Backends\Plex\PlexGuid;
use App\Backends\Common\Response;
use App\Libs\Container;
use App\Libs\Stream;
use Monolog\LogRecord;
use ReflectionMethod;
use Tests\Support\FailingWriteStream;

class BackupFlowTest extends PlexTestCase
{
    public function test_backup_writes_json(): void
    {
        $context = $this->makeContext();
        $mapper = $context->userContext->mapper;
        $item = ag($this->fixture('library_movie_get_200'), 'response.body.MediaContainer.Metadata.0');

        $writer = new Stream('php://temp', 'w+');

        $action = new Backup($this->makeHttpClient(), $this->logger);
        $guid = (new PlexGuid($this->logger))->withContext($context);

        $this->invokeProcess(
            $action,
            $context,
            $guid,
            $mapper,
            $item,
            ['library' => ['id' => 1]],
            ['writer' => $writer, 'no_enhance' => true],
        );

        $writer->rewind();
        $content = (string) $writer;

        $this->assertStringContainsString('Ferengi', $content);
    }

    public function test_backup_episode_includes_parent(): void
    {
        $context = $this->makeContext();
        $mapper = $context->userContext->mapper;

        $item = [
            'ratingKey' => '11',
            'type' => 'episode',
            'title' => 'Pilot',
            'grandparentTitle' => 'Test Show',
            'parentIndex' => 1,
            'index' => 1,
            'addedAt' => 1000,
            'Guid' => [
                ['id' => 'imdb://tt123'],
            ],
            'grandparentRatingKey' => 'show-1',
            'viewOffset' => 70000,
        ];

        $showPayload = [
            'MediaContainer' => [
                'Metadata' => [
                    [
                        'ratingKey' => 'show-1',
                        'type' => 'show',
                        'title' => 'Test Show',
                        'Guid' => [
                            ['id' => 'imdb://tt123'],
                        ],
                        'guid' => 'imdb://tt123',
                    ],
                ],
            ],
        ];

        Container::add(GetMetaData::class, fn() => new class($showPayload) {
            public function __construct(private array $payload)
            {
            }

            public function __invoke(\App\Backends\Common\Context $context, string|int $id, array $opts = []): Response
            {
                return new Response(status: true, response: $this->payload);
            }
        });

        $writer = new Stream('php://temp', 'w+');

        $action = new Backup($this->makeHttpClient(), $this->logger);
        $guid = (new PlexGuid($this->logger))->withContext($context);

        $this->invokeProcess(
            $action,
            $context,
            $guid,
            $mapper,
            $item,
            ['library' => ['id' => 1]],
            ['writer' => $writer, 'no_enhance' => true],
        );

        $writer->rewind();
        $content = (string) $writer;

        $this->assertStringContainsString('parent', $content);
    }

    public function test_backup_logs_remote_id_on_failure(): void
    {
        $context = $this->makeContext();
        $mapper = $context->userContext->mapper;
        $item = ag($this->fixture('library_movie_get_200'), 'response.body.MediaContainer.Metadata.0');
        $writer = new FailingWriteStream('backup write failed');

        $action = new Backup($this->makeHttpClient(), $this->logger);
        $guid = (new PlexGuid($this->logger))->withContext($context);

        $this->invokeProcess(
            $action,
            $context,
            $guid,
            $mapper,
            $item,
            ['library' => ['id' => 1]],
            ['writer' => $writer, 'no_enhance' => true],
        );

        $records = array_values(array_filter(
            $this->handler->getRecords(),
            static fn(LogRecord $record): bool => 'backend.operation.failed' === ($record->context['event_name'] ?? null),
        ));

        $this->assertNotEmpty($records);
        $record = end($records);
        $this->assertSame('backend.backup', $record->context['subsystem'] ?? null);
        $this->assertSame('1', $record->context['item']['remote_id'] ?? null);
    }

    private function invokeProcess(
        object $action,
        \App\Backends\Common\Context $context,
        \App\Backends\Common\GuidInterface $guid,
        \App\Libs\Mappers\ImportInterface $mapper,
        array $item,
        array $logContext,
        array $opts,
    ): void {
        $method = new ReflectionMethod($action, 'process');
        $method->invoke($action, $context, $guid, $mapper, $item, $logContext, $opts);
    }
}
