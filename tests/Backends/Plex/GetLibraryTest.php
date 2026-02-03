<?php

declare(strict_types=1);

namespace Tests\Backends\Plex;

use App\Backends\Common\Response;
use App\Backends\Plex\Action\GetLibrariesList;
use App\Backends\Plex\Action\GetLibrary;
use App\Backends\Plex\PlexGuid;
use App\Libs\Container;
use App\Libs\Entity\StateInterface;
use App\Libs\Options;

class GetLibraryTest extends PlexTestCase
{
    public function test_get_library_success_to_entity(): void
    {
        $section = [
            'key' => '1',
            'title' => 'Movies',
            'type' => 'movie',
            'agent' => 'tv.plex.agents.movie',
        ];

        Container::add(GetLibrariesList::class, fn() => new class($section) {
            public function __construct(private array $section)
            {
            }

            public function __invoke(\App\Backends\Common\Context $context, array $opts = []): Response
            {
                return new Response(status: true, response: [
                    [
                        'id' => $this->section['key'],
                        'raw' => $this->section,
                    ],
                ]);
            }
        });

        $payload = $this->fixture('library_movie_get_200');
        $http = $this->makeHttpClient($this->makeResponse($payload['response']['body']));
        $context = $this->makeContext();

        $action = new GetLibrary($http, $this->logger);
        $guid = (new PlexGuid($this->logger))->withContext($context);
        $result = $action($context, $guid, '1', [Options::TO_ENTITY => true]);

        $message = $result->error?->format() ?? '';
        $this->assertTrue($result->isSuccessful(), $message);
        $this->assertInstanceOf(StateInterface::class, $result->response[0]);
    }
}
