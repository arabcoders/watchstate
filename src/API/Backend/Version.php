<?php

declare(strict_types=1);

namespace App\API\Backend;

use App\Libs\Attributes\Route\Get;
use App\Libs\Enums\Http\Status;
use App\Libs\Exceptions\InvalidArgumentException;
use App\Libs\Traits\APITraits;
use Psr\Http\Message\ResponseInterface as iResponse;
use Psr\Http\Message\ServerRequestInterface as iRequest;
use Throwable;

final class Version
{
    use APITraits;

    #[Get(Index::URL . '/{name:backend}/version[/]', name: 'backend.version')]
    public function __invoke(iRequest $request, array $args = []): iResponse
    {
        if (null === ($name = ag($args, 'name'))) {
            return api_error('Invalid value for id path parameter.', Status::HTTP_BAD_REQUEST);
        }

        if (null === $this->getBackend(name: $name)) {
            return api_error(r("Backend '{name}' not found.", ['name' => $name]), Status::HTTP_NOT_FOUND);
        }

        try {
            return api_response(Status::HTTP_OK, ['version' => $this->getClient(name: $name)->getVersion()]);
        } catch (InvalidArgumentException $e) {
            return api_error($e->getMessage(), Status::HTTP_NOT_FOUND);
        } catch (Throwable $e) {
            return api_error($e->getMessage(), Status::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
