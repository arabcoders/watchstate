<?php

declare(strict_types=1);

namespace App\API\Backends\Plex;

use App\Libs\Attributes\Route\Post;
use App\Libs\DataUtil;
use App\Libs\Exceptions\RuntimeException;
use App\Libs\HTTP_STATUS;
use App\Libs\Traits\APITraits;
use Psr\Http\Message\ResponseInterface as iResponse;
use Psr\Http\Message\ServerRequestInterface as iRequest;
use Symfony\Contracts\HttpClient\HttpClientInterface as iHttp;

final class GenerateAccessToken
{
    use APITraits;

    public const string URL = '%{api.prefix}/backends/plex/accesstoken';

    public function __construct(private iHttp $http)
    {
    }

    #[Post(self::URL . '/{id:backend}[/]', name: 'backends.plex.accesstoken')]
    public function gAccesstoken(iRequest $request, array $args = []): iResponse
    {
        $backend = ag($args, 'id');

        $data = DataUtil::fromArray($request->getParsedBody());

        if (null === ($uuid = $data->get('uuid'))) {
            return api_error('No User (uuid) was given.', HTTP_STATUS::HTTP_BAD_REQUEST);
        }

        try {
            $client = $this->getBackend($backend);
        } catch (RuntimeException $e) {
            return api_error($e->getMessage(), HTTP_STATUS::HTTP_INTERNAL_SERVER_ERROR);
        }

        try {
            $token = $client->getUserToken(
                userId: $uuid,
                username: $data->get('username', $client->getContext()->backendName . '_user'),
            );

            if (!is_string($token)) {
                return api_error('Failed to generate access token.', HTTP_STATUS::HTTP_INTERNAL_SERVER_ERROR);
            }

            $arr = [
                'token' => $token,
            ];

            if ($data->get('username')) {
                $arr['username'] = $data->get('username');
            }

            return api_response(HTTP_STATUS::HTTP_OK, $arr);
        } catch (\Throwable $e) {
            return api_error($e->getMessage(), HTTP_STATUS::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
