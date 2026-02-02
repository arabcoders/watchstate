<?php

declare(strict_types=1);

namespace App\Backends\Plex\Action;

use App\Backends\Common\CommonTrait;
use App\Backends\Common\Context;
use App\Backends\Common\Response;
use App\Backends\Plex\PlexActionTrait;
use App\Libs\Options;
use DateInterval;

class GetImagesUrl
{
    use CommonTrait;
    use PlexActionTrait;

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
        // -- Plex love's to play hard to get, so special care needed and API request is needed to fetch
        // -- the images url.
        $response = $this->getItemInfo($context, $id, opts: [Options::CACHE_TTL => new DateInterval('PT60M')]);
        if (false === $response->isSuccessful()) {
            return $response;
        }
        $data = ag($response->response, 'MediaContainer.Metadata.0', []);

        $poster = ag($data, 'thumb', null);
        $background = ag($data, 'art', null);

        return $this->tryResponse(
            context: $context,
            fn: static fn() => new Response(
                status: true,
                response: [
                    'poster' => $poster ? $context->backendUrl->withPath($poster) : null,
                    'background' => $background ? $context->backendUrl->withPath($background) : null,
                ],
            ),
            action: $this->action,
        );
    }
}
