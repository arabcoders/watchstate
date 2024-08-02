<?php

declare(strict_types=1);

namespace App\API\System;

use App\Libs\Attributes\Route\Delete;
use App\Libs\Attributes\Route\Get;
use App\Libs\Container;
use App\Libs\Database\DatabaseInterface as iDB;
use App\Libs\DataUtil;
use App\Libs\Entity\StateInterface as iState;
use App\Libs\Enums\Http\Status;
use App\Libs\Traits\APITraits;
use DateInterval;
use Psr\Http\Message\ResponseInterface as iResponse;
use Psr\Http\Message\ServerRequestInterface as iRequest;
use Psr\SimpleCache\CacheInterface as iCache;
use Psr\SimpleCache\InvalidArgumentException;

final class Events
{
    use APITraits;

    public const string URL = '%{api.prefix}/system/events';

    private const array TYPES = ['queue', 'progress', 'requests'];

    public function __construct(private iCache $cache, private iDB $db)
    {
    }

    /**
     * @throws InvalidArgumentException
     */
    #[Get(self::URL . '[/]', name: 'system.events')]
    public function __invoke(iRequest $request): iResponse
    {
        $response = [
            'queue' => [],
            'progress' => [],
            'requests' => [],
        ];

        foreach ($this->cache->get('queue', []) as $key => $item) {
            if (null === ($entity = $this->db->get(Container::get(iState::class)::fromArray($item)))) {
                continue;
            }
            $response['queue'][] = ['key' => $key, 'item' => $this->formatEntity($entity)];
        }

        foreach ($this->cache->get('progress', []) as $key => $item) {
            if (null !== ($entity = $this->db->get($item))) {
                $item->id = $entity->id;
            }

            $response['progress'][] = ['key' => $key, 'item' => $this->formatEntity($item)];
        }

        foreach ($this->cache->get('requests', []) as $key => $request) {
            if (null === ($item = ag($request, 'entity')) || false === ($item instanceof iState)) {
                continue;
            }

            if (null !== ($entity = $this->db->get($item))) {
                $item->id = $entity->id;
            }

            $response['requests'][] = ['key' => $key, 'item' => $this->formatEntity($item)];
        }

        return api_response(Status::HTTP_OK, $response);
    }

    /**
     * @throws InvalidArgumentException
     */
    #[Delete(self::URL . '/{id}[/]', name: 'system.events.delete')]
    public function deleteEvent(iRequest $request, array $args = []): iResponse
    {
        $params = DataUtil::fromRequest($request, true);

        if (null === ($id = $params->get('id', ag($args, 'id')))) {
            return api_error('Invalid id.', Status::HTTP_BAD_REQUEST);
        }

        $type = $params->get('type', 'queue');

        if (false === in_array($type, self::TYPES, true)) {
            return api_error(r("Invalid type '{type}'. Only '{types}' are supported.", [
                'type' => $type,
                'types' => implode(", ", self::TYPES),
            ]), Status::HTTP_BAD_REQUEST);
        }

        $items = $this->cache->get($type, []);

        if (empty($items)) {
            return api_error(r('{type} is empty.', ['type' => $type]), Status::HTTP_NOT_FOUND);
        }

        if (false === array_key_exists($id, $items)) {
            return api_error(r("Record id '{id}' doesn't exists. for '{type}' list.", [
                'id' => $id,
                'type' => $type,
            ]), Status::HTTP_NOT_FOUND);
        }

        if ('queue' === $type) {
            $item = Container::get(iState::class)::fromArray(['id' => $id]);
            queuePush($item, remove: true);
        } else {
            unset($items[$id]);
            $this->cache->set($type, $items, new DateInterval('P3D'));
        }

        return api_response(Status::HTTP_OK, ['id' => $id, 'type' => $type, 'status' => 'deleted']);
    }
}
