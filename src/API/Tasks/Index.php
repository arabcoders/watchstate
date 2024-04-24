<?php

declare(strict_types=1);

namespace App\API\Tasks;

use App\Commands\System\TasksCommand;
use App\Libs\Attributes\Route\Get;
use App\Libs\Attributes\Route\Route;
use App\Libs\HTTP_STATUS;
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

    #[Get(self::URL . '[/]', name: 'tasks.index')]
    public function tasksIndex(iRequest $request): iResponse
    {
        $apiUrl = $request->getUri()->withHost('')->withPort(0)->withScheme('');
        $urlPath = rtrim($request->getUri()->getPath(), '/');

        $response = [
            'tasks' => [],
            'links' => [
                'self' => (string)$apiUrl,
            ],
        ];

        foreach (TasksCommand::getTasks() as $task) {
            $task = array_filter(
                self::formatTask($task),
                fn($k) => false === in_array($k, ['command', 'args']),
                ARRAY_FILTER_USE_KEY
            );

            $task['links'] = [
                'self' => (string)$apiUrl->withPath($urlPath . '/' . ag($task, 'name')),
                'queue' => (string)$apiUrl->withPath($urlPath . '/' . ag($task, 'name') . '/queue'),
            ];

            $response['tasks'][] = $task;
        }

        return api_response(HTTP_STATUS::HTTP_OK, $response);
    }

    /**
     * @throws InvalidArgumentException
     */
    #[Route(['GET', 'POST'], self::URL . '/{id:[a-zA-Z0-9_-]+}/queue[/]', name: 'tasks.task.queue')]
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

        $apiUrl = $request->getUri()->withHost('')->withPort(0)->withScheme('')->withUserInfo('');
        $urlPath = parseConfigValue(Index::URL);

        return api_response(HTTP_STATUS::HTTP_OK, [
            'task' => $id,
            'is_queued' => in_array($id, $queuedTasks),
            'links' => [
                'self' => (string)$apiUrl,
                'task' => (string)$apiUrl->withPath($urlPath . '/' . $id),
                'tasks' => (string)$apiUrl->withPath($urlPath),
            ],
        ]);
    }

    #[Get(self::URL . '/{id:[a-zA-Z0-9_-]+}[/]', name: 'tasks.task.view')]
    public function taskView(iRequest $request, array $args = []): iResponse
    {
        if (null === ($id = ag($args, 'id'))) {
            return api_error('No id was given.', HTTP_STATUS::HTTP_BAD_REQUEST);
        }

        $apiUrl = $request->getUri()->withHost('')->withPort(0)->withScheme('');

        $task = TasksCommand::getTasks($id);

        if (empty($task)) {
            return api_error('Task not found.', HTTP_STATUS::HTTP_NOT_FOUND);
        }

        $response = [
            ...Index::formatTask($task),
            'links' => [
                'self' => (string)$apiUrl,
                'list' => (string)$apiUrl->withPath(parseConfigValue(Index::URL)),
            ],
        ];

        return api_response(HTTP_STATUS::HTTP_OK, ['task' => $response]);
    }

    private function formatTask(array $task): array
    {
        $isEnabled = (bool)ag($task, 'enabled', false);

        $item = [
            'name' => ag($task, 'name'),
            'description' => ag($task, 'description'),
            'enabled' => true === $isEnabled,
            'timer' => ag($task, 'timer')->getexpression(),
            'next_run' => null,
            'prev_run' => null,
            'command' => ag($task, 'command'),
            'args' => ag($task, 'args'),
        ];

        if (!is_string($item['command'])) {
            $item['command'] = get_debug_type($item['command']);
        }

        if (true === $isEnabled) {
            $item['next_run'] = makeDate(ag($task, 'timer')->getNextRunDate());
            $item['prev_run'] = makeDate(ag($task, 'timer')->getPreviousRunDate());
        }

        return $item;
    }
}
