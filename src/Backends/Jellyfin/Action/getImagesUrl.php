<?php

declare(strict_types=1);

namespace App\Backends\Jellyfin\Action;

use App\Backends\Common\CommonTrait;
use App\Backends\Common\Context;
use App\Backends\Common\Response;

class getImagesUrl
{
    use CommonTrait;

    protected string $action = 'jellyfin.getImagesUrl';

    /**
     * Get Backend images url.
     *
     * @param Context $context backend context.
     * @param string|int $id item id.
     * @param array $opts optional options.
     *
     * @return Response
     */
    public function __invoke(Context $context, string|int $id, array $opts = []): Response
    {
        return $this->tryResponse(
            context: $context,
            fn: fn() => new Response(
                status: true,
                response: [
                    'poster' => $context->backendUrl->withPath("/Items/{$id}/Images/Primary/"),
                    'background' => $context->backendUrl->withPath("/Items/{$id}/Images/Backdrop/"),
                ]
            ),
            action: $this->action
        );
    }
}
