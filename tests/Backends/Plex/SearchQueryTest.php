<?php

declare(strict_types=1);

namespace Tests\Backends\Plex;

use App\Backends\Plex\Action\SearchQuery;
use App\Backends\Plex\PlexGuid;
use App\Libs\Database\DBLayer;
use App\Libs\Database\PDO\PDOAdapter;
use PDO;

class SearchQueryTest extends PlexTestCase
{
    public function test_search_query_success(): void
    {
        $item = ag($this->fixture('library_movie_get_200'), 'response.body.MediaContainer.Metadata.0');
        $payload = [
            'MediaContainer' => [
                'Hub' => [
                    [
                        'type' => 'movie',
                        'Metadata' => [$item],
                    ],
                    [
                        'type' => 'music',
                        'Metadata' => [
                            ['type' => 'track', 'title' => 'Skip'],
                        ],
                    ],
                ],
            ],
        ];

        $response = $this->makeResponse($payload);
        $http = $this->makeHttpClient($response);
        $context = $this->makeContext();

        $db = new PDOAdapter($this->logger, new DBLayer(new PDO('sqlite::memory:')));
        $db->migrations('up');

        $action = new SearchQuery($http, $this->logger, $db, new PlexGuid($this->logger));
        $result = $action($context, 'Ferengi');

        $this->assertTrue($result->isSuccessful());
        $this->assertCount(1, $result->response);
        $this->assertSame('Ferengi: Rules of Acquisition', $result->response[0]['title']);
    }

    public function test_search_query_error_status(): void
    {
        $response = $this->makeResponse(['error' => 'nope'], 500);
        $http = $this->makeHttpClient($response);
        $context = $this->makeContext();

        $db = new PDOAdapter($this->logger, new DBLayer(new PDO('sqlite::memory:')));
        $db->migrations('up');

        $action = new SearchQuery($http, $this->logger, $db, new PlexGuid($this->logger));
        $result = $action($context, 'Ferengi');

        $this->assertFalse($result->isSuccessful());
        $this->assertNotNull($result->error);
    }
}
