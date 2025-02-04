<?php

declare(strict_types=1);

namespace App\API\Backend;

use App\Libs\Attributes\Route\Get;
use App\Libs\DataUtil;
use App\Libs\Enums\Http\Status;
use App\Libs\Exceptions\InvalidArgumentException;
use App\Libs\Options;
use App\Libs\Traits\APITraits;
use Psr\Http\Message\ResponseInterface as iResponse;
use Psr\Http\Message\ServerRequestInterface as iRequest;
use Throwable;

final class Users
{
    use APITraits;

    #[Get(Index::URL . '/{name:backend}/users[/]', name: 'backend.users')]
    public function __invoke(iRequest $request, array $args = []): iResponse
    {
        if (null === ($name = ag($args, 'name'))) {
            return api_error('Invalid value for id path parameter.', Status::BAD_REQUEST);
        }

        if (null === $this->getBackend(name: $name)) {
            return api_error(r("Backend '{name}' not found.", ['name' => $name]), Status::NOT_FOUND);
        }

        $opts = [];
        $params = DataUtil::fromRequest($request, true);

        if (true === (bool)$params->get('tokens', false)) {
            $opts['tokens'] = true;
        }

        if (true === (bool)$params->get('raw', false)) {
            $opts[Options::RAW_RESPONSE] = true;
        }

        try {
            return api_response(Status::OK, $this->getClient(name: $name)->getUsersList($opts));
        } catch (InvalidArgumentException $e) {
            return api_error($e->getMessage(), Status::NOT_FOUND);
        } catch (Throwable $e) {
            return api_error($e->getMessage(), Status::INTERNAL_SERVER_ERROR);
        }
    }
}
