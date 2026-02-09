<?php

declare(strict_types=1);

namespace Tests\Backends\MediaBrowser;

use App\Backends\Emby\Action\Import as EmbyImport;
use App\Backends\Emby\Action\GetMetaData as EmbyGetMetaData;
use App\Backends\Emby\EmbyGuid;
use App\Backends\Jellyfin\Action\Import as JellyfinImport;
use App\Backends\Jellyfin\Action\GetMetaData as JellyfinGetMetaData;
use App\Backends\Jellyfin\JellyfinGuid;
use App\Backends\Common\Response;
use App\Libs\Container;
use ReflectionMethod;

class ImportFlowTest extends MediaBrowserTestCase
{
    public function test_import_process_adds_items(): void
    {
        foreach ($this->provideBackends() as [$clientName, $actionClass, $guidClass]) {
            $context = $this->makeContext($clientName);
            $mapper = $context->userContext->mapper;
            $item = $this->fixture('metadata');
            $item['UserData']['PlaybackPositionTicks'] = 0;

            $action = new $actionClass($this->makeHttpClient(), $this->logger);
            $guid = (new $guidClass($this->logger))->withContext($context);

            $this->invokeProcess(
                $action,
                $context,
                $guid,
                $mapper,
                $item,
                ['library' => ['id' => 'lib-1']],
                [],
            );

            $result = $mapper->commit();

            $this->assertSame(1, $result['movie']['added']);
        }
    }

    public function test_import_ignores_missing_date(): void
    {
        foreach ($this->provideBackends() as [$clientName, $actionClass, $guidClass]) {
            $context = $this->makeContext($clientName);
            $mapper = $context->userContext->mapper;
            $item = $this->fixture('metadata');
            unset($item['DateCreated']);
            $item['UserData']['PlaybackPositionTicks'] = 0;

            $action = new $actionClass($this->makeHttpClient(), $this->logger);
            $guid = (new $guidClass($this->logger))->withContext($context);

            $this->invokeProcess(
                $action,
                $context,
                $guid,
                $mapper,
                $item,
                ['library' => ['id' => 'lib-1']],
                [],
            );

            $result = $mapper->commit();

            $this->assertSame(0, $result['movie']['added']);
        }
    }

    public function test_import_process_show_caches_guid(): void
    {
        foreach ($this->provideBackends() as [$clientName, $actionClass, $guidClass]) {
            $context = $this->makeContext($clientName);
            $item = [
                'Id' => 'series-1',
                'Name' => 'Test Show',
                'Type' => 'Series',
                'ProductionYear' => 2020,
                'ProviderIds' => [
                    'Imdb' => 'tt123',
                ],
            ];

            $action = new $actionClass($this->makeHttpClient(), $this->logger);
            $guid = (new $guidClass($this->logger))->withContext($context);

            $this->invokeProcessShow($action, $context, $guid, $item, []);

            $cacheKey = 'Series.' . $item['Id'];
            $this->assertNotSame([], $context->cache->get($cacheKey, []));
        }
    }

    public function test_import_ignores_no_supported_guids(): void
    {
        foreach ($this->provideBackends() as [$clientName, $actionClass, $guidClass]) {
            $context = $this->makeContext($clientName);
            $mapper = $context->userContext->mapper;
            $item = $this->fixture('metadata');
            $item['ProviderIds'] = [];
            $item['UserData']['PlaybackPositionTicks'] = 0;

            $action = new $actionClass($this->makeHttpClient(), $this->logger);
            $guid = (new $guidClass($this->logger))->withContext($context);

            $this->invokeProcess(
                $action,
                $context,
                $guid,
                $mapper,
                $item,
                ['library' => ['id' => 'lib-1']],
                [],
            );

            $result = $mapper->commit();

            $this->assertSame(0, $result['movie']['added']);
        }
    }

    public function test_import_process_episode_adds_item(): void
    {
        foreach ($this->provideBackends() as [$clientName, $actionClass, $guidClass, $metaClass]) {
            $context = $this->makeContext($clientName);
            $mapper = $context->userContext->mapper;

            $item = $this->fixture('metadata_episode');
            $item['SeriesId'] = 'series-1';
            $item['UserData']['Played'] = false;
            $item['UserData']['PlaybackPositionTicks'] = 0;

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

            $action = new $actionClass($this->makeHttpClient(), $this->logger);
            $guid = (new $guidClass($this->logger))->withContext($context);

            $this->invokeProcess(
                $action,
                $context,
                $guid,
                $mapper,
                $item,
                ['library' => ['id' => 'lib-2']],
                [],
            );

            $result = $mapper->commit();

            $this->assertSame(1, $result['episode']['added']);
        }
    }

    public function test_import_ignores_invalid_type(): void
    {
        foreach ($this->provideBackends() as [$clientName, $actionClass, $guidClass]) {
            $context = $this->makeContext($clientName);
            $mapper = $context->userContext->mapper;
            $item = $this->fixture('metadata');
            $item['Type'] = 'Audio';

            $action = new $actionClass($this->makeHttpClient(), $this->logger);
            $guid = (new $guidClass($this->logger))->withContext($context);

            $this->invokeProcess(
                $action,
                $context,
                $guid,
                $mapper,
                $item,
                ['library' => ['id' => 'lib-1']],
                [],
            );

            $result = $mapper->commit();

            $this->assertSame(0, $result['movie']['added']);
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

    private function invokeProcessShow(
        object $action,
        \App\Backends\Common\Context $context,
        \App\Backends\Common\GuidInterface $guid,
        array $item,
        array $logContext,
    ): void {
        $method = new ReflectionMethod($action, 'processShow');
        $method->invoke($action, $context, $guid, $item, $logContext);
    }

    private function provideBackends(): array
    {
        return [
            ['Jellyfin', JellyfinImport::class, JellyfinGuid::class, JellyfinGetMetaData::class],
            ['Emby', EmbyImport::class, EmbyGuid::class, EmbyGetMetaData::class],
        ];
    }
}
