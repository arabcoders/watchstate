<?php

declare(strict_types=1);

namespace App\API\Tasks;

use App\Commands\System\TasksCommand;
use App\Libs\Attributes\Route\Get;
use App\Libs\HTTP_STATUS;
use Psr\Http\Message\ResponseInterface as iResponse;
use Psr\Http\Message\ServerRequestInterface as iRequest;

final class Index
{
    public const string URL = '%{api.prefix}/tasks';

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
            ];

            $response['tasks'][] = $task;
        }

        return api_response(HTTP_STATUS::HTTP_OK, $response);
    }

    #[Get(self::URL . '/{id:[a-zA-Z0-9_-]+}[/]', name: 'tasks.view')]
    public function __invoke(iRequest $request, array $args = []): iResponse
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
