<?php

declare(strict_types=1);

namespace App\Backends\Plex\Action;

use App\Backends\Common\CommonTrait;
use App\Backends\Common\Context;
use App\Backends\Common\Response;
use App\Backends\Plex\PlexActionTrait;
use App\Backends\Plex\PlexGuid;
use App\Libs\Database\DatabaseInterface as iDB;
use App\Libs\Exceptions\Backends\InvalidArgumentException;
use App\Libs\Exceptions\Backends\RuntimeException;
use App\Libs\Options;
use Psr\Log\LoggerInterface as iLogger;
use Symfony\Contracts\HttpClient\HttpClientInterface as iHttp;

final class SearchId
{
    use CommonTrait;
    use PlexActionTrait;

    private string $action = 'plex.searchId';

    public function __construct(
        protected iHttp $http,
        protected iLogger $logger,
        private iDB $db,
        private PlexGuid $plexGuid
    ) {
    }

    /**
     * Search Backend for ID.
     *
     * @param Context $context
     * @param string|int $id
     * @param array $opts optional options.
     *
     * @return Response
     */
    public function __invoke(Context $context, string|int $id, array $opts = []): Response
    {
        return $this->tryResponse(
            context: $context,
            fn: fn() => $this->search($context, $id, $opts),
            action: $this->action
        );
    }

    /**
     * @throws InvalidArgumentException
     * @throws RuntimeException
     */
    private function search(Context $context, string|int $id, array $opts = []): Response
    {
        $item = $this->getItemInfo($context, $id, $opts + [Options::NO_THROW => true]);

        if (!$item->isSuccessful()) {
            return $item;
        }

        $entity = $this->createEntity(
            $context,
            $this->plexGuid->withContext($context),
            ag($item->response, 'MediaContainer.Metadata.0', [])
        );

        if (null !== ($localEntity = $this->db->get($entity))) {
            $entity->id = $localEntity->id;
        }

        $builder = $entity->getAll();

        if (true === (bool)ag($opts, Options::RAW_RESPONSE)) {
            $builder[Options::RAW_RESPONSE] = $item->response;
        }

        return new Response(status: true, response: $builder);
    }
}
