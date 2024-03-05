<?php

declare(strict_types=1);

namespace App\API\Backends;

use App\Libs\Attributes\Route\Get;
use App\Libs\HTTP_STATUS;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

#[Get(self::URL . '/{id:[a-zA-Z0-9_-]+}[/]', name: 'backends.view')]
final class View
{
    public const URL = '%{api.prefix}/backends';

    public function __invoke(ServerRequestInterface $request, array $args = []): ResponseInterface
    {
        if (null === ($id = ag($args, 'id'))) {
            return api_error('Invalid value for id path parameter.', HTTP_STATUS::HTTP_BAD_REQUEST);
        }

        $data = Index::getBackends(name: $id, blacklist: true);
        if (empty($data)) {
            return api_error('Backend not found.', HTTP_STATUS::HTTP_NOT_FOUND);
        }

        $apiUrl = $request->getUri()->withHost('')->withPort(0)->withScheme('');
        $data = array_pop($data);

        $response = [
            ...$data,
            'links' => [
                'self' => (string)$apiUrl,
                'list' => (string)$apiUrl->withPath(parseConfigValue(self::URL)),
            ],
        ];

        return api_response(HTTP_STATUS::HTTP_OK, ['backend' => $response]);
    }

}
