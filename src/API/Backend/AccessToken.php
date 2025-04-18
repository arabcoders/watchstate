<?php

declare(strict_types=1);

namespace App\API\Backend;

use App\Libs\Attributes\Route\Post;
use App\Libs\DataUtil;
use App\Libs\Enums\Http\Status;
use App\Libs\Exceptions\InvalidArgumentException;
use App\Libs\Exceptions\RuntimeException;
use App\Libs\Mappers\ImportInterface as iImport;
use App\Libs\Traits\APITraits;
use Psr\Http\Message\ResponseInterface as iResponse;
use Psr\Http\Message\ServerRequestInterface as iRequest;
use Psr\Log\LoggerInterface as iLogger;
use Symfony\Contracts\HttpClient\HttpClientInterface as iHttp;
use Throwable;

final class AccessToken
{
    use APITraits;

    public function __construct(private readonly iHttp $http)
    {
    }

    #[Post(Index::URL . '/{name:backend}/accesstoken[/]', name: 'backend.accesstoken')]
    public function __invoke(iRequest $request, string $name, iImport $mapper, iLogger $logger): iResponse
    {
        try {
            $userContext = $this->getUserContext($request, $mapper, $logger);
        } catch (RuntimeException $e) {
            return api_error($e->getMessage(), Status::NOT_FOUND);
        }

        if (null === $this->getBackend(name: $name, userContext: $userContext)) {
            return api_error(r("Backend '{name}' not found.", ['name' => $name]), Status::NOT_FOUND);
        }

        $data = DataUtil::fromArray($request->getParsedBody());

        if (null === ($id = $data->get('id'))) {
            return api_error('No id was given.', Status::BAD_REQUEST);
        }

        try {
            $client = $this->getClient(name: $name, userContext: $userContext);
            $token = $client->getUserToken(
                userId: $id,
                username: $data->get('username', $client->getContext()->backendName . '_user'),
            );

            if (!is_string($token)) {
                return api_error('Failed to generate access token.', Status::INTERNAL_SERVER_ERROR);
            }

            $arr = [
                'token' => $token,
            ];

            if ($data->get('username')) {
                $arr['username'] = $data->get('username');
            }

            return api_response(Status::OK, $arr);
        } catch (InvalidArgumentException $e) {
            return api_error($e->getMessage(), Status::NOT_FOUND);
        } catch (Throwable $e) {
            return api_error($e->getMessage(), Status::INTERNAL_SERVER_ERROR);
        }
    }
}
