<?php

declare(strict_types=1);

namespace App\Libs\Events;

use App\Libs\Options;
use App\Model\Events\Event as EventInfo;
use App\Model\Events\EventsRepository;
use App\Model\Events\EventsTable;
use App\Model\Events\EventStatus;
use DateInterval;
use PDOException;
use Psr\SimpleCache\CacheInterface as iCache;

final class EventQueue
{
    public function __construct(
        private readonly iCache $cache,
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
     * @throws \Psr\SimpleCache\InvalidArgumentException May throw this exception if saving to db fails and fallback also fail.
     */
    public function queue(string $event, array $data = [], array $opts = []): EventInfo
    {
        if (true === (bool) ag($opts, Options::CACHE_ONLY, false)) {
            return $this->cacheItem($event, $data, $opts);
        }

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
                return $this->cacheItem($event, $data, $opts);
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

    private function cacheItem(string $event, array $data, array $opts): EventInfo
    {
        $item = $this->createEntity(new EventInfo(['id' => generate_uuid()]), $event, $data, $opts);

        $events = $this->cache->get('events', []);
        $reference = ag($opts, EventsTable::COLUMN_REFERENCE);
        $isUnique = true === (bool) ag($opts, 'unique', false);
        $ttl = ag($opts, Options::CACHE_TTL, new DateInterval('PT1H'));

        unset($opts[EventsRepository::class], $opts[Options::CACHE_ONLY], $opts[Options::CACHE_TTL]);
        $opts[EventsTable::COLUMN_CREATED_AT] = $item->created_at;
        $opts['cached'] = true;

        $cachedEvent = [
            'event' => $event,
            'data' => $data,
            'opts' => $opts,
        ];

        if (true === $isUnique && null !== $reference) {
            foreach ($events as $index => $queued) {
                if ($reference !== ag($queued, 'opts.' . EventsTable::COLUMN_REFERENCE)) {
                    continue;
                }

                $events[$index] = $cachedEvent;
                $this->cache->set('events', $events, $ttl);

                return $item;
            }
        }

        $events[] = $cachedEvent;
        $this->cache->set('events', $events, $ttl);

        return $item;
    }
}
