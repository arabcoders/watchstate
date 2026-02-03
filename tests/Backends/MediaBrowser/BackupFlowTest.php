<?php

declare(strict_types=1);

namespace Tests\Backends\MediaBrowser;

use App\Backends\Emby\Action\Backup as EmbyBackup;
use App\Backends\Emby\Action\GetMetaData as EmbyGetMetaData;
use App\Backends\Emby\EmbyGuid;
use App\Backends\Jellyfin\Action\Backup as JellyfinBackup;
use App\Backends\Jellyfin\Action\GetMetaData as JellyfinGetMetaData;
use App\Backends\Jellyfin\JellyfinGuid;
use App\Backends\Common\Response;
use App\Libs\Container;
use App\Libs\Stream;
use ReflectionMethod;

class BackupFlowTest extends MediaBrowserTestCase
{
    public function test_backup_writes_json(): void
    {
        foreach ($this->provideBackends() as [$clientName, $actionClass, $guidClass]) {
            $context = $this->makeContext($clientName);
            $mapper = $context->userContext->mapper;
            $item = $this->fixture('metadata');

            $writer = new Stream('php://temp', 'w+');

            $action = new $actionClass($this->makeHttpClient(), $this->logger);
            $guid = (new $guidClass($this->logger))->withContext($context);

            $this->invokeProcess(
                $action,
                $context,
                $guid,
                $mapper,
                $item,
                ['library' => ['id' => 'lib-1']],
                ['writer' => $writer, 'no_enhance' => true],
            );

            $writer->rewind();
            $content = (string) $writer;

            $this->assertStringContainsString('Test Movie', $content);
        }
    }

    public function test_backup_episode_includes_parent(): void
    {
        foreach ($this->provideBackends() as [$clientName, $actionClass, $guidClass, $metaClass]) {
            $context = $this->makeContext($clientName);
            $mapper = $context->userContext->mapper;
            $item = $this->fixture('metadata_episode');
            $item['SeriesId'] = 'series-1';
            $item['UserData']['Played'] = false;
            $item['UserData']['PlaybackPositionTicks'] = 7000000000;

            $showPayload = [
                'Id' => 'series-1',
                'Name' => 'Test Show',
                'Type' => 'Series',
                'ProductionYear' => 2020,
                'ProviderIds' => ['Imdb' => 'tt123'],
            ];

            Container::add($metaClass, fn() => new class($showPayload) {
                public function __construct(private array $payload)
                {
                }

                public function __invoke(\App\Backends\Common\Context $context, string|int $id, array $opts = []): Response
                {
                    return new Response(status: true, response: $this->payload);
                }
            });

            $writer = new Stream('php://temp', 'w+');

            $action = new $actionClass($this->makeHttpClient(), $this->logger);
            $guid = (new $guidClass($this->logger))->withContext($context);

            $this->invokeProcess(
                $action,
                $context,
                $guid,
                $mapper,
                $item,
                ['library' => ['id' => 'lib-2']],
                ['writer' => $writer, 'no_enhance' => true],
            );

            $writer->rewind();
            $content = (string) $writer;

            $this->assertStringContainsString('parent', $content);
        }
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

    private function provideBackends(): array
    {
        return [
            ['Jellyfin', JellyfinBackup::class, JellyfinGuid::class, JellyfinGetMetaData::class],
            ['Emby', EmbyBackup::class, EmbyGuid::class, EmbyGetMetaData::class],
        ];
    }
}
