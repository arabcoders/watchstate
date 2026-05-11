<?php

declare(strict_types=1);

namespace App\API\System;

use App\API\Logs\Index as LogsIndex;
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
use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface as iResponse;
use Psr\Http\Message\ServerRequestInterface as iRequest;

final readonly class Events
{
    public const string URL = '%{api.prefix}/system/events';

    public const int PERPAGE = 10;

    public function __construct(
        private EventsRepository $repo,
    ) {}

    #[Get(pattern: self::URL . '[/]')]
    public function list(iRequest $request): iResponse
    {
        $params = DataUtil::fromRequest($request, true);
        [$page, $perpage, $start] = get_pagination($request, 1, self::PERPAGE);

        try {
            $arrParams = $this->buildListCriteria($params);
        } catch (InvalidArgumentException $e) {
            return api_error($e->getMessage(), Status::BAD_REQUEST);
        }

        $sortField = trim((string) $params->get('sort', EntityTable::COLUMN_CREATED_AT));
        $sortField = in_array(
            $sortField,
            [
                EntityTable::COLUMN_CREATED_AT,
                EntityTable::COLUMN_UPDATED_AT,
                EntityTable::COLUMN_EVENT,
                EntityTable::COLUMN_STATUS,
                EntityTable::COLUMN_REFERENCE,
                EntityTable::COLUMN_ATTEMPTS,
            ],
            true,
        )
            ? $sortField
            : EntityTable::COLUMN_CREATED_AT;
        $direction = 'asc' === strtolower(trim((string) $params->get('direction', 'desc'))) ? 'asc' : 'desc';

        $this->repo
            ->setPerpage($perpage)
            ->setStart($start)
            ->setSort($sortField);

        if ('asc' === $direction) {
            $this->repo->setAscendingOrder();
        } else {
            $this->repo->setDescendingOrder();
        }

        $entities = $this->repo->findAll($arrParams, [
            EntityTable::COLUMN_ID,
            EntityTable::COLUMN_EVENT,
            EntityTable::COLUMN_REFERENCE,
            EntityTable::COLUMN_STATUS,
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
                'previous' => !empty($entities) && $page > 1 ? $page - 1 : null,
            ],
            'filter' => [
                'filter' => trim((string) $params->get('filter', '')),
                'status' => trim((string) $params->get('status', '')),
                'event' => trim((string) $params->get('event', '')),
                'reference' => trim((string) $params->get('reference', '')),
                'before' => trim((string) $params->get('before', '')),
                'after' => trim((string) $params->get('after', '')),
                'sort' => $sortField,
                'direction' => $direction,
                'all' => true === (bool) $params->get('all', false),
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

    #[Get(pattern: self::URL . '/stats[/]')]
    public function stats(iRequest $request): iResponse
    {
        $params = DataUtil::fromRequest($request, true);
        $data = [];

        foreach (EventStatus::cases() as $status) {
            $data[strtolower($status->name)] = 0;
        }

        $only = array_map('trim', explode(',', $params->get('only', implode(',', array_keys($data)))));
        foreach ($only as $type) {
            if (null === ($status = EventStatus::fromName(strtoupper($type)))) {
                return api_error("Invalid status '{$type}' was given.", Status::BAD_REQUEST);
            }

            $data[strtolower($status->name)] = $this->repo->countByStatus($status);
        }

        return api_response(Status::OK, $data, headers: [
            'X-No-AccessLog' => '1',
        ]);
    }

    #[Delete(pattern: self::URL . '[/]')]
    public function removeAll(iRequest $request): iResponse
    {
        $params = DataUtil::fromRequest($request, true);

        try {
            $criteria = $this->buildDeleteCriteria($params);
        } catch (InvalidArgumentException $e) {
            return api_error($e->getMessage(), Status::BAD_REQUEST);
        }

        $limit = null;
        if (null !== ($limitValue = $params->get('limit')) && '' !== trim((string) $limitValue)) {
            if (1 !== preg_match('/^\d+$/', trim((string) $limitValue)) || (int) $limitValue < 1) {
                return api_error('limit parameter must be a positive integer.', Status::BAD_REQUEST);
            }

            $limit = (int) $limitValue;
        }

        $items = (clone $this->repo)
            ->setPerpage(null === $limit ? PHP_INT_MAX : $limit)
            ->setStart(0)
            ->setSort(EntityTable::COLUMN_CREATED_AT)
            ->setAscendingOrder()
            ->findAll($criteria);

        $deleted = 0;

        foreach ($items as $event) {
            if (!$this->repo->remove($event)) {
                continue;
            }

            $deleted++;
        }

        return api_response(Status::OK, [
            'deleted' => $deleted,
            'matched' => count($items),
            'include_pending' => true === (bool) $params->get('include_pending', false),
            'limit' => $limit,
        ]);
    }

    #[Post(pattern: self::URL . '[/]')]
    public function create(iRequest $request): iResponse
    {
        $params = DataUtil::fromRequest($request);

        if (null === ($event = $params->get(EntityTable::COLUMN_EVENT))) {
            return api_error(
                'No event name was given.',
                Status::BAD_REQUEST,
                [
                    ...$params->getAll(),
                ],
            );
        }

        $opts = [
            EventsRepository::class => $this->repo,
        ];

        if (null !== ($delay = $params->get(Options::DELAY_BY))) {
            $opts[Options::DELAY_BY] = (int) $delay;
        }

        $data = (array) $params->get(EntityTable::COLUMN_EVENT_DATA, []);
        $item = queue_event($event, $data, $opts);

        return api_message(
            r("Event '{event}' was queued.", [
                'event' => $item->event,
            ]),
            Status::ACCEPTED,
            $this->formatEntity($item),
        );
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
        $status = null;

        if (null !== ($status = $params->get(EntityTable::COLUMN_STATUS))) {
            if (false === is_int($status) && false === ctype_digit($status)) {
                return api_error('status parameter must be a number.', Status::BAD_REQUEST);
            }

            if (null === ($status = EventStatus::tryFrom((int) $status))) {
                return api_error('Invalid status parameter was given.', Status::BAD_REQUEST);
            }
        }

        if (null !== $status) {
            if (EventStatus::CANCELLED === $status && EventStatus::PENDING !== $entity->status) {
                return api_error('Only events in pending state can be cancelled.', Status::BAD_REQUEST);
            }
            $entity->status = $status;
        }

        if (null !== ($event = $params->get(EntityTable::COLUMN_EVENT))) {
            $entity->event = $event;
        }

        if (null !== ($eventData = $params->get(EntityTable::COLUMN_EVENT_DATA))) {
            $entity->event_data = $eventData;
        }

        if (true === (bool) $params->get('reset_logs', false)) {
            $entity->logs = [];
        }

        $changed = !empty($entity->diff());

        if ($changed) {
            $entity->updated_at = (string) make_date();
            $entity->logs[] = 'Event was manually updated';
            $this->repo->save($entity);
        }

        return api_message($changed ? 'Updated' : 'No Changes detected', Status::OK, $this->formatEntity($entity));
    }

    private function formatEntity(EntityItem $entity): array
    {
        $data = $entity->getAll();
        $data['status_name'] = $entity->getStatusText();
        $data['display_id'] = substr(str_replace('-', '', (string) $entity->id), 0, 12);

        if (is_array($entity->logs) && count($entity->logs) > 0) {
            $data['logs'] = array_map(LogsIndex::formatLog(...), $entity->logs);
        }

        if ($delay = ag($entity->options, Options::DELAY_BY)) {
            $data['delay_by'] = $delay;
        }

        return $data;
    }

    /**
     * @return array<string,mixed>
     */
    private function buildListCriteria(DataUtil $params): array
    {
        $criteria = [];
        $status = trim((string) $params->get('status', ''));
        $event = trim((string) $params->get('event', ''));
        $reference = trim((string) $params->get('reference', ''));
        $filter = trim((string) $params->get('filter', ''));
        $before = trim((string) $params->get('before', ''));
        $after = trim((string) $params->get('after', ''));
        $all = true === (bool) $params->get('all', false);

        if ('' !== $status) {
            $criteria[EntityTable::COLUMN_STATUS] = $this->normalizeStatus($status)->value;
        } elseif (false === $all) {
            $criteria[EntityTable::COLUMN_STATUS] = [
                DBLayer::IS_IN,
                [
                    EventStatus::PENDING->value,
                    EventStatus::FAILED->value,
                    EventStatus::CANCELLED->value,
                ],
            ];
        }

        if ('' !== $event) {
            $criteria[EntityTable::COLUMN_EVENT] = [DBLayer::IS_LIKE, $event];
        }

        if ('' !== $reference) {
            $criteria[EntityTable::COLUMN_REFERENCE] = [DBLayer::IS_LIKE, $reference];
        }

        if ('' !== $filter) {
            $criteria[EntityTable::COLUMN_EVENT] = [DBLayer::IS_LIKE, $filter];
        }

        $range = $this->makeDateRange($before, $after);
        if (null !== $range) {
            $criteria[EntityTable::COLUMN_CREATED_AT] = $range;
        }

        return $criteria;
    }

    /**
     * @return array<string,mixed>
     */
    private function buildDeleteCriteria(DataUtil $params): array
    {
        $criteria = $this->buildListCriteria($params);
        $allowed = [
            EventStatus::SUCCESS->value,
            EventStatus::FAILED->value,
            EventStatus::CANCELLED->value,
        ];

        if (true === (bool) $params->get('include_pending', false)) {
            $allowed[] = EventStatus::PENDING->value;
        }

        if (isset($criteria[EntityTable::COLUMN_STATUS]) && !is_array($criteria[EntityTable::COLUMN_STATUS])) {
            $criteria[EntityTable::COLUMN_STATUS] = in_array((int) $criteria[EntityTable::COLUMN_STATUS], $allowed, true)
                ? (int) $criteria[EntityTable::COLUMN_STATUS]
                : [DBLayer::IS_IN, [-1]];

            return $criteria;
        }

        $criteria[EntityTable::COLUMN_STATUS] = [DBLayer::IS_IN, $allowed];

        return $criteria;
    }

    private function normalizeStatus(string $value): EventStatus
    {
        $value = trim($value);

        if (ctype_digit($value) && null !== ($status = EventStatus::tryFrom((int) $value))) {
            return $status;
        }

        if (null !== ($status = EventStatus::fromName($value))) {
            return $status;
        }

        throw new InvalidArgumentException(r("Invalid status '{status}' was given.", ['status' => $value]));
    }

    /**
     * @return array<int,mixed>|null
     */
    private function makeDateRange(string $before, string $after): ?array
    {
        if ('' !== $before) {
            $before = $this->normalizeDate($before);
        }

        if ('' !== $after) {
            $after = $this->normalizeDate($after);
        }

        if ('' !== $before && '' !== $after) {
            return [DBLayer::IS_BETWEEN, [$after, $before]];
        }

        if ('' !== $before) {
            return [DBLayer::IS_LOWER_THAN, $before];
        }

        if ('' !== $after) {
            return [DBLayer::IS_HIGHER_THAN_OR_EQUAL, $after];
        }

        return null;
    }

    private function normalizeDate(string $value): string
    {
        $timestamp = strtotime($value);

        if (false === $timestamp) {
            throw new InvalidArgumentException(r(
                "Unable to parse time expression '{value}'. Use formats that PHP strtotime() accepts, such as '1min ago', '15 minutes ago', '2 hours ago', 'now', 'yesterday', or '2026-05-12 10:00'.",
                ['value' => $value],
            ));
        }

        return make_date($timestamp)->format('Y-m-d H:i:s');
    }
}
