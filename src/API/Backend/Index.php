<?php

declare(strict_types=1);

namespace App\API\Backend;

use App\Libs\Attributes\Route\Get;
use App\Libs\HTTP_STATUS;
use App\Libs\Traits\APITraits;
use Psr\Http\Message\ResponseInterface as iResponse;
use Psr\Http\Message\ServerRequestInterface as iRequest;

final class Index
{
    use APITraits;

    public const string URL = '%{api.prefix}/backend';

    #[Get(self::URL . '/{name:backend}[/]', name: 'backend.view')]
    public function __invoke(iRequest $request, array $args = []): iResponse
    {
        if (null === ($name = ag($args, 'name'))) {
            return api_error('Invalid value for name path parameter.', HTTP_STATUS::HTTP_BAD_REQUEST);
        }

        $data = $this->getBackends(name: $name);

        if (empty($data)) {
            return api_error(r("Backend '{name}' not found.", ['name' => $name]), HTTP_STATUS::HTTP_NOT_FOUND);
        }

        $data = array_pop($data);

        return api_response(HTTP_STATUS::HTTP_OK, $data);
    }
}
