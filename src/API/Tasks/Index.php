<?php

declare(strict_types=1);

namespace App\API\Tasks;

use App\Commands\System\TasksCommand;
use App\Libs\Attributes\Route\Get;
use App\Libs\Attributes\Route\Route;
use App\Libs\HTTP_STATUS;
use Cron\CronExpression;
use DateInterval;
use Psr\Http\Message\ResponseInterface as iResponse;
use Psr\Http\Message\ServerRequestInterface as iRequest;
use Psr\SimpleCache\CacheInterface as iCache;
use Psr\SimpleCache\InvalidArgumentException;

final class Index
{
    public const string URL = '%{api.prefix}/tasks';

    public function __construct(private readonly iCache $cache)
    {
    }

    /**
     * @throws InvalidArgumentException
     */
    #[Get(self::URL . '[/]', name: 'tasks.index')]
    public function tasksIndex(iRequest $request): iResponse
    {
        $queuedTasks = $this->cache->get('queued_tasks', []);
        $response = [
            'tasks' => [],
            'queued' => $queuedTasks,
            'status' => isTaskWorkerRunning(),
        ];

        foreach (TasksCommand::getTasks() as $task) {
            $task = self::formatTask($task);
            $task['queued'] = in_array(ag($task, 'name'), $queuedTasks);
            $response['tasks'][] = $task;
        }

        return api_response(HTTP_STATUS::HTTP_OK, $response);
    }

    /**
     * @throws InvalidArgumentException
     */
    #[Route(['GET', 'POST', 'DELETE'], self::URL . '/{id:[a-zA-Z0-9_-]+}/queue[/]', name: 'tasks.task.queue')]
    public function taskQueue(iRequest $request, array $args = []): iResponse
    {
        if (null === ($id = ag($args, 'id'))) {
            return api_error('No id was given.', HTTP_STATUS::HTTP_BAD_REQUEST);
        }

        $task = TasksCommand::getTasks($id);

        if (empty($task)) {
            return api_error('Task not found.', HTTP_STATUS::HTTP_NOT_FOUND);
        }

        $queuedTasks = $this->cache->get('queued_tasks', []);

        if ('POST' === $request->getMethod()) {
            $queuedTasks[] = $id;
            $this->cache->set('queued_tasks', $queuedTasks, new DateInterval('P3D'));
            return api_response(HTTP_STATUS::HTTP_ACCEPTED, ['queue' => $queuedTasks]);
        }

        if ('DELETE' === $request->getMethod()) {
            $queuedTasks = array_filter($queuedTasks, fn($v) => $v !== $id);
            $this->cache->set('queued_tasks', $queuedTasks, new DateInterval('P3D'));
            return api_response(HTTP_STATUS::HTTP_OK, ['queue' => $queuedTasks]);
        }

        return api_response(HTTP_STATUS::HTTP_OK, [
            'task' => $id,
            'is_queued' => in_array($id, $queuedTasks),
        ]);
    }

    /**
     * @throws InvalidArgumentException
     */
    #[Get(self::URL . '/{id:[a-zA-Z0-9_-]+}[/]', name: 'tasks.task.view')]
    public function taskView(iRequest $request, array $args = []): iResponse
    {
        if (null === ($id = ag($args, 'id'))) {
            return api_error('No id was given.', HTTP_STATUS::HTTP_BAD_REQUEST);
        }

        $task = TasksCommand::getTasks($id);

        if (empty($task)) {
            return api_error('Task not found.', HTTP_STATUS::HTTP_NOT_FOUND);
        }

        $queuedTasks = $this->cache->get('queued_tasks', []);

        $data = Index::formatTask($task);
        $data['queued'] = in_array(ag($task, 'name'), $queuedTasks);

        return api_response(HTTP_STATUS::HTTP_OK, $data);
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
        ];

        if (!is_string($item['command'])) {
            $item['command'] = get_debug_type($item['command']);
        }

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
}
