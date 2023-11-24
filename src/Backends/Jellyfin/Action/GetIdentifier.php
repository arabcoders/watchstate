<?php

declare(strict_types=1);

namespace App\Backends\Jellyfin\Action;

use App\Backends\Common\CommonTrait;
use App\Backends\Common\Context;
use App\Backends\Common\Response;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class GetIdentifier
{
    use CommonTrait;

    private string $action = 'unique identifier';

    public function __construct(
        protected HttpClientInterface $http,
        protected LoggerInterface $logger,
        protected CacheInterface $cache
    ) {
    }

    /**
     * Get Backend unique identifier.
     *
     * @param Context $context
     * @param array $opts optional options.
     *
     * @return Response
     */
    public function __invoke(Context $context, array $opts = []): Response
    {
        return $this->tryResponse(
            context: $context,
            fn: function () use ($context, $opts) {
                $info = (new GetInfo($this->http, $this->logger, $this->cache))(context: $context, opts: $opts);

                if (false === $info->status) {
                    return $info;
                }

                return new Response(status: true, response: ag($info->response, 'identifier'));
            },
            action: $this->action,
        );
    }
}
