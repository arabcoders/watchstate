<?php

declare(strict_types=1);

namespace App\Backends\Emby;

use App\Backends\Common\Context;
use App\Backends\Emby\Action\GetMetaData;
use App\Libs\Container;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;
use RuntimeException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class EmbyClient
{
    private Context|null $context = null;

    public function __construct(
        protected HttpClientInterface $http,
        protected CacheInterface $cache,
        protected LoggerInterface $logger,
    ) {
    }

    public function withContext(Context $context): self
    {
        $cloned = clone $this;
        $cloned->context = $context;

        return $cloned;
    }

    public function getMetadata(string|int $id, array $opts = []): array
    {
        $response = Container::get(GetMetaData::class)(context: $this->context, id: $id, opts: $opts);

        if (!$response->isSuccessful()) {
            throw new RuntimeException(message: $response->error->format(), previous: $response->error->previous);
        }

        return $response->response;
    }
}
