<?php

declare(strict_types=1);

namespace App\Backends\Jellyfin\Action;

use App\Backends\Common\CommonTrait;
use App\Backends\Common\Context;
use App\Backends\Common\Response;
use App\Backends\Jellyfin\JellyfinGuid;
use App\Backends\Plex\PlexActionTrait;
use App\Libs\Entity\StateInterface;

class ToEntity
{
    use CommonTrait;
    use PlexActionTrait;

    private string $action = 'jellyfin.toEntity';

    public function __construct(private JellyfinGuid $guid)
    {
    }

    /**
     * Create an entity from the given item.
     *
     * @param Context $context
     * @param array $item
     * @param array $opts optional options.
     *
     * @return Response<StateInterface>
     */
    public function __invoke(Context $context, array $item, array $opts = []): Response
    {
        return $this->tryResponse(
            context: $context,
            fn: fn() => $this->createEntity($context, $this->guid->withContext($context), $item, $opts),
            action: $this->action
        );
    }
}
