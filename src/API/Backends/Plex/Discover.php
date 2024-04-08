<?php

declare(strict_types=1);

namespace App\API\Backends\Plex;

use App\Backends\Plex\PlexClient;
use App\Libs\Attributes\Route\Post;
use App\Libs\DataUtil;
use App\Libs\HTTP_STATUS;
use Psr\Http\Message\ResponseInterface as iResponse;
use Psr\Http\Message\ServerRequestInterface as iRequest;
use Symfony\Contracts\HttpClient\HttpClientInterface as iHttp;

final class Discover
{
    public const string URL = '%{api.prefix}/backends/plex/discover';

    public function __construct(private iHttp $http)
    {
    }

    #[Post(self::URL . '[/]', name: 'backends.plex.discover')]
    public function plexDiscover(iRequest $request): iResponse
    {
        $data = DataUtil::fromArray($request->getParsedBody());

        if (null === ($token = $data->get('token'))) {
            return api_error('No token was given.', HTTP_STATUS::HTTP_BAD_REQUEST);
        }

        try {
            $list = PlexClient::discover($this->http, $token);
        } catch (\Throwable $e) {
            return api_error($e->getMessage(), HTTP_STATUS::HTTP_INTERNAL_SERVER_ERROR);
        }

        return api_response(HTTP_STATUS::HTTP_OK, ag($list, 'list', []));
    }
}
