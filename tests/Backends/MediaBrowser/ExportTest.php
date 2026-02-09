<?php

declare(strict_types=1);

namespace Tests\Backends\MediaBrowser;

use App\Backends\Emby\Action\Export as EmbyExport;
use App\Backends\Emby\EmbyGuid;
use App\Backends\Jellyfin\Action\Export as JellyfinExport;
use App\Backends\Jellyfin\JellyfinGuid;

class ExportTest extends MediaBrowserTestCase
{
    public function test_export_handles_empty_libraries(): void
    {
        foreach ($this->provideBackends() as [$clientName, $actionClass, $guidClass]) {
            $response = $this->makeResponse($this->fixture('libraries_empty'));
            $http = $this->makeHttpClient($response);
            $context = $this->makeContext($clientName);
            $guid = new $guidClass($this->logger);
            $mapper = $context->userContext->mapper;

            $action = new $actionClass($http, $this->logger);
            $result = $action($context, $guid, $mapper);

            $this->assertTrue($result->isSuccessful());
            $this->assertSame([], $result->response);
        }
    }

    private function provideBackends(): array
    {
        return [
            ['Jellyfin', JellyfinExport::class, JellyfinGuid::class],
            ['Emby', EmbyExport::class, EmbyGuid::class],
        ];
    }
}
