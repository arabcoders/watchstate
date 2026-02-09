<?php

declare(strict_types=1);

namespace App\Backends\Plex\Action;

use App\Backends\Common\CommonTrait;
use App\Backends\Common\Context;
use App\Backends\Common\Response;
use App\Backends\Plex\PlexActionTrait;
use App\Backends\Plex\PlexGuid;
use App\Libs\Entity\StateEntity;

final class ToEntity
{
    use CommonTrait;
    use PlexActionTrait;

    private string $action = 'plex.toEntity';

    public function __construct(
        private PlexGuid $guid,
    ) {}

    /**
     * Create an entity from the given item.
     *
     * @param Context $context
     * @param array $item
     * @param array $opts optional options.
     *
     * @return Response<StateEntity>
     */
    public function __invoke(Context $context, array $item, array $opts = []): Response
    {
        return $this->tryResponse(
            context: $context,
            fn: fn() => $this->createEntity($context, $this->guid->withContext($context), $item, $opts),
            action: $this->action,
        );
    }
}
