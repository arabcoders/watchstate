<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Backends\Common\Cache;
use App\Backends\Common\Context;
use App\Libs\Uri;
use Monolog\Logger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Psr16Cache;

trait MediaBrowserContextTestSupport
{
    private function createContext(string $clientName): Context
    {
        $cache = new Cache(new Logger('test'), new Psr16Cache(new ArrayAdapter()));
        $userContext = $this->createUserContext($clientName);

        return new Context(
            clientName: $clientName,
            backendName: $clientName,
            backendUrl: new Uri('http://mediabrowser.test'),
            cache: $cache,
            userContext: $userContext,
        );
    }
}
