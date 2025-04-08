<?php

declare(strict_types=1);

namespace App\API\Backends;

use App\Backends\Plex\PlexClient;
use App\Libs\Attributes\Route\Route;
use App\Libs\DataUtil;
use App\Libs\Enums\Http\Status;
use App\Libs\Traits\APITraits;
use Psr\Http\Message\ResponseInterface as iResponse;
use Psr\Http\Message\ServerRequestInterface as iRequest;
use Symfony\Contracts\HttpClient\HttpClientInterface as iHttp;
use Throwable;

final class ValidateToken
{
    use APITraits;

    #[Route(['POST'], Index::URL . '/validate/token/{type}[/]', name: 'backends.validate.token')]
    public function __invoke(iRequest $request, iHttp $http, string $type): iResponse
    {
        if (false === (ucfirst($type) === PlexClient::CLIENT_NAME)) {
            return api_error('Validate token api endpoint only supported for plex client.', Status::BAD_REQUEST);
        }

        $params = DataUtil::fromRequest($request);

        if (null === ($token = $params->get('token'))) {
            return api_error('Token is required.', Status::BAD_REQUEST);
        }

        try {
            $status = PlexClient::validate_token($http, $token);

            if (true === $status) {
                return api_message('Token is valid.', Status::OK);
            }

            return api_error('non 200 OK request received.', Status::UNAUTHORIZED);
        } catch (Throwable $e) {
            return api_error($e->getMessage(), Status::tryFrom($e->getCode()) ?? Status::BAD_REQUEST);
        }
    }
}
