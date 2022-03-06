<?php

declare(strict_types=1);

use App\Libs\Config;
use App\Libs\Entity\StateEntity;
use App\Libs\Entity\StateInterface;
use Monolog\Logger;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Cache\Psr16Cache;
use Symfony\Component\HttpClient\CurlHttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

return (function (): array {
    return [
        LoggerInterface::class => [
            'class' => fn() => new Logger('logger')
        ],

        HttpClientInterface::class => [
            'class' => function (): HttpClientInterface {
                return new CurlHttpClient(
                    defaultOptions:     Config::get('http.default.options', []),
                    maxHostConnections: Config::get('http.default.maxHostConnections', 25),
                    maxPendingPushes:   Config::get('http.default.maxPendingPushes', 50),
                );
            }
        ],

        StateInterface::class => [
            'class' => fn() => new StateEntity([])
        ],

        CacheInterface::class => [
            'class' => fn() => new Psr16Cache(new FilesystemAdapter(directory: Config::get('cache.config.directory')))
        ],
    ];
})();
