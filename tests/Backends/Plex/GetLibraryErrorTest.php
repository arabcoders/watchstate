<?php

declare(strict_types=1);

namespace Tests\Backends\Plex;

use App\Backends\Common\Response;
use App\Backends\Plex\Action\GetLibrary;
use App\Backends\Plex\Action\GetLibrariesList;
use App\Backends\Plex\PlexGuid;
use App\Libs\Container;

class GetLibraryErrorTest extends PlexTestCase
{
    public function test_get_library_missing_id(): void
    {
        Container::add(GetLibrariesList::class, fn() => new class() {
            public function __invoke(): Response
            {
                return new Response(status: true, response: []);
            }
        });

        $context = $this->makeContext();
        $action = new GetLibrary($this->makeHttpClient(), $this->logger);
        $result = $action($context, new PlexGuid($this->logger), '1');

        $this->assertFalse($result->isSuccessful());
        $this->assertNotNull($result->error);
    }

    public function test_get_library_unsupported_type(): void
    {
        Container::add(GetLibrariesList::class, fn() => new class() {
            public function __invoke(): Response
            {
                return new Response(status: true, response: [
                    [
                        'id' => '1',
                        'type' => 'photo',
                        'title' => 'Photos',
                        'raw' => ['type' => 'photo', 'title' => 'Photos'],
                    ],
                ]);
            }
        });

        $context = $this->makeContext();
        $action = new GetLibrary($this->makeHttpClient(), $this->logger);
        $result = $action($context, new PlexGuid($this->logger), '1');

        $this->assertFalse($result->isSuccessful());
        $this->assertNotNull($result->error);
    }
}
