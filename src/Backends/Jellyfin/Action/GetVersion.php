<?php

declare(strict_types=1);

namespace App\Backends\Jellyfin\Action;

use App\Backends\Common\CommonTrait;
use App\Backends\Common\Context;
use App\Backends\Common\Response;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Class GetVersion
 *
 * Represents a class that retrieves the version of jellyfin server.
 */
class GetVersion
{
    use CommonTrait;

    /**
     * @var string Action name.
     */
    protected string $action = 'jellyfin.getVersion';

    /**
     * Class Constructor.
     *
     * @param HttpClientInterface $http The HTTP client instance.
     * @param LoggerInterface $logger The logger instance.
     * @param CacheInterface $cache The cache instance.
     */
    public function __construct(
        protected HttpClientInterface $http,
        protected LoggerInterface $logger,
        protected CacheInterface $cache
    ) {
    }

    /**
     * Get Jellyfin server version.
     *
     * @param Context $context The context instance.
     * @param array $opts The options array.
     *
     * @return Response The response.
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

                return new Response(status: true, response: ag($info->response, 'version'));
            },
            action: $this->action,
        );
    }
}
