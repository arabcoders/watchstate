<?php

declare(strict_types=1);

namespace App\API\Backends;

use App\Libs\Attributes\Route\Route;
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

    #[Route(['GET', 'POST'], Index::URL . '/users/{type}[/]', name: 'backends.get.users')]
    public function __invoke(iRequest $request, array $args = []): iResponse
    {
        if (null === ($type = ag($args, 'type'))) {
            return api_error('Invalid value for type path parameter.', Status::BAD_REQUEST);
        }

        $params = DataUtil::fromRequest($request, true);

        try {
            $client = $this->getBasicClient($type, $params);
        } catch (InvalidArgumentException $e) {
            return api_error($e->getMessage(), Status::BAD_REQUEST);
        }

        $users = $opts = [];

        if (true === (bool)$params->get(Options::GET_TOKENS, false)) {
            $opts[Options::GET_TOKENS] = true;
        }

        if (true === (bool)$params->get('no_cache', false)) {
            $opts[Options::NO_CACHE] = true;
        }

        try {
            foreach ($client->getUsersList($opts) as $user) {
                $users[] = $user;
            }
        } catch (Throwable $e) {
            return api_error($e->getMessage(), Status::INTERNAL_SERVER_ERROR);
        }

        return api_response(Status::OK, $users);
    }
}
