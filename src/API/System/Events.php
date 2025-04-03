<?php

declare(strict_types=1);

namespace App\API\System;

use App\Libs\Attributes\Route\Delete;
use App\Libs\Attributes\Route\Get;
use App\Libs\Attributes\Route\Patch;
use App\Libs\Attributes\Route\Post;
use App\Libs\Database\DBLayer;
use App\Libs\DataUtil;
use App\Libs\Enums\Http\Status;
use App\Libs\Options;
use App\Model\Events\Event as EntityItem;
use App\Model\Events\EventsRepository;
use App\Model\Events\EventsTable as EntityTable;
use App\Model\Events\EventStatus;
use Psr\Http\Message\ResponseInterface as iResponse;
use Psr\Http\Message\ServerRequestInterface as iRequest;

final readonly class Events
{
    public const string URL = '%{api.prefix}/system/events';

    public const int PERPAGE = 10;

    public function __construct(private EventsRepository $repo)
    {
    }

    #[Get(pattern: self::URL . '[/]')]
    public function list(iRequest $request): iResponse
    {
        $params = DataUtil::fromRequest($request, true);
        [$page, $perpage, $start] = getPagination($request, 1, self::PERPAGE);

        $arrParams = [];

        if (null !== ($filter = $params->get('filter'))) {
            $arrParams['event'] = [DBLayer::IS_LIKE, $filter];
        }

        $this->repo->setPerpage($perpage)->setStart($start)->setDescendingOrder();

        $entities = $this->repo->findAll($arrParams, [
            EntityTable::COLUMN_ID,
            EntityTable::COLUMN_EVENT,
            EntityTable::COLUMN_STATUS,
            EntityTable::COLUMN_EVENT_DATA,
            EntityTable::COLUMN_OPTIONS,
            EntityTable::COLUMN_ATTEMPTS,
            EntityTable::COLUMN_CREATED_AT,
            EntityTable::COLUMN_UPDATED_AT,
        ]);

        $total = $this->repo->getTotal();

        $arr = [
            'paging' => [
                'page' => $page,
                'total' => $total,
                'perpage' => $perpage,
                'next' => $page < @ceil($total / $perpage) ? $page + 1 : null,
                'previous' => !empty($entities) && $page > 1 ? $page - 1 : null
            ],
            'items' => [],
            'statuses' => [],
        ];

        foreach (EventStatus::cases() as $status) {
            $arr['statuses'][] = [
                'id' => $status->value,
                'name' => ucfirst(strtolower($status->name)),
            ];
        }

        foreach ($entities as $entity) {
            $arr['items'][] = $this->formatEntity($entity);
        }

        return api_response(Status::OK, $arr);
    }

    #[Delete(pattern: self::URL . '[/]')]
    public function removeAll(): iResponse
    {
        $this->repo->remove([
            EntityTable::COLUMN_STATUS => [DBLayer::IS_NOT_EQUAL, EventStatus::PENDING->value],
        ]);

        return api_response(Status::OK);
    }

    #[Post(pattern: self::URL . '[/]')]
    public function create(iRequest $request): iResponse
    {
        $params = DataUtil::fromRequest($request);

        if (null === ($event = $params->get(EntityTable::COLUMN_EVENT))) {
            return api_error('No event name was given.', Status::BAD_REQUEST, [
                ...$params->getAll()
            ]);
        }

        $opts = [
            EventsRepository::class => $this->repo
        ];

        if (null !== ($delay = $params->get(Options::DELAY_BY))) {
            $opts[Options::DELAY_BY] = (int)$delay;
        }

        $data = (array)$params->get(EntityTable::COLUMN_EVENT_DATA, []);
        $item = queueEvent($event, $data, $opts);

        return api_message(r("Event '{event}' was queued.", [
            'event' => $item->event,
        ]), Status::ACCEPTED, $this->formatEntity($item));
    }

    #[Get(pattern: self::URL . '/{id:uuid}[/]')]
    public function read(string $id): iResponse
    {
        if (null === ($entity = $this->repo->findById($id))) {
            return api_error('Item does not exists', Status::NOT_FOUND);
        }

        return api_response(Status::OK, $this->formatEntity($entity));
    }

    #[Delete(pattern: self::URL . '/{id:uuid}[/]')]
    public function delete(string $id): iResponse
    {
        if (null === ($entity = $this->repo->findById($id))) {
            return api_error('Item does not exists', Status::NOT_FOUND);
        }

        if (EventStatus::RUNNING === $entity->status) {
            return api_error('Cannot delete event that is in running state.', Status::BAD_REQUEST);
        }

        $this->repo->remove($entity);

        return api_response(Status::OK, $this->formatEntity($entity));
    }

    #[Patch(pattern: self::URL . '/{id:uuid}[/]')]
    public function update(iRequest $request, string $id): iResponse
    {
        if (null === ($entity = $this->repo->findById($id))) {
            return api_error('Item does not exists', Status::NOT_FOUND);
        }

        if (EventStatus::RUNNING === $entity->status) {
            return api_error('Cannot update event in running state.', Status::BAD_REQUEST);
        }

        $params = DataUtil::fromRequest($request);

        // -- Update State.
        if (null !== ($status = $params->get(EntityTable::COLUMN_STATUS))) {
            if (false === is_int($status) && false === ctype_digit($status)) {
                return api_error('status parameter must be a number.', Status::BAD_REQUEST);
            }

            if (null == ($status = EventStatus::tryFrom((int)$status))) {
                return api_error('Invalid status parameter was given.', Status::BAD_REQUEST);
            }

            $entity->status = $status;
        }

        if (null !== ($event = $params->get(EntityTable::COLUMN_EVENT))) {
            $entity->event = $event;
        }

        if (null !== ($event_data = $params->get(EntityTable::COLUMN_EVENT_DATA))) {
            $entity->event_data = $event_data;
        }

        if (true === (bool)$params->get('reset_logs', false)) {
            $entity->logs = [];
        }

        $changed = !empty($entity->diff());

        if ($changed) {
            $entity->updated_at = (string)makeDate();
            $entity->logs[] = 'Event was manually updated';
            $this->repo->save($entity);
        }

        return api_message($changed ? 'Updated' : 'No Changes detected', Status::OK, $this->formatEntity($entity));
    }

    private function formatEntity(EntityItem $entity): array
    {
        $data = $entity->getAll();
        $data['status_name'] = $entity->getStatusText();

        if ($delay = ag($entity->options, Options::DELAY_BY)) {
            $data['delay_by'] = $delay;
        }

        return $data;
    }
}
