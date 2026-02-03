<?php

declare(strict_types=1);

namespace Tests\Backends\MediaBrowser;

use App\Backends\Emby\Action\GetWebUrl as EmbyGetWebUrl;
use App\Backends\Jellyfin\Action\GetWebUrl as JellyfinGetWebUrl;

class GetWebUrlInvalidTypeTest extends MediaBrowserTestCase
{
    public function test_get_web_url_rejects_invalid_type(): void
    {
        foreach ($this->provideBackends() as [$clientName, $actionClass]) {
            $context = $this->makeContext($clientName);
            $action = new $actionClass();
            $result = $action($context, 'music', 'item-1');

            $this->assertFalse($result->isSuccessful());
            $this->assertNotNull($result->error);
        }
    }

    private function provideBackends(): array
    {
        return [
            ['Jellyfin', JellyfinGetWebUrl::class],
            ['Emby', EmbyGetWebUrl::class],
        ];
    }
}
