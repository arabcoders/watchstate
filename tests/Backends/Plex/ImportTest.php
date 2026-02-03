<?php

declare(strict_types=1);

namespace Tests\Backends\Plex;

use App\Backends\Plex\Action\Import;
use App\Backends\Plex\PlexGuid;

class ImportTest extends PlexTestCase
{
    public function test_import_empty_libraries(): void
    {
        $payload = [
            'MediaContainer' => ['Directory' => []],
        ];

        $http = $this->makeHttpClient($this->makeResponse($payload));
        $context = $this->makeContext();
        $action = new Import($http, $this->logger);

        $result = $action($context, new PlexGuid($this->logger), $context->userContext->mapper);

        $this->assertTrue($result->isSuccessful());
        $this->assertSame([], $result->response);
    }
}
