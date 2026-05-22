<?php

declare(strict_types=1);

namespace Tests\Backends\Plex;

use App\Backends\Plex\PlexClient;
use App\Libs\Exceptions\Backends\RuntimeException;

class DiscoverTest extends PlexTestCase
{
    public function test_discover_xml_error(): void
    {
        $http = $this->makeHttpClient($this->makeResponse(
            '<?xml version="1.0" encoding="UTF-8"?><errors><error>Invalid authentication token.</error></errors>',
            401,
        ));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'Failed to load Plex servers list: plex.tv returned status 401. Invalid authentication token.'
        );

        PlexClient::discover($http, 'token');
    }
}
