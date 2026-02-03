<?php

declare(strict_types=1);

namespace Tests\Backends\Plex;

use App\Backends\Plex\Action\Export;
use App\Backends\Plex\PlexGuid;

class ExportTest extends PlexTestCase
{
    public function test_export_empty_libraries(): void
    {
        $payload = [
            'MediaContainer' => ['Directory' => []],
        ];

        $http = $this->makeHttpClient($this->makeResponse($payload));
        $context = $this->makeContext();
        $action = new Export($http, $this->logger);

        $result = $action($context, new PlexGuid($this->logger), $context->userContext->mapper);

        $this->assertTrue($result->isSuccessful());
        $this->assertSame([], $result->response);
    }
}
