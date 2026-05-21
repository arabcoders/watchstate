<?php

declare(strict_types=1);

namespace Tests\Backends\Plex;

use App\Backends\Plex\Action\Import;
use App\Backends\Plex\PlexGuid;
use App\Libs\Options;
use Monolog\LogRecord;
use Symfony\Component\HttpClient\Response\MockResponse;

class ImportTest extends PlexTestCase
{
    public function test_select_includes(): void
    {
        $sections = ag($this->fixture('sections_get_200'), 'response.body');
        $sections['MediaContainer']['Directory'][1]['agent'] = 'tv.plex.agents.series';
        $sections['MediaContainer']['Directory'][1]['agent'] = 'tv.plex.agents.series';
        $http = $this->makeHttpClient(
            $this->makeResponse($sections),
            new MockResponse('', [
                'http_code' => 200,
                'response_headers' => ['X-Plex-Container-Total-Size' => '1'],
            ]),
            new MockResponse('', [
                'http_code' => 200,
                'response_headers' => ['X-Plex-Container-Total-Size' => '1'],
            ]),
        );
        $context = $this->makeContext([Options::LIBRARY_SELECT => ['2']]);
        $action = new Import($http, $this->logger);

        $result = $action(
            $context,
            new PlexGuid($this->logger),
            $context->userContext->mapper,
            null,
            [],
        );

        $this->assertTrue($result->isSuccessful());
        $this->assertCount(2, $result->response);
        foreach ($result->response as $request) {
            $logContext = $request->extras['logContext'] ?? [];
            $this->assertSame(2, (int) ag($logContext, 'library.id'));
        }

        $records = array_values(array_filter(
            $this->handler->getRecords(),
            static fn(LogRecord $record): bool => 'backend.item.ignored' === ($record->context['event_name'] ?? null)
                && 'selected_excluded' === ($record->context['reason'] ?? null)
                && 1 === (int) ag($record->context, 'library.id'),
        ));

        $this->assertNotEmpty($records);
        $record = end($records);
        $this->assertSame('backend.import', $record->context['subsystem'] ?? null);
    }

    public function test_select_excludes(): void
    {
        $sections = ag($this->fixture('sections_get_200'), 'response.body');
        $sections['MediaContainer']['Directory'][1]['agent'] = 'tv.plex.agents.series';
        $http = $this->makeHttpClient(
            $this->makeResponse($sections),
            new MockResponse('', [
                'http_code' => 200,
                'response_headers' => ['X-Plex-Container-Total-Size' => '1'],
            ]),
            new MockResponse('', [
                'http_code' => 200,
                'response_headers' => ['X-Plex-Container-Total-Size' => '1'],
            ]),
        );
        $context = $this->makeContext([
            Options::LIBRARY_SELECT => ['1'],
            Options::LIBRARY_INVERSE => true,
        ]);
        $action = new Import($http, $this->logger);

        $result = $action(
            $context,
            new PlexGuid($this->logger),
            $context->userContext->mapper,
            null,
            [],
        );

        $this->assertTrue($result->isSuccessful());
        $this->assertCount(2, $result->response);
        foreach ($result->response as $request) {
            $logContext = $request->extras['logContext'] ?? [];
            $this->assertSame(2, (int) ag($logContext, 'library.id'));
        }
    }

    public function test_empty_libraries(): void
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

    public function test_missing_series_count(): void
    {
        $sections = ag($this->fixture('sections_get_200'), 'response.body');
        $http = $this->makeHttpClient(
            $this->makeResponse($sections),
            new MockResponse('', [
                'http_code' => 200,
                'response_headers' => ['X-Plex-Container-Total-Size' => '1'],
            ]),
            new MockResponse('', [
                'http_code' => 500,
            ]),
        );
        $context = $this->makeContext([Options::LIBRARY_SELECT => ['2']]);
        $action = new Import($http, $this->logger);

        $result = $action(
            $context,
            new PlexGuid($this->logger),
            $context->userContext->mapper,
            null,
            [],
        );

        $this->assertTrue($result->isSuccessful());
        $this->assertCount(1, $result->response);

        $failed = array_values(array_filter(
            $this->handler->getRecords(),
            static fn(LogRecord $record): bool => 'backend.client.request_failed' === ($record->context['event_name'] ?? null)
                && 2 === (int) ag($record->context, 'library.id'),
        ));
        $ignored = array_values(array_filter(
            $this->handler->getRecords(),
            static fn(LogRecord $record): bool => 'backend.item.ignored' === ($record->context['event_name'] ?? null)
                && 'missing_series_count' === ($record->context['reason'] ?? null)
                && 2 === (int) ag($record->context, 'library.id'),
        ));

        $this->assertNotEmpty($failed);
        $failedRecord = end($failed);
        $this->assertSame('backend.import', $failedRecord->context['subsystem'] ?? null);
        $this->assertNotEmpty($ignored);
        $ignoredRecord = end($ignored);
        $this->assertSame('backend.import', $ignoredRecord->context['subsystem'] ?? null);
    }

    public function test_nfo_select(): void
    {
        $sections = [
            'MediaContainer' => [
                'Directory' => [
                    [
                        'key' => '5',
                        'title' => 'NFO Movies',
                        'type' => 'movie',
                        'agent' => 'tv.plex.agents.nfo.movie',
                    ],
                ],
            ],
        ];

        $http = $this->makeHttpClient(
            $this->makeResponse($sections),
            new MockResponse('', [
                'http_code' => 200,
                'response_headers' => ['X-Plex-Container-Total-Size' => '1'],
            ]),
        );
        $context = $this->makeContext([Options::LIBRARY_SELECT => ['5']]);
        $action = new Import($http, $this->logger);

        $result = $action(
            $context,
            new PlexGuid($this->logger),
            $context->userContext->mapper,
            null,
            [],
        );

        $this->assertTrue($result->isSuccessful());
        $this->assertCount(1, $result->response);
        $logContext = $result->response[0]->extras['logContext'] ?? [];
        $this->assertSame(5, (int) ag($logContext, 'library.id'));
    }
}
