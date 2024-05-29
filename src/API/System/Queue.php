<?php

declare(strict_types=1);

namespace App\API\System;

use App\Libs\Attributes\Route\Delete;
use App\Libs\Attributes\Route\Get;
use App\Libs\Container;
use App\Libs\Database\DatabaseInterface as iDB;
use App\Libs\Entity\StateInterface as iState;
use App\Libs\HTTP_STATUS;
use Psr\Http\Message\ResponseInterface as iResponse;
use Psr\Http\Message\ServerRequestInterface as iRequest;
use Psr\SimpleCache\CacheInterface as iCache;
use Psr\SimpleCache\InvalidArgumentException;

final class Queue
{
    public const string URL = '%{api.prefix}/system/queue';

    public function __construct(private iCache $cache, private iDB $db)
    {
    }

    /**
     * @throws InvalidArgumentException
     */
    #[Get(self::URL . '[/]', name: 'system.queue')]
    public function queueList(iRequest $request): iResponse
    {
        $response = [];

        $queue = $this->cache->get('queue', []);

        $entities = $items = [];

        foreach ($queue as $item) {
            $items[] = Container::get(iState::class)::fromArray($item);
        }

        if (!empty($items)) {
            foreach ($this->db->find(...$items) as $item) {
                $entities[$item->id] = $item;
            }
        }

        $items = null;

        foreach ($entities as $entity) {
            $response[] = [
                iState::COLUMN_ID => $entity->id,
                iState::COLUMN_TITLE => $entity->getName(),
                iState::COLUMN_WATCHED => $entity->isWatched(),
                iState::COLUMN_VIA => $entity->via ?? '??',
                iState::COLUMN_UPDATED => makeDate($entity->updated),
                iState::COLUMN_EXTRA_EVENT => ag($entity->getExtra($entity->via), iState::COLUMN_EXTRA_EVENT),
                iState::COLUMN_META_DATA_PROGRESS => $entity->hasPlayProgress() ? $entity->getPlayProgress() : null
            ];
        }

        return api_response(HTTP_STATUS::HTTP_OK, $response);
    }

    /**
     * @throws InvalidArgumentException
     */
    #[Delete(self::URL . '/{id}[/]', name: 'system.queue.delete')]
    public function deleteQueue(iRequest $request, array $args = []): iResponse
    {
        if (null === ($id = ag($args, 'id'))) {
            return api_error('Invalid id.', HTTP_STATUS::HTTP_BAD_REQUEST);
        }

        $queue = $this->cache->get('queue', []);

        if (empty($queue)) {
            return api_error('Queue is empty.', HTTP_STATUS::HTTP_NOT_FOUND);
        }

        if (false === array_key_exists($id, $queue)) {
            return api_error(r("Record id '{id}' doesn't exists.", ['id' => $id]), HTTP_STATUS::HTTP_NOT_FOUND);
        }

        $item = Container::get(iState::class)::fromArray(['id' => $id]);

        queuePush($item, remove: true);

        return api_response(HTTP_STATUS::HTTP_OK, ['item' => $item->getAll()]);
    }
}
