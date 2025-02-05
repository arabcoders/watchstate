<?php

declare(strict_types=1);

namespace App\API\Backend;

use App\Libs\Attributes\Route\Get;
use App\Libs\Enums\Http\Status;
use App\Libs\Exceptions\RuntimeException;
use App\Libs\Exceptions\InvalidArgumentException;
use App\Libs\Mappers\ExtendedImportInterface as iEImport;
use App\Libs\Traits\APITraits;
use Psr\Http\Message\ResponseInterface as iResponse;
use Psr\Http\Message\ServerRequestInterface as iRequest;
use Psr\Log\LoggerInterface as iLogger;
use Throwable;

final class Version
{
    use APITraits;

    #[Get(Index::URL . '/{name:backend}/version[/]', name: 'backend.version')]
    public function __invoke(iRequest $request, string $name, iEImport $mapper, iLogger $logger): iResponse
    {
        try {
            $userContext = $this->getUserContext($request, $mapper, $logger);
        } catch (RuntimeException $e) {
            return api_error($e->getMessage(), Status::NOT_FOUND);
        }

        if (null === $this->getBackend(name: $name, userContext: $userContext)) {
            return api_error(r("Backend '{name}' not found.", ['name' => $name]), Status::NOT_FOUND);
        }

        try {
            return api_response(Status::OK, [
                'version' => $this->getClient(name: $name, userContext: $userContext)->getVersion()
            ]);
        } catch (InvalidArgumentException $e) {
            return api_error($e->getMessage(), Status::NOT_FOUND);
        } catch (Throwable $e) {
            return api_error($e->getMessage(), Status::INTERNAL_SERVER_ERROR);
        }
    }
}
