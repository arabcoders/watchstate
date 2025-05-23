<?php

declare(strict_types=1);

namespace App\Backends\Emby;

use App\Backends\Common\Context;
use App\Backends\Emby\Action\GetMetaData;
use App\Backends\Jellyfin\JellyfinActionTrait;
use App\Libs\Container;
use App\Libs\Exceptions\Backends\RuntimeException;

trait EmbyActionTrait
{
    use JellyfinActionTrait;

    /**
     * Get item details.
     *
     * @param Context $context
     * @param string|int $id
     * @param array $opts
     *
     * @return array
     * @throws RuntimeException When API call was not successful.
     */
    protected function getItemDetails(Context $context, string|int $id, array $opts = []): array
    {
        $response = Container::get(GetMetaData::class)(context: $context, id: $id, opts: $opts);

        if ($response->isSuccessful()) {
            return $response->response;
        }

        throw new RuntimeException(message: $response->error->format(), previous: $response->error->previous);
    }

}
