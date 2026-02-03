<?php

declare(strict_types=1);

namespace Tests\Backends\Plex;

use App\Backends\Plex\Action\GetMetaData;
use App\Backends\Plex\Action\ParseWebhook;
use App\Backends\Plex\PlexGuid;
use App\Libs\Container;
use App\Libs\Options;
use Nyholm\Psr7\ServerRequest;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Psr16Cache;

class ParseWebhookTest extends PlexTestCase
{
    public function test_parse_webhook_success(): void
    {
        $payload = $this->fixture('library_movie_get_200');
        $item = ag($payload, 'response.body.MediaContainer.Metadata.0');

        $webhook = [
            'event' => 'media.scrobble',
            'Metadata' => array_replace_recursive($item, [
                'librarySectionID' => 1,
                'viewCount' => 1,
                'lastViewedAt' => 1691594411,
            ]),
        ];

        Container::add(GetMetaData::class, function () use ($payload) {
            $http = $this->makeHttpClient($this->makeResponse($payload['response']['body']));
            return new GetMetaData($http, $this->logger, new Psr16Cache(new ArrayAdapter()));
        });

        $context = $this->makeContext();
        $cache = new Psr16Cache(new ArrayAdapter());
        $request = (new ServerRequest('POST', 'http://plex.test'))->withParsedBody($webhook);
        $guid = (new PlexGuid($this->logger))->withContext($context);

        $action = new ParseWebhook($cache);
        $result = $action($context, $guid, $request);

        $this->assertTrue($result->isSuccessful());
        $this->assertSame('Ferengi: Rules of Acquisition', $result->response->title);
    }

    public function test_parse_webhook_ignored_library(): void
    {
        $payload = [
            'event' => 'media.scrobble',
            'Metadata' => [
                'ratingKey' => '1',
                'type' => 'movie',
                'librarySectionID' => '99',
            ],
        ];

        $context = $this->makeContext([Options::IGNORE => '99']);
        $request = (new ServerRequest('POST', 'http://plex.test'))->withParsedBody($payload);

        $action = new ParseWebhook(new Psr16Cache(new ArrayAdapter()));
        $result = $action($context, new PlexGuid($this->logger), $request);

        $this->assertFalse($result->isSuccessful());
        $this->assertSame(200, $result->extra['http_code']);
    }

    public function test_parse_webhook_rejects_invalid_event(): void
    {
        $payload = [
            'event' => 'unknown.event',
            'Metadata' => [
                'ratingKey' => '1',
                'type' => 'movie',
            ],
        ];

        $context = $this->makeContext();
        $request = (new ServerRequest('POST', 'http://plex.test'))->withParsedBody($payload);

        $action = new ParseWebhook(new Psr16Cache(new ArrayAdapter()));
        $result = $action($context, new PlexGuid($this->logger), $request);

        $this->assertFalse($result->isSuccessful());
        $this->assertSame(200, $result->extra['http_code']);
    }
}
