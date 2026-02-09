<?php

declare(strict_types=1);

namespace Tests\Backends\MediaBrowser;

use App\Backends\Emby\Action\GetWebUrl as EmbyGetWebUrl;
use App\Backends\Jellyfin\Action\GetWebUrl as JellyfinGetWebUrl;
use App\Libs\Entity\StateInterface as iState;

class GetWebUrlTest extends MediaBrowserTestCase
{
    public function test_get_web_url_success(): void
    {
        foreach ($this->provideBackends() as [$clientName, $actionClass, $fragment]) {
            $context = $this->makeContext($clientName);
            $action = new $actionClass();
            $result = $action($context, iState::TYPE_MOVIE, 'item-1');

            $this->assertTrue($result->isSuccessful());
            $this->assertStringContainsString($fragment, (string) $result->response);
        }
    }

    private function provideBackends(): array
    {
        return [
            ['Jellyfin', JellyfinGetWebUrl::class, '!/details?id=item-1&serverId=backend-1'],
            ['Emby', EmbyGetWebUrl::class, '!/item?id=item-1&serverId=backend-1'],
        ];
    }
}
