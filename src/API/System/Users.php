<?php

declare(strict_types=1);

namespace App\API\System;

use App\Libs\Attributes\Route\Get;
use App\Libs\Enums\Http\Status;
use App\Libs\Mappers\ImportInterface as iImport;
use App\Libs\Traits\APITraits;
use Psr\Http\Message\ResponseInterface as iResponse;
use Psr\Http\Message\ServerRequestInterface as iRequest;
use Psr\Log\LoggerInterface as iLogger;

final class Users
{
    use APITraits;

    public const string URL = '%{api.prefix}/system/users';

    #[Get(self::URL . '[/]', name: 'system.users')]
    public function __invoke(iRequest $request, iImport $mapper, iLogger $logger): iResponse
    {
        $users = [];
        $usersContext = get_users_context($mapper, $logger);
        foreach ($usersContext as $username => $userContext) {
            $users[] = [
                'user' => $username,
                'backends' => array_keys($userContext->config->getAll()),
            ];
        }

        return api_response(Status::OK, ['users' => $users]);
    }
}
