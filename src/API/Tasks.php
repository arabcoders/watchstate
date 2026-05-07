<?php

declare(strict_types=1);

namespace App\API;

use App\Commands\System\TasksCommand;
use App\Libs\Attributes\Route\Get;
use App\Libs\Attributes\Route\Route;
use App\Libs\Database\DBLayer;
use App\Libs\Enums\Http\Status;
use App\Model\Events\Event;
use App\Model\Events\EventsRepository;
use App\Model\Events\EventsTable;
use App\Model\Events\EventStatus;
use Cron\CronExpression;
use Psr\Http\Message\ResponseInterface as iResponse;
use Psr\Http\Message\ServerRequestInterface as iRequest;
use Throwable;

final class Tasks
{
    public const string URL = '%{api.prefix}/tasks';

    public function __construct(
        private readonly EventsRepository $eventsRepo,
    ) {}

    #[Get(self::URL . '[/]', name: 'tasks.index')]
    public function tasksIndex(): iResponse
    {
        $tasks = [];

        foreach (TasksCommand::getTasks() as $task) {
            $task = $this->formatTask($task);
            if (true === (bool) ag($task, 'hide', false)) {
                continue;
            }

            $task['queued'] = null !== $this->isQueued(ag($task, 'name'));
            $tasks[] = $task;
        }

        $queued = [];
        foreach (array_filter($tasks, static fn($item) => $item['queued'] === true) as $item) {
            $queued[] = $item['name'];
        }

        return api_response(Status::OK, [
            'tasks' => $tasks,
            'queued' => $queued,
        ]);
    }

    #[Route(['GET', 'POST', 'DELETE'], self::URL . '/{id:[a-zA-Z0-9_-]+}/queue[/]', name: 'tasks.task.queue')]
    public function taskQueue(iRequest $request, string $id): iResponse
    {
        $task = TasksCommand::getTasks($id);

        if (empty($task)) {
            return api_error('Task not found.', Status::NOT_FOUND);
        }

        $queuedTask = $this->isQueued(ag($task, 'name'));

        if ('POST' === $request->getMethod()) {
            if (null !== $queuedTask) {
                return api_error('Task already queued.', Status::CONFLICT);
            }

            $event = queue_event(
                TasksCommand::NAME,
                ['name' => $id],
                [
                    EventsTable::COLUMN_REFERENCE => r('task://{name}', ['name' => $id]),
                ],
            );

            return api_response(Status::ACCEPTED, $event->getAll());
        }

        if ('DELETE' === $request->getMethod()) {
            if (null === $queuedTask) {
                return api_error('Task not queued.', Status::NOT_FOUND);
            }

            if ($queuedTask->status === EventStatus::RUNNING) {
                return api_error('Cannot remove task in running state.', Status::BAD_REQUEST);
            }

            $queuedTask->status = EventStatus::CANCELLED;
            $this->eventsRepo->save($queuedTask);

            return api_response(Status::OK);
        }

        return api_response(Status::OK, [
            'task' => $id,
            'is_queued' => null !== $queuedTask,
        ]);
    }

    #[Get(self::URL . '/{id:[a-zA-Z0-9_-]+}[/]', name: 'tasks.task.view')]
    public function taskView(string $id): iResponse
    {
        $task = TasksCommand::getTasks($id);

        if (empty($task)) {
            return api_error('Task not found.', Status::NOT_FOUND);
        }

        $data = $this->formatTask($task);
        $data['queued'] = null !== $this->isQueued(ag($task, 'name'));

        return api_response(Status::OK, $data);
    }

    private function formatTask(array $task): array
    {
        $isEnabled = (bool) ag($task, 'enabled', false);
        $lastRun = $this->lastRun((string) ag($task, 'name'));

        $timer = ag($task, 'timer');
        assert($timer instanceof CronExpression, 'Expected CronExpression for task timer.');

        $item = [
            'name' => ag($task, 'name'),
            'description' => ag($task, 'description'),
            'enabled' => true === $isEnabled,
            'timer' => $timer->getExpression(),
            'next_run' => null,
            'prev_run' => null !== $lastRun ? $lastRun['time'] : null,
            'prev_run_event_id' => null !== $lastRun ? $lastRun['id'] : null,
            'command' => ag($task, 'command'),
            'args' => ag($task, 'args'),
            'hide' => (bool) ag($task, 'hide', false),
        ];

        if (!is_string($item['command'])) {
            $item['command'] = get_debug_type($item['command']);
        }

        $ff = get_env_spec('WS_CRON_' . strtoupper(ag($task, 'name')));
        $item['allow_disable'] = !empty($ff);

        try {
            if (true === $isEnabled) {
                $item['next_run'] = make_date($timer->getNextRunDate());
            }
        } catch (Throwable) {
            $item['next_run'] = null;
        }

        return $item;
    }

    /**
     * @return array{id:string,time:string}|null
     */
    private function lastRun(string $id): ?array
    {
        $criteria = [
            EventsTable::COLUMN_STATUS => [
                DBLayer::IS_IN,
                [
                    EventStatus::SUCCESS->value,
                    EventStatus::FAILED->value,
                ],
            ],
        ];

        $getLast = function (array $query) use ($criteria): ?array {
            $items = (clone $this->eventsRepo)
                ->setPerpage(1)
                ->setStart(0)
                ->setDescendingOrder()
                ->setSort(EventsTable::COLUMN_UPDATED_AT)
                ->findAll([...$criteria, ...$query]);

            if (!isset($items[0]) || !$items[0] instanceof Event) {
                return null;
            }

            $event = $items[0];
            $date = null !== $event->updated_at ? $event->updated_at : $event->created_at;

            return [
                'id' => (string) $event->id,
                'time' => (string) $date,
                'ts' => $date->getTimestamp(),
            ];
        };

        $runs = array_values(array_filter([
            $getLast([
                EventsTable::COLUMN_EVENT => TasksCommand::NAME . '.' . $id,
            ]),
            $getLast([
                EventsTable::COLUMN_EVENT => TasksCommand::NAME,
                EventsTable::COLUMN_REFERENCE => r('task://{name}', ['name' => $id]),
            ]),
        ]));

        if ([] === $runs) {
            return null;
        }

        usort($runs, static fn(array $a, array $b): int => $b['ts'] <=> $a['ts']);

        unset($runs[0]['ts']);

        return $runs[0];
    }

    private function isQueued(string $id): ?Event
    {
        return $this->eventsRepo->findByReference(r('task://{name}', ['name' => $id]), [
            EventsTable::COLUMN_STATUS => EventStatus::PENDING->value,
        ]);
    }
}
