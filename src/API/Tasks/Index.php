<?php

declare(strict_types=1);

namespace App\API\Tasks;

use App\Commands\System\TasksCommand;
use App\Libs\Attributes\Route\Get;
use App\Libs\HTTP_STATUS;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

#[Get(self::URL . '[/]', name: 'tasks.index')]
final class Index
{
    public const URL = '%{api.prefix}/tasks';

    public function __invoke(ServerRequestInterface $request, array $args = []): ResponseInterface
    {
        $response = [
            'data' => [],
        ];

        $apiUrl = $request->getUri()->withHost('')->withPort(0)->withScheme('');
        $urlPath = rtrim($request->getUri()->getPath(), '/');

        foreach (TasksCommand::getTasks() as $task) {
            $response['data'][] = [
                '@self' => (string)$apiUrl->withPath($urlPath . '/' . ag($task, 'name')),
                ...array_filter(
                    self::formatTask($task),
                    fn($k) => false === in_array($k, ['command', 'args']),
                    ARRAY_FILTER_USE_KEY
                )
            ];
        }

        return api_response($response, HTTP_STATUS::HTTP_OK, []);
    }

    public static function formatTask(array $task): array
    {
        $isEnabled = (bool)ag($task, 'enabled', false);

        $item = [
            'name' => ag($task, 'name'),
            'description' => ag($task, 'description'),
            'enabled' => $isEnabled,
            'timer' => ag($task, 'timer')->getexpression(),
            'next_run' => null,
            'prev_run' => null,
            'command' => ag($task, 'command'),
            'args' => ag($task, 'args'),
        ];

        if (!is_string($item['command'])) {
            $item['command'] = get_debug_type($item['command']);
        }

        if ($isEnabled) {
            $item['next_run'] = makeDate(ag($task, 'timer')->getNextRunDate());
            $item['prev_run'] = makeDate(ag($task, 'timer')->getPreviousRunDate());
        }

        return $item;
    }
}
