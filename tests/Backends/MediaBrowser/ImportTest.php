<?php

declare(strict_types=1);

namespace Tests\Backends\MediaBrowser;

use App\Backends\Emby\Action\Import as EmbyImport;
use App\Backends\Emby\EmbyGuid;
use App\Backends\Jellyfin\Action\Import as JellyfinImport;
use App\Backends\Jellyfin\JellyfinGuid;
use App\Libs\Options;
use Monolog\LogRecord;

class ImportTest extends MediaBrowserTestCase
{
    public function test_select_includes(): void
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

            $records = array_values(array_filter(
                $this->handler->getRecords(),
                static fn(LogRecord $record): bool => 'backend.item.ignored' === ($record->context['event_name'] ?? null)
                    && 'selected_excluded' === ($record->context['reason'] ?? null)
                    && 'lib-1' === (string) ag($record->context, 'library.id'),
            ));

            $this->assertNotEmpty($records);
            $record = end($records);
            $this->assertSame('backend.import', $record->context['subsystem'] ?? null);
        }
    }

    public function test_select_excludes(): void
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

    public function test_empty_libraries(): void
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

    public function test_count_status_failure(): void
    {
        foreach ($this->provideBackends() as [$clientName, $actionClass, $guidClass]) {
            $http = $this->makeHttpClient(
                $this->makeResponse($this->fixture('libraries')),
                $this->makeResponse([], 500),
            );
            $context = $this->makeContext($clientName, [Options::LIBRARY_SELECT => ['lib-1']]);
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
            $this->assertSame([], $result->response);

            $failed = array_values(array_filter(
                $this->handler->getRecords(),
                static fn(LogRecord $record): bool => 'backend.client.request_failed' === ($record->context['event_name'] ?? null)
                    && 'lib-1' === (string) ag($record->context, 'library.id'),
            ));
            $this->assertNotEmpty($failed);
            $record = end($failed);
            $this->assertSame('backend.import', $record->context['subsystem'] ?? null);
        }
    }

    public function test_unsupported_type(): void
    {
        foreach ($this->provideBackends() as [$clientName, $actionClass, $guidClass]) {
            $libraries = $this->fixture('libraries');
            $libraries['Items'][1]['CollectionType'] = 'music';

            $http = $this->makeHttpClient($this->makeResponse($libraries));
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
            $this->assertSame([], $result->response);

            $records = array_values(array_filter(
                $this->handler->getRecords(),
                static fn(LogRecord $record): bool => 'backend.item.ignored' === ($record->context['event_name'] ?? null)
                    && 'unsupported_library_type' === ($record->context['reason'] ?? null)
                    && 'lib-2' === (string) ag($record->context, 'library.id'),
            ));

            $this->assertNotEmpty($records);
            $record = end($records);
            $this->assertSame('backend.import', $record->context['subsystem'] ?? null);
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
