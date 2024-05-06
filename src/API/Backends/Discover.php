<?php

declare(strict_types=1);

namespace App\API\Backends;

use App\Backends\Plex\PlexClient;
use App\Libs\Attributes\Route\Route;
use App\Libs\DataUtil;
use App\Libs\Exceptions\InvalidArgumentException;
use App\Libs\HTTP_STATUS;
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

    #[Route(['GET', 'POST'], Index::URL . '/discover/{type}[/]', name: 'backends.get.users')]
    public function __invoke(iRequest $request, array $args = []): iResponse
    {
        if (null === ($type = ag($args, 'type'))) {
            return api_error('Invalid value for type path parameter.', HTTP_STATUS::HTTP_BAD_REQUEST);
        }

        if ('plex' !== $type) {
            return api_error('Discover only supported on plex.', HTTP_STATUS::HTTP_BAD_REQUEST);
        }

        try {
            $client = $this->getBasicClient($type, DataUtil::fromRequest($request, true));
            assert($client instanceof PlexClient);
        } catch (InvalidArgumentException $e) {
            return api_error($e->getMessage(), HTTP_STATUS::HTTP_BAD_REQUEST);
        }

        try {
            $list = $client::discover($this->http, $client->getContext()->backendToken);
            return api_response(HTTP_STATUS::HTTP_OK, ag($list, 'list', []));
        } catch (Throwable $e) {
            return api_error($e->getMessage(), HTTP_STATUS::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
