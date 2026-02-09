<?php

declare(strict_types=1);

namespace Tests\Backends\Plex;

use App\Backends\Plex\Action\UpdateState;
use App\Libs\Entity\StateEntity;
use App\Libs\Entity\StateInterface as iState;
use App\Libs\Options;
use App\Libs\QueueRequests;
use Symfony\Component\HttpClient\Response\MockResponse;

class UpdateStateTest extends PlexTestCase
{
    public function test_update_state_queues_request(): void
    {
        $http = new \App\Libs\Extends\HttpClient(
            new \App\Libs\Extends\MockHttpClient(
                new MockResponse('', ['http_code' => 200]),
            ),
        );
        $context = $this->makeContext();
        $queue = new QueueRequests();

        $entity = StateEntity::fromArray([
            iState::COLUMN_TYPE => iState::TYPE_MOVIE,
            iState::COLUMN_UPDATED => 2000,
            iState::COLUMN_WATCHED => 1,
            iState::COLUMN_VIA => $context->backendName,
            iState::COLUMN_TITLE => 'Test Movie',
            iState::COLUMN_META_DATA => [
                $context->backendName => [
                    iState::COLUMN_ID => '1',
                    iState::COLUMN_TYPE => iState::TYPE_MOVIE,
                    iState::COLUMN_WATCHED => '0',
                    iState::COLUMN_TITLE => 'Test Movie',
                ],
            ],
        ]);

        $action = new UpdateState($http, $this->logger);
        $result = $action($context, [$entity], $queue);

        $this->assertTrue($result->isSuccessful());
        $this->assertSame(1, $queue->count());
    }

    public function test_update_state_dry_run(): void
    {
        $http = $this->makeHttpClient();
        $context = $this->makeContext([Options::DRY_RUN => true]);
        $queue = new QueueRequests();

        $entity = StateEntity::fromArray([
            iState::COLUMN_TYPE => iState::TYPE_MOVIE,
            iState::COLUMN_UPDATED => 2000,
            iState::COLUMN_WATCHED => 1,
            iState::COLUMN_VIA => $context->backendName,
            iState::COLUMN_TITLE => 'Test Movie',
            iState::COLUMN_META_DATA => [
                $context->backendName => [
                    iState::COLUMN_ID => '1',
                    iState::COLUMN_TYPE => iState::TYPE_MOVIE,
                    iState::COLUMN_WATCHED => '0',
                    iState::COLUMN_TITLE => 'Test Movie',
                ],
            ],
        ]);

        $action = new UpdateState($http, $this->logger);
        $result = $action($context, [$entity], $queue);

        $this->assertTrue($result->isSuccessful());
        $this->assertSame(0, $queue->count());
    }
}
