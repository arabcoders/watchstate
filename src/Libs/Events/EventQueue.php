<?php

declare(strict_types=1);

namespace App\Libs\Events;

use App\Libs\Events\Queue\EventEnvelope;
use App\Libs\Events\Queue\EventTransportInterface as iEventTransport;
use App\Libs\Options;
use App\Model\Events\Event as EventInfo;
use App\Model\Events\EventsRepository;
use App\Model\Events\EventsTable;
use App\Model\Events\EventStatus;
use DateTimeInterface;
use PDOException;

final class EventQueue
{
    public function __construct(
        private readonly iEventTransport $transport,
        private readonly EventsRepository $repo,
    ) {}

    /**
     * Queue Event.
     *
     * @param string $event Event name.
     * @param array $data Event data.
     * @param array $opts Options.
     *
     * @return EventInfo
     */
    public function queue(string $event, array $data = [], array $opts = []): EventInfo
    {
        if (true === (bool) ag($opts, Options::QUEUE_ONLY, false) || true === (bool) ag($opts, Options::CACHE_ONLY, false)) {
            return $this->transportItem($event, $data, $opts);
        }

        return $this->persist($event, $data, $opts);
    }

    /**
     * Materialize a transported event envelope into the database event journal.
     */
    public function materialize(EventEnvelope $envelope): EventInfo
    {
        return $this->persist($envelope->event, $envelope->data, $envelope->opts);
    }

    private function persist(string $event, array $data, array $opts = []): EventInfo
    {
        $repo = ag($opts, EventsRepository::class, $this->repo);
        if (!$repo instanceof EventsRepository) {
            $repo = $this->repo;
        }

        $item = $repo->getObject([]);

        try {
            if (null !== ($reference = ag($opts, EventsTable::COLUMN_REFERENCE))) {
                $criteria = [];

                if (false === ($isUnique = (bool) ag($opts, 'unique', false))) {
                    $criteria[EventsTable::COLUMN_STATUS] = EventStatus::PENDING->value;
                }

                if (null !== ($refItem = $repo->findByReference($reference, $criteria, $opts)) && true === $isUnique) {
                    $repo->remove($refItem, $opts);
                } else {
                    $item = $refItem ?? $item;
                }

                unset($refItem);
            }

            $item = $this->createEntity($item, $event, $data, $opts);

            $id = $repo->save($item, $opts);
            $item->id = $id;
        } catch (PDOException $e) {
            if (false === ag_exists($opts, 'cached') && false !== stripos($e->getMessage(), 'database is locked')) {
                return $this->transportItem($event, $data, $opts);
            }
            throw $e;
        }

        return $item;
    }

    private function createEntity(EventInfo $item, string $event, array $data, array $opts): EventInfo
    {
        $item->event = $event;
        $item->status = EventStatus::PENDING;
        $item->event_data = $data;
        $item->created_at = ag($opts, EventsTable::COLUMN_CREATED_AT, static fn() => make_date());
        $item->options = ['class' => ag($opts, 'class', DataEvent::class)];

        if (ag_exists($opts, EventsTable::COLUMN_OPTIONS) && true === is_array($opts[EventsTable::COLUMN_OPTIONS])) {
            $item->options = array_replace_recursive($opts[EventsTable::COLUMN_OPTIONS], $item->options);
        }

        if (ag_exists($opts, Options::CONTEXT_USER) && false === empty($opts[Options::CONTEXT_USER])) {
            $item->options[Options::CONTEXT_USER] = $opts[Options::CONTEXT_USER];
        }

        if (ag_exists($opts, Options::DELAY_BY) && false === empty($opts[Options::DELAY_BY])) {
            $item->options[Options::DELAY_BY] = $opts[Options::DELAY_BY];
        }

        if (true === (bool) ag($opts, Options::FAIL_FAST_ON_LOCK, false)) {
            $item->options[Options::FAIL_FAST_ON_LOCK] = true;
        }

        if (true === (bool) ag($opts, Options::REPLAY_PROGRESS, false)) {
            $item->options[Options::REPLAY_PROGRESS] = true;
        }

        if (null !== ($reference = ag($opts, EventsTable::COLUMN_REFERENCE))) {
            $item->reference = $reference;
        }

        return $item;
    }

    private function transportItem(string $event, array $data, array $opts): EventInfo
    {
        $item = $this->createEntity(new EventInfo(['id' => generate_uuid()]), $event, $data, $opts);

        unset($opts[EventsRepository::class], $opts[Options::CACHE_ONLY], $opts[Options::CACHE_TTL], $opts[Options::QUEUE_ONLY]);
        $createdAt = $item->created_at instanceof DateTimeInterface
            ? $item->created_at->format(DateTimeInterface::ATOM)
            : (string) $item->created_at;

        $opts[EventsTable::COLUMN_CREATED_AT] = $createdAt;
        $opts['cached'] = true;

        $this->transport->enqueue(EventEnvelope::create($event, $data, $opts));

        return $item;
    }
}
