<?php

declare(strict_types=1);

namespace App\API\Backend;

use App\Libs\Attributes\Route\Post;
use App\Libs\DataUtil;
use App\Libs\Exceptions\InvalidArgumentException;
use App\Libs\HTTP_STATUS;
use App\Libs\Traits\APITraits;
use Psr\Http\Message\ResponseInterface as iResponse;
use Psr\Http\Message\ServerRequestInterface as iRequest;
use Symfony\Contracts\HttpClient\HttpClientInterface as iHttp;
use Throwable;

final class AccessToken
{
    use APITraits;

    public function __construct(private readonly iHttp $http)
    {
    }

    #[Post(Index::URL . '/{name:backend}/accesstoken[/]', name: 'backend.accesstoken')]
    public function __invoke(iRequest $request, array $args = []): iResponse
    {
        if (null === ($name = ag($args, 'name'))) {
            return api_error('Invalid value for name path parameter.', HTTP_STATUS::HTTP_BAD_REQUEST);
        }

        if (null === $this->getBackend(name: $name)) {
            return api_error(r("Backend '{name}' not found.", ['name' => $name]), HTTP_STATUS::HTTP_NOT_FOUND);
        }

        $data = DataUtil::fromArray($request->getParsedBody());

        if (null === ($id = $data->get('id'))) {
            return api_error('No id was given.', HTTP_STATUS::HTTP_BAD_REQUEST);
        }

        try {
            $client = $this->getClient(name: $name);
            $token = $client->getUserToken(
                userId: $id,
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
        } catch (InvalidArgumentException $e) {
            return api_error($e->getMessage(), HTTP_STATUS::HTTP_NOT_FOUND);
        } catch (Throwable $e) {
            return api_error($e->getMessage(), HTTP_STATUS::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
