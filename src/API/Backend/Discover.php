<?php

declare(strict_types=1);

namespace App\API\Backend;

use App\Backends\Plex\PlexClient;
use App\Libs\Attributes\Route\Get;
use App\Libs\Enums\Http\Status;
use App\Libs\Exceptions\InvalidArgumentException;
use App\Libs\Traits\APITraits;
use Psr\Http\Message\ResponseInterface as iResponse;
use Psr\Http\Message\ServerRequestInterface as iRequest;
use Symfony\Contracts\HttpClient\HttpClientInterface as iHttp;
use Throwable;

final class Discover
{
    use APITraits;

    public function __construct(private readonly iHttp $http)
    {
    }

    #[Get(Index::URL . '/{name:backend}/discover[/]', name: 'backend.discover')]
    public function __invoke(iRequest $request, array $args = []): iResponse
    {
        if (null === ($name = ag($args, 'name'))) {
            return api_error('Invalid value for name path parameter.', Status::HTTP_BAD_REQUEST);
        }

        if (null === $this->getBackend(name: $name)) {
            return api_error(r("Backend '{name}' not found.", ['name' => $name]), Status::HTTP_NOT_FOUND);
        }

        try {
            $client = $this->getClient(name: $name);

            if (PlexClient::CLIENT_NAME !== $client->getType()) {
                return api_error('Discover is only available for Plex backends.', Status::HTTP_BAD_REQUEST);
            }

            assert($client instanceof PlexClient);

            $list = $client::discover($this->http, $client->getContext()->backendToken);
            return api_response(Status::HTTP_OK, ag($list, 'list', []));
        } catch (InvalidArgumentException $e) {
            return api_error($e->getMessage(), Status::HTTP_NOT_FOUND);
        } catch (Throwable $e) {
            return api_error($e->getMessage(), Status::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
