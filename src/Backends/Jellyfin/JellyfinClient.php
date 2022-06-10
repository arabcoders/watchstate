<?php

declare(strict_types=1);

namespace App\Backends\Jellyfin;

use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class JellyfinClient
{
    public function __construct(
        protected HttpClientInterface $http,
        protected CacheInterface $cache,
        protected LoggerInterface $logger,
    ) {
    }
}
