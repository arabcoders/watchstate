<?php

declare(strict_types=1);

use App\Libs\Config;
use Monolog\Logger;
use Psr\Log\LoggerInterface;
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
    ];
})();
