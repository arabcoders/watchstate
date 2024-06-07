<?php

declare(strict_types=1);

namespace App\Backends\Plex\Action;

use App\Backends\Common\CommonTrait;
use App\Backends\Common\Context;
use App\Backends\Common\Error;
use App\Backends\Common\Levels;
use App\Backends\Common\Response;
use App\Libs\Entity\StateInterface as iState;

final class GetWebUrl
{
    use CommonTrait;

    private string $action = 'plex.getWebUrl';

    private array $supportedTypes = [
        iState::TYPE_MOVIE,
        iState::COLUMN_EPISODE,
        iState::TYPE_SHOW,
    ];

    /**
     * Get Backend unique identifier.
     *
     * @param Context $context backend context.
     * @param string $type item type.
     * @param string|int $id item id.
     * @param array $opts optional options.
     *
     * @return Response
     */
    public function __invoke(Context $context, string $type, string|int $id, array $opts = []): Response
    {
        if (false === in_array($type, $this->supportedTypes, true)) {
            return new Response(
                status: false,
                error: new Error(
                    message: r('Invalid Web url type "{type}".', ['type' => $type]),
                    level: Levels::WARNING,
                )
            );
        }

        return $this->tryResponse(
            context: $context,
            fn: function () use ($context, $type, $id, $opts) {
                $key = $context->backendUrl->withPath(
                    r('/library/metadata/{item_id}', ['item_id' => $id])
                );

                $webUrl = $context->backendUrl->withPath('/web/index.html')->withFragment(
                    r('!/server/{backend_id}/details?key={key}&context=external', [
                        'backend_id' => $context->backendId,
                        'key' => urlencode($key->getPath())
                    ])
                );

                return new Response(status: true, response: $webUrl);
            },
            action: $this->action
        );
    }
}
