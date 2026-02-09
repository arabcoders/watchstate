<?php

declare(strict_types=1);

namespace Tests\Backends\Plex;

use App\Backends\Plex\Action\GetWebUrl;
use App\Libs\Entity\StateInterface as iState;

class GetWebUrlTest extends PlexTestCase
{
    public function test_get_web_url_success(): void
    {
        $context = $this->makeContext();
        $action = new GetWebUrl();
        $result = $action($context, iState::TYPE_MOVIE, '1');

        $this->assertTrue($result->isSuccessful());
        $this->assertStringContainsString('!/server/plex-server-1/details?key=%2Flibrary%2Fmetadata%2F1', (string) $result->response);
    }

    public function test_get_web_url_invalid_type(): void
    {
        $context = $this->makeContext();
        $action = new GetWebUrl();
        $result = $action($context, 'music', '1');

        $this->assertFalse($result->isSuccessful());
        $this->assertNotNull($result->error);
    }
}
