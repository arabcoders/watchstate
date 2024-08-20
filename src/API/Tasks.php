<?php

declare(strict_types=1);

namespace App\API;

use App\Commands\System\TasksCommand;
use App\Libs\Attributes\Route\Get;
use App\Libs\Attributes\Route\Route;
use App\Libs\Enums\Http\Status;
use App\Model\Events\Event;
use App\Model\Events\EventsRepository;
use App\Model\Events\EventsTable;
use App\Model\Events\EventStatus;
use Cron\CronExpression;
use Psr\Http\Message\ResponseInterface as iResponse;
use Psr\Http\Message\ServerRequestInterface as iRequest;
use Psr\SimpleCache\InvalidArgumentException;

final class Tasks
{
    public const string URL = '%{api.prefix}/tasks';

    public function __construct(private EventsRepository $eventsRepo)
    {
    }

    /**
     * @throws InvalidArgumentException
     */
    #[Get(self::URL . '[/]', name: 'tasks.index')]
    public function tasksIndex(): iResponse
    {
        $tasks = [];

        foreach (TasksCommand::getTasks() as $task) {
            $task = self::formatTask($task);
            if (true === (bool)ag($task, 'hide', false)) {
                continue;
            }

            $task['queued'] = null !== $this->isQueued(ag($task, 'name'));
            $tasks[] = $task;
        }

        $queued = [];
        foreach (array_filter($tasks, fn($item) => $item['queued'] === true) as $item) {
            $queued[] = $item['name'];
        }

        return api_response(Status::OK, [
            'tasks' => $tasks,
            'queued' => $queued,
            'status' => isTaskWorkerRunning(),
        ]);
    }

    /**
     * @throws InvalidArgumentException
     */
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

            $event = queueEvent(TasksCommand::NAME, ['name' => $id], [
                EventsTable::COLUMN_REFERENCE => r('task://{name}', ['name' => $id]),
            ]);

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

        $data = Tasks::formatTask($task);
        $data['queued'] = null !== $this->isQueued(ag($task, 'name'));

        return api_response(Status::OK, $data);
    }

    private function formatTask(array $task): array
    {
        $isEnabled = (bool)ag($task, 'enabled', false);

        $timer = ag($task, 'timer');
        assert($timer instanceof CronExpression);

        $item = [
            'name' => ag($task, 'name'),
            'description' => ag($task, 'description'),
            'enabled' => true === $isEnabled,
            'timer' => $timer->getExpression(),
            'next_run' => null,
            'prev_run' => null,
            'command' => ag($task, 'command'),
            'args' => ag($task, 'args'),
            'hide' => (bool)ag($task, 'hide', false),
        ];

        if (!is_string($item['command'])) {
            $item['command'] = get_debug_type($item['command']);
        }

        $ff = getEnvSpec('WS_CRON_' . strtoupper(ag($task, 'name')));
        $item['allow_disable'] = !empty($ff);

        if (true === $isEnabled) {
            try {
                $item['next_run'] = makeDate($timer->getNextRunDate());
                $item['prev_run'] = makeDate($timer->getPreviousRunDate());
            } catch (\Exception) {
                $item['next_run'] = null;
                $item['prev_run'] = null;
            }
        }

        return $item;
    }

    private function isQueued(string $id): Event|null
    {
        return $this->eventsRepo->findByReference(r('task://{name}', ['name' => $id]), [
            EventsTable::COLUMN_STATUS => EventStatus::PENDING->value
        ]);
    }
}
