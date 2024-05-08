<?php

declare(strict_types=1);

namespace App\API\Backends;

use App\Libs\Attributes\Route\Route;
use App\Libs\DataUtil;
use App\Libs\Exceptions\InvalidArgumentException;
use App\Libs\HTTP_STATUS;
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
            return api_error('Invalid value for type path parameter.', HTTP_STATUS::HTTP_BAD_REQUEST);
        }

        try {
            $client = $this->getBasicClient($type, DataUtil::fromRequest($request, true));
        } catch (InvalidArgumentException $e) {
            return api_error($e->getMessage(), HTTP_STATUS::HTTP_BAD_REQUEST);
        }

        $users = $opts = [];
        $params = DataUtil::fromRequest($request, true);

        if (true === (bool)$params->get('tokens', false)) {
            $opts['tokens'] = true;
        }

        try {
            foreach ($client->getUsersList($opts) as $user) {
                $users[] = $user;
            }
        } catch (Throwable $e) {
            return api_error($e->getMessage(), HTTP_STATUS::HTTP_INTERNAL_SERVER_ERROR);
        }

        return api_response(HTTP_STATUS::HTTP_OK, $users);
    }
}
