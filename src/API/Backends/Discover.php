<?php

declare(strict_types=1);

namespace App\API\Backends;

use App\Backends\Plex\PlexClient;
use App\Libs\Attributes\Route\Route;
use App\Libs\DataUtil;
use App\Libs\Enums\Http\Status;
use App\Libs\Exceptions\InvalidArgumentException;
use App\Libs\Options;
use App\Libs\Traits\APITraits;
use Psr\Http\Message\ResponseInterface as iResponse;
use Psr\Http\Message\ServerRequestInterface as iRequest;
use Symfony\Contracts\HttpClient\HttpClientInterface as iHttp;
use Throwable;

final class Discover
{
    use APITraits;

    public function __construct(
        private readonly iHttp $http,
    ) {}

    #[Route(['GET', 'POST'], Index::URL . '/discover/{type}[/]', name: 'backends.get.users')]
    public function __invoke(iRequest $request, array $args = []): iResponse
    {
        if (null === ($type = ag($args, 'type'))) {
            return api_error('Invalid value for type path parameter.', Status::BAD_REQUEST);
        }

        if ('plex' !== $type) {
            return api_error('Discover only supported on plex.', Status::BAD_REQUEST);
        }

        try {
            $client = $this->getBasicClient($type, DataUtil::fromRequest($request, true));
            assert($client instanceof PlexClient, 'Expected Plex client for discover request.');
        } catch (InvalidArgumentException $e) {
            return api_error($e->getMessage(), Status::BAD_REQUEST);
        }

        try {
            $opts = [];

            if (null !== ($adminToken = ag($request->getParsedBody(), 'options.' . Options::ADMIN_TOKEN))) {
                $opts[Options::ADMIN_TOKEN] = $adminToken;
            }

            $list = $client::discover($this->http, $client->getContext()->backendToken, $opts);
            return api_response(Status::OK, ag($list, 'list', []));
        } catch (Throwable $e) {
            return api_error($e->getMessage(), Status::INTERNAL_SERVER_ERROR);
        }
    }
}
