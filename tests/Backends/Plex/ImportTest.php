<?php

declare(strict_types=1);

namespace Tests\Backends\Plex;

use App\Backends\Plex\Action\Import;
use App\Backends\Plex\PlexGuid;
use App\Libs\Options;
use Symfony\Component\HttpClient\Response\MockResponse;

class ImportTest extends PlexTestCase
{
    public function test_import_library_select_includes_only_selected(): void
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
    }

    public function test_import_library_select_inverse_excludes_selected(): void
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
