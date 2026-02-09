<?php

declare(strict_types=1);

namespace Tests\Backends\MediaBrowser;

use App\Backends\Emby\Action\Backup as EmbyBackup;
use App\Backends\Emby\EmbyGuid;
use App\Backends\Jellyfin\Action\Backup as JellyfinBackup;
use App\Backends\Jellyfin\JellyfinGuid;

class BackupTest extends MediaBrowserTestCase
{
    public function test_backup_handles_empty_libraries(): void
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
            ['Jellyfin', JellyfinBackup::class, JellyfinGuid::class],
            ['Emby', EmbyBackup::class, EmbyGuid::class],
        ];
    }
}
