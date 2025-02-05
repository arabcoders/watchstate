<?php

declare(strict_types=1);

namespace App\API\System;

use App\Libs\Attributes\Route\Get;
use App\Libs\Enums\Http\Status;
use App\Libs\Mappers\ExtendedImportInterface as iEImport;
use App\Libs\Traits\APITraits;
use Psr\Http\Message\ResponseInterface as iResponse;
use Psr\Http\Message\ServerRequestInterface as iRequest;
use Psr\Log\LoggerInterface as iLogger;

final class Users
{
    use APITraits;

    public const string URL = '%{api.prefix}/system/users';

    #[Get(self::URL . '[/]', name: 'system.users')]
    public function __invoke(iRequest $request, iEImport $mapper, iLogger $logger): iResponse
    {
        return api_response(Status::OK, [
            'users' => array_keys(getUsersContext($mapper, $logger)),
        ]);
    }
}
