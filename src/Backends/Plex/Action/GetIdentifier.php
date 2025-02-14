<?php

declare(strict_types=1);

namespace App\Backends\Plex\Action;

use App\Backends\Common\CommonTrait;
use App\Backends\Common\Context;
use App\Backends\Common\Response;
use Psr\Log\LoggerInterface as iLogger;
use Symfony\Contracts\HttpClient\HttpClientInterface as iHttp;

final class GetIdentifier
{
    use CommonTrait;

    protected string $action = 'plex.getIdentifier';

    public function __construct(protected readonly iHttp $http, protected readonly iLogger $logger)
    {
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
                $info = new GetInfo($this->http, $this->logger)(context: $context, opts: $opts);

                if (false === $info->status) {
                    return $info;
                }

                return new Response(status: true, response: ag($info->response, 'identifier'));
            },
            action: $this->action
        );
    }
}
