<?php

declare(strict_types=1);

namespace App\API\Backend;

use App\Libs\Attributes\Route\Get;
use App\Libs\Enums\Http\Status;
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
            return api_error('Invalid value for name path parameter.', Status::BAD_REQUEST);
        }

        if (null === ($data = $this->getBackend(name: $name))) {
            return api_error(r("Backend '{name}' not found.", ['name' => $name]), Status::NOT_FOUND);
        }

        return api_response(Status::OK, $data);
    }
}
