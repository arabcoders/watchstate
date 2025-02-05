<?php

declare(strict_types=1);

namespace App\API\Backend;

use App\Libs\Attributes\Route\Get;
use App\Libs\Enums\Http\Status;
use App\Libs\Exceptions\RuntimeException;
use App\Libs\Mappers\ExtendedImportInterface as iEImport;
use App\Libs\Traits\APITraits;
use Psr\Http\Message\ResponseInterface as iResponse;
use Psr\Http\Message\ServerRequestInterface as iRequest;
use Psr\Log\LoggerInterface as iLogger;

final class Index
{
    use APITraits;

    public const string URL = '%{api.prefix}/backend';

    #[Get(self::URL . '/{name:backend}[/]', name: 'backend.view')]
    public function __invoke(iRequest $request, string $name, iEImport $mapper, iLogger $logger): iResponse
    {
        try {
            $userContext = $this->getUserContext($request, $mapper, $logger);
        } catch (RuntimeException $e) {
            return api_error($e->getMessage(), Status::NOT_FOUND);
        }

        if (null === ($data = $this->getBackend(name: $name, userContext: $userContext))) {
            return api_error(r("Backend '{name}' not found.", ['name' => $name]), Status::NOT_FOUND);
        }

        return api_response(Status::OK, $data);
    }
}
