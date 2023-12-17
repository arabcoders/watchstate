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
 * Class GetIdentifier
 *
 * This class is responsible for retrieving the jellyfin instance unique identifier.
 */
class GetIdentifier
{
    use CommonTrait;

    /**
     * @var string Action name
     */
    protected string $action = 'jellyfin.getIdentifier';

    /**
     * Class constructor.
     *
     * @param HttpClientInterface $http The HTTP client instance to use.
     * @param LoggerInterface $logger The logger instance to use.
     * @param CacheInterface $cache The cache instance to use.
     * @return void
     */
    public function __construct(
        protected HttpClientInterface $http,
        protected LoggerInterface $logger,
        protected CacheInterface $cache
    ) {
    }

    /**
     * Get backend unique identifier.
     *
     * @param Context $context Backend context.
     * @param array $opts (Optional) options.
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
