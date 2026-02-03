<?php

declare(strict_types=1);

namespace Tests\Backends\Plex;

use App\Backends\Plex\Action\Push;
use App\Libs\QueueRequests;

class PushTest extends PlexTestCase
{
    public function test_push_empty_entities(): void
    {
        $http = $this->makeHttpClient();
        $context = $this->makeContext();
        $queue = new QueueRequests();

        $action = new Push($http, $this->logger);
        $result = $action($context, [], $queue);

        $this->assertTrue($result->isSuccessful());
        $this->assertSame(0, $queue->count());
    }
}
