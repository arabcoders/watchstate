<?php

declare(strict_types=1);

namespace App\API\Backend;

use App\Libs\Attributes\Route\Get;
use App\Libs\DataUtil;
use App\Libs\Enums\Http\Status;
use App\Libs\Exceptions\RuntimeException;
use App\Libs\Exceptions\InvalidArgumentException;
use App\Libs\Mappers\ExtendedImportInterface as iEImport;
use App\Libs\Options;
use App\Libs\Traits\APITraits;
use Psr\Http\Message\ResponseInterface as iResponse;
use Psr\Http\Message\ServerRequestInterface as iRequest;
use Psr\Log\LoggerInterface as iLogger;
use Throwable;

final class Sessions
{
    use APITraits;

    #[Get(Index::URL . '/{name:backend}/sessions[/]', name: 'backend.sessions')]
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
            $client = $this->getClient(name: $name, userContext: $userContext);
        } catch (InvalidArgumentException $e) {
            return api_error($e->getMessage(), Status::NOT_FOUND);
        }

        $opts = [];
        $params = DataUtil::fromRequest($request, true);

        if (true === (bool)$params->get('raw', false)) {
            $opts[Options::RAW_RESPONSE] = true;
        }

        try {
            $sessions = $client->getSessions($opts);
            return api_response(Status::OK, ag($sessions, 'sessions', []));
        } catch (Throwable $e) {
            return api_error($e->getMessage(), Status::INTERNAL_SERVER_ERROR);
        }
    }
}
