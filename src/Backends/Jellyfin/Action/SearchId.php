<?php

declare(strict_types=1);

namespace App\Backends\Jellyfin\Action;

use App\Backends\Common\CommonTrait;
use App\Backends\Common\Context;
use App\Backends\Common\Response;
use App\Backends\Jellyfin\JellyfinActionTrait;
use App\Backends\Jellyfin\JellyfinGuid;
use App\Libs\Database\DatabaseInterface as iDB;
use App\Libs\Entity\StateInterface as iState;
use App\Libs\Exceptions\Backends\InvalidArgumentException;
use App\Libs\Exceptions\Backends\RuntimeException;
use App\Libs\Options;
use Psr\Log\LoggerInterface as iLogger;
use Symfony\Contracts\HttpClient\HttpClientInterface as iHttp;

/**
 * Class SearchId
 *
 * SearchId class is responsible for searching and retrieving details about an item with a given ID from the Jellyfin API.
 */
class SearchId
{
    use CommonTrait;
    use JellyfinActionTrait;

    /**
     * @var string Action name.
     */
    protected string $action = 'jellyfin.searchId';

    public function __construct(
        protected iHttp $http,
        protected iLogger $logger,
        private JellyfinGuid $jellyfinGuid,
        private iDB $db
    ) {
    }


    /**
     * Wrap the operation in a try response block.
     *
     * @param Context $context Backend context.
     * @param string|int $id The ID of the item to search for.
     * @param array $opts (optional) options.
     *
     * @return Response The response.
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
     * Fetch details about ID from jellyfin API.
     *
     * @param Context $context Backend context.
     * @param string|int $id The ID of the item to search for.
     * @param array $opts (Optional) options.
     *
     * @return Response The response.
     * @throws RuntimeException When API call was not successful.
     * @throws InvalidArgumentException When the ID is not valid.
     */
    private function search(Context $context, string|int $id, array $opts = []): Response
    {
        $item = $this->getItemDetails($context, $id, $opts);

        $entity = $this->createEntity($context, $this->jellyfinGuid->withContext($context), $item);

        if (null !== ($localEntity = $this->db->get($entity))) {
            $entity->id = $localEntity->id;
        }

        $builder = $entity->getAll();
        $builder['url'] = (string)$this->getWebUrl(
            $context,
            $entity->type,
            (int)ag(
                $entity->getMetadata($entity->via),
                iState::COLUMN_ID
            )
        );

        $builder['content_title'] = ag(
            $entity->getMetadata($entity->via),
            iState::COLUMN_EXTRA . '.' . iState::COLUMN_TITLE,
            $entity->title
        );
        $builder['content_path'] = ag($entity->getMetadata($entity->via), iState::COLUMN_META_PATH);

        if (true === (bool)ag($opts, Options::RAW_RESPONSE)) {
            $builder['raw'] = $item;
        }

        return new Response(status: true, response: $builder);
    }
}
