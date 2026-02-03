<?php

declare(strict_types=1);

namespace Tests\Backends\MediaBrowser;

use App\Backends\Emby\Action\UpdateState as EmbyUpdateState;
use App\Backends\Jellyfin\Action\UpdateState as JellyfinUpdateState;
use App\Libs\QueueRequests;

class UpdateStateTest extends MediaBrowserTestCase
{
    public function test_update_state_handles_empty_entities(): void
    {
        foreach ($this->provideBackends() as [$clientName, $actionClass]) {
            $http = $this->makeHttpClient();
            $context = $this->makeContext($clientName);
            $queue = new QueueRequests();

            $action = new $actionClass($http, $this->logger);
            $result = $action($context, [], $queue);

            $this->assertTrue($result->isSuccessful());
            $this->assertSame(0, $queue->count());
        }
    }

    private function provideBackends(): array
    {
        return [
            ['Jellyfin', JellyfinUpdateState::class],
            ['Emby', EmbyUpdateState::class],
        ];
    }
}
