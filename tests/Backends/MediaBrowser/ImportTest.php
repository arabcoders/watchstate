<?php

declare(strict_types=1);

namespace Tests\Backends\MediaBrowser;

use App\Backends\Emby\Action\Import as EmbyImport;
use App\Backends\Emby\EmbyGuid;
use App\Backends\Jellyfin\Action\Import as JellyfinImport;
use App\Backends\Jellyfin\JellyfinGuid;
use App\Libs\Options;

class ImportTest extends MediaBrowserTestCase
{
    public function test_import_library_select_includes_only_selected(): void
    {
        foreach ($this->provideBackends() as [$clientName, $actionClass, $guidClass]) {
            $http = $this->makeHttpClient(
                $this->makeResponse($this->fixture('libraries')),
                $this->makeResponse(['TotalRecordCount' => 1]),
            );
            $context = $this->makeContext($clientName, [Options::LIBRARY_SELECT => ['lib-2']]);
            $guid = new $guidClass($this->logger);
            $mapper = $context->userContext->mapper;
            $action = new $actionClass($http, $this->logger);

            $result = $action(
                $context,
                $guid,
                $mapper,
                null,
                [],
            );

            $this->assertTrue($result->isSuccessful());
            $this->assertCount(2, $result->response);
            $libraryIds = array_map(
                static fn($request) => (string) ag($request->extras['logContext'] ?? [], 'library.id'),
                $result->response,
            );
            $this->assertSame(['lib-2'], array_values(array_unique($libraryIds)));
        }
    }

    public function test_import_library_select_inverse_excludes_selected(): void
    {
        foreach ($this->provideBackends() as [$clientName, $actionClass, $guidClass]) {
            $http = $this->makeHttpClient(
                $this->makeResponse($this->fixture('libraries')),
                $this->makeResponse(['TotalRecordCount' => 1]),
            );
            $context = $this->makeContext($clientName, [
                Options::LIBRARY_SELECT => ['lib-1'],
                Options::LIBRARY_INVERSE => true,
            ]);
            $guid = new $guidClass($this->logger);
            $mapper = $context->userContext->mapper;
            $action = new $actionClass($http, $this->logger);

            $result = $action(
                $context,
                $guid,
                $mapper,
                null,
                [],
            );

            $this->assertTrue($result->isSuccessful());
            $this->assertCount(2, $result->response);
            $libraryIds = array_map(
                static fn($request) => (string) ag($request->extras['logContext'] ?? [], 'library.id'),
                $result->response,
            );
            $this->assertSame(['lib-2'], array_values(array_unique($libraryIds)));
        }
    }

    public function test_import_handles_empty_libraries(): void
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
            ['Jellyfin', JellyfinImport::class, JellyfinGuid::class],
            ['Emby', EmbyImport::class, EmbyGuid::class],
        ];
    }
}
