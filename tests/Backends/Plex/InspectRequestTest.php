<?php

declare(strict_types=1);

namespace Tests\Backends\Plex;

use App\Backends\Plex\Action\InspectRequest;
use Nyholm\Psr7\ServerRequest;

class InspectRequestTest extends PlexTestCase
{
    public function test_inspect_request_plex_payload(): void
    {
        $payload = [
            'event' => 'media.scrobble',
            'Server' => ['uuid' => 'plex-server-1', 'title' => 'Plex', 'version' => '1.0.0'],
            'Account' => ['id' => 1, 'title' => 'Test User'],
            'Metadata' => ['ratingKey' => '1', 'type' => 'movie'],
        ];

        $request = new ServerRequest(
            'POST',
            'http://plex.test',
            [],
            null,
            '1.1',
            ['HTTP_USER_AGENT' => 'PlexMediaServer/1.0.0'],
        );
        $request = $request->withParsedBody(['payload' => json_encode($payload)]);

        $context = $this->makeContext();
        $action = new InspectRequest();
        $result = $action($context, $request);

        $this->assertTrue($result->isSuccessful());
        $this->assertSame('plex-server-1', $result->response->getAttribute('backend')['id']);
        $this->assertSame('media.scrobble', $result->response->getAttribute('webhook')['event']);
    }

    public function test_inspect_request_tautulli_payload(): void
    {
        $payload = [
            'event' => 'tautulli.watched',
            'Server' => ['uuid' => 'plex-server-1', 'title' => 'Plex', 'version' => '1.0.0'],
            'Account' => ['id' => '1', 'title' => 'Test User'],
            'Player' => ['local' => '1'],
            'Metadata' => [
                'ratingKey' => '1',
                'type' => 'movie',
                'addedAt' => '2024-01-01 00:00:00',
                'updatedAt' => '2024-01-01 00:00:00',
                'Guid' => [],
                'Guids' => ['imdb' => 'tt123'],
            ],
        ];

        $request = new ServerRequest(
            'POST',
            'http://plex.test',
            [],
            null,
            '1.1',
            ['HTTP_USER_AGENT' => 'Tautulli/2.0'],
        );
        $request = $request->withParsedBody($payload);

        $context = $this->makeContext();
        $action = new InspectRequest();
        $result = $action($context, $request);

        $this->assertTrue($result->isSuccessful());
        $parsed = $result->response->getParsedBody();
        $this->assertSame(1, $parsed['Metadata']['viewCount']);
    }

    public function test_rejects_invalid_user_agent(): void
    {
        $request = new ServerRequest(
            'POST',
            'http://plex.test',
            [],
            null,
            '1.1',
            ['HTTP_USER_AGENT' => 'OtherClient/1.0'],
        );

        $context = $this->makeContext();
        $action = new InspectRequest();
        $result = $action($context, $request);

        $this->assertFalse($result->isSuccessful());
    }
}
