<?php

declare(strict_types=1);

namespace App\API\History;

use App\Libs\Attributes\Route\Get;
use App\Libs\Container;
use App\Libs\Database\DatabaseInterface as iDB;
use App\Libs\Entity\StateInterface as iState;
use App\Libs\HTTP_STATUS;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

#[Get(Index::URL . '/{id:\d+}[/]', name: 'history.view')]
final readonly class View
{
    public function __construct(private iDB $db)
    {
    }

    public function __invoke(ServerRequestInterface $request, array $args = []): ResponseInterface
    {
        if (null === ($id = ag($args, 'id'))) {
            return api_error('Invalid value for id path parameter.', HTTP_STATUS::HTTP_BAD_REQUEST);
        }

        $entity = Container::get(iState::class)::fromArray([iState::COLUMN_ID => $id]);

        if (null === ($item = $this->db->get($entity))) {
            return api_error('Not found', HTTP_STATUS::HTTP_NOT_FOUND);
        }

        $apiUrl = $request->getUri()->withHost('')->withPort(0)->withScheme('');

        $item = $item->getAll();

        $item[iState::COLUMN_WATCHED] = $entity->isWatched();
        $item[iState::COLUMN_UPDATED] = makeDate($entity->updated);

        $item = [
            ...$item,
            'links' => [
                'self' => (string)$apiUrl,
                'list' => (string)$apiUrl->withPath(parseConfigValue(Index::URL)),
            ],
        ];

        return api_response(HTTP_STATUS::HTTP_OK, ['history' => $item]);
    }
}
