<?php

declare(strict_types=1);

namespace App\API\Backend;

use App\Backends\Plex\PlexClient;
use App\Libs\Attributes\Route\Get;
use App\Libs\Enums\Http\Status;
use App\Libs\Exceptions\RuntimeException;
use App\Libs\Exceptions\InvalidArgumentException;
use App\Libs\Mappers\ExtendedImportInterface as iEImport;
use App\Libs\Options;
use App\Libs\Traits\APITraits;
use Psr\Http\Message\ResponseInterface as iResponse;
use Psr\Http\Message\ServerRequestInterface as iRequest;
use Psr\Log\LoggerInterface as iLogger;
use Symfony\Contracts\HttpClient\HttpClientInterface as iHttp;
use Throwable;

final class Discover
{
    use APITraits;

    #[Get(Index::URL . '/{name:backend}/discover[/]', name: 'backend.discover')]
    public function __invoke(iRequest $request, string $name, iEImport $mapper, iLogger $logger, iHttp $http): iResponse
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

            if (PlexClient::CLIENT_NAME !== $client->getType()) {
                return api_error('Discover is only available for Plex backends.', Status::BAD_REQUEST);
            }

            assert($client instanceof PlexClient);

            $context = $client->getContext();
            $opts = [];

            if (null !== ($adminToken = ag($context->options, Options::ADMIN_TOKEN))) {
                $opts[Options::ADMIN_TOKEN] = $adminToken;
            }

            $list = $client::discover($http, $context->backendToken, $opts);
            return api_response(Status::OK, ag($list, 'list', []));
        } catch (InvalidArgumentException $e) {
            return api_error($e->getMessage(), Status::NOT_FOUND);
        } catch (Throwable $e) {
            return api_error($e->getMessage(), Status::INTERNAL_SERVER_ERROR);
        }
    }
}
