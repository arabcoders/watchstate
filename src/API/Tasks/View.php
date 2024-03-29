<?php

declare(strict_types=1);

namespace App\API\Tasks;

use App\Commands\System\TasksCommand;
use App\Libs\Attributes\Route\Get;
use App\Libs\HTTP_STATUS;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

#[Get(Index::URL . '/{id:[a-zA-Z0-9_-]+}[/]', name: 'tasks.view')]
final class View
{
    public function __invoke(ServerRequestInterface $request, array $args = []): ResponseInterface
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
}
