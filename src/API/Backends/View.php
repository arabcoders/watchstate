<?php

declare(strict_types=1);

namespace App\API\Backends;

use App\Libs\Attributes\Route\Get;
use App\Libs\HTTP_STATUS;
use App\Libs\Traits\APITraits;
use Psr\Http\Message\ResponseInterface as iResponse;
use Psr\Http\Message\ServerRequestInterface as iRequest;

final class View
{
    use APITraits;

    #[Get(Index::URL . '/{name:backend}[/]', name: 'backends.view')]
    public function backendsView(iRequest $request, array $args = []): iResponse
    {
        if (null === ($name = ag($args, 'name'))) {
            return api_error('Invalid value for id path parameter.', HTTP_STATUS::HTTP_BAD_REQUEST);
        }

        $data = $this->getBackends(name: $name);

        if (empty($data)) {
            return api_error(r("Backend '{name}' not found.", ['name' => $name]), HTTP_STATUS::HTTP_NOT_FOUND);
        }

        $apiUrl = $request->getUri()->withHost('')->withPort(0)->withScheme('');
        $data = array_pop($data);

        $response = [
            ...$data,
            'links' => [
                'self' => (string)$apiUrl,
                'list' => (string)$apiUrl->withPath(parseConfigValue(Index::URL)),
            ],
        ];

        return api_response(HTTP_STATUS::HTTP_OK, ['backend' => $response]);
    }
}
