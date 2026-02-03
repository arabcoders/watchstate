<?php

declare(strict_types=1);

namespace Tests\Backends\MediaBrowser;

use App\Backends\Emby\Action\ToEntity as EmbyToEntity;
use App\Backends\Emby\Action\GetMetaData as EmbyGetMetaData;
use App\Backends\Emby\EmbyGuid;
use App\Backends\Jellyfin\Action\ToEntity as JellyfinToEntity;
use App\Backends\Jellyfin\Action\GetMetaData as JellyfinGetMetaData;
use App\Backends\Jellyfin\JellyfinGuid;
use App\Backends\Common\Response;
use App\Libs\Entity\StateInterface;
use App\Libs\Container;

class ToEntityTest extends MediaBrowserTestCase
{
    public function test_to_entity_success(): void
    {
        foreach ($this->provideBackends() as [$clientName, $actionClass, $guidClass]) {
            $context = $this->makeContext($clientName);
            $guid = new $guidClass($this->logger);
            $action = new $actionClass($guid);

            $result = $action($context, $this->fixture('metadata'));

            $this->assertTrue($result->isSuccessful());
            $this->assertInstanceOf(StateInterface::class, $result->response);
            $this->assertSame('Test Movie', $result->response->title);
        }
    }

    public function test_to_entity_episode_parent(): void
    {
        foreach ($this->provideBackends() as [$clientName, $actionClass, $guidClass, $metaClass]) {
            $context = $this->makeContext($clientName);
            $guid = new $guidClass($this->logger);
            $action = new $actionClass($guid);

            $item = $this->fixture('metadata_episode');
            $item['SeriesId'] = 'series-1';
            $item['ProviderIds']['Imdb'] = 'tt123';

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

            $result = $action($context, $item);

            $this->assertTrue($result->isSuccessful());
            $this->assertInstanceOf(StateInterface::class, $result->response);
            $this->assertNotEmpty($result->response->parent);
        }
    }

    private function provideBackends(): array
    {
        return [
            ['Jellyfin', JellyfinToEntity::class, JellyfinGuid::class, JellyfinGetMetaData::class],
            ['Emby', EmbyToEntity::class, EmbyGuid::class, EmbyGetMetaData::class],
        ];
    }
}
