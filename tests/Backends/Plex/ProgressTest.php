<?php

declare(strict_types=1);

namespace Tests\Backends\Plex;

use App\Backends\Common\Response;
use App\Backends\Plex\Action\GetSessions;
use App\Backends\Plex\Action\Progress;
use App\Backends\Plex\PlexGuid;
use App\Libs\Container;
use App\Libs\QueueRequests;

class ProgressTest extends PlexTestCase
{
    public function test_progress_empty_entities(): void
    {
        Container::add(GetSessions::class, fn() => new class() {
            public function __invoke(): Response
            {
                return new Response(status: true, response: ['sessions' => []]);
            }
        });

        $context = $this->makeContext();
        $queue = new QueueRequests();

        $action = new Progress($this->makeHttpClient(), $this->logger);
        $result = $action($context, new PlexGuid($this->logger), [], $queue);

        $this->assertTrue($result->isSuccessful());
        $this->assertSame(0, $queue->count());
    }
}
