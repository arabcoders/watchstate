<?php

declare(strict_types=1);

namespace Tests\Backends\MediaBrowser;

use App\Backends\Emby\Action\UpdateState as EmbyUpdateState;
use App\Backends\Jellyfin\Action\UpdateState as JellyfinUpdateState;
use App\Libs\Entity\StateEntity;
use App\Libs\Entity\StateInterface as iState;
use App\Libs\Options;
use App\Libs\QueueRequests;

class UpdateStateDryRunTest extends MediaBrowserTestCase
{
    public function test_update_state_dry_run_no_queue(): void
    {
        foreach ($this->provideBackends() as [$clientName, $actionClass]) {
            $http = $this->makeHttpClient();
            $context = $this->makeContext($clientName, [Options::DRY_RUN => true]);
            $queue = new QueueRequests();

            $entity = StateEntity::fromArray([
                iState::COLUMN_TYPE => iState::TYPE_MOVIE,
                iState::COLUMN_UPDATED => 2000,
                iState::COLUMN_WATCHED => 1,
                iState::COLUMN_VIA => $context->backendName,
                iState::COLUMN_TITLE => 'Test Movie',
                iState::COLUMN_META_DATA => [
                    $context->backendName => [
                        iState::COLUMN_ID => 'item-1',
                        iState::COLUMN_TYPE => iState::TYPE_MOVIE,
                        iState::COLUMN_WATCHED => '0',
                        iState::COLUMN_TITLE => 'Test Movie',
                    ],
                ],
            ]);

            $action = new $actionClass($http, $this->logger);
            $result = $action($context, [$entity], $queue);

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
