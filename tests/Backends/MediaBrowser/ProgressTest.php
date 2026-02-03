<?php

declare(strict_types=1);

namespace Tests\Backends\MediaBrowser;

use App\Backends\Emby\Action\Progress as EmbyProgress;
use App\Backends\Emby\EmbyGuid;
use App\Backends\Jellyfin\Action\Progress as JellyfinProgress;
use App\Backends\Jellyfin\JellyfinGuid;
use App\Libs\QueueRequests;

class ProgressTest extends MediaBrowserTestCase
{
    public function test_progress_handles_empty_entities(): void
    {
        foreach ($this->provideBackends() as [$clientName, $actionClass, $guidClass]) {
            $http = $this->makeHttpClient();
            $context = $this->makeContext($clientName);
            $queue = new QueueRequests();
            $guid = new $guidClass($this->logger);

            $action = new $actionClass($http, $this->logger);
            $result = $action($context, $guid, [], $queue);

            $this->assertTrue($result->isSuccessful());
            $this->assertSame(0, $queue->count());
        }
    }

    private function provideBackends(): array
    {
        return [
            ['Jellyfin', JellyfinProgress::class, JellyfinGuid::class],
            ['Emby', EmbyProgress::class, EmbyGuid::class],
        ];
    }
}
