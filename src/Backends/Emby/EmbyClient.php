<?php

declare(strict_types=1);

namespace App\Backends\Emby;

use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class EmbyClient
{
    public function __construct(
        protected HttpClientInterface $http,
        protected CacheInterface $cache,
        protected LoggerInterface $logger,
    ) {
    }
}
