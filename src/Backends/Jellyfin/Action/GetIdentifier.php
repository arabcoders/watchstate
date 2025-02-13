<?php

declare(strict_types=1);

namespace App\Backends\Jellyfin\Action;

use App\Backends\Common\CommonTrait;
use App\Backends\Common\Context;
use App\Backends\Common\Response;
use Psr\Log\LoggerInterface as iLogger;
use Symfony\Contracts\HttpClient\HttpClientInterface as iHttp;

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
     * @param iHttp $http The HTTP client instance to use.
     * @param iLogger $logger The logger instance to use.
     * @return void
     */
    public function __construct(protected readonly iHttp $http, protected readonly iLogger $logger)
    {
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
                $info = new GetInfo($this->http, $this->logger)(context: $context, opts: $opts);

                if (false === $info->status) {
                    return $info;
                }

                return new Response(status: true, response: ag($info->response, 'identifier'));
            },
            action: $this->action,
        );
    }
}
