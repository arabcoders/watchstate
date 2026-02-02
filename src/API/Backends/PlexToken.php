<?php

declare(strict_types=1);

namespace App\API\Backends;

use App\Backends\Plex\PlexClient;
use App\Libs\Attributes\Route\Post;
use App\Libs\DataUtil;
use App\Libs\Enums\Http\Method;
use App\Libs\Enums\Http\Status;
use App\Libs\Extends\HttpClient;
use App\Libs\Traits\APITraits;
use Psr\Http\Message\ResponseInterface as iResponse;
use Psr\Http\Message\ServerRequestInterface as iRequest;
use Psr\Log\LoggerInterface as iLogger;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface as iHttp;

final class PlexToken
{
    public const string URL = Index::URL . '/plex';

    use APITraits;

    public function __construct(
        private readonly iLogger $logger,
    ) {}

    /**
     * Check if given plex token is valid.
     *
     * @param iHttp&HttpClient $http The HTTP client used to send the request.
     *
     * @return iResponse The response from the Plex API.
     * @throws ExceptionInterface When a network-related exception occurs.
     */
    #[Post(self::URL . '/generate[/]', name: 'backends.plex.generate')]
    public function generate(iHttp $http): iResponse
    {
        $req = $http->request(Method::POST, 'https://plex.tv/api/v2/pins', [
            'headers' => [
                'accept' => 'application/json',
                ...PlexClient::getHeaders(),
            ],
            'json' => [
                'strong' => true,
            ],
        ]);

        if (Status::CREATED !== Status::from($req->getStatusCode())) {
            $this->logger->error("Request for OAuth PIN returned with unexpected '{status_code}' status code.", [
                'status_code' => $req->getStatusCode(),
                'parsed' => $req->toArray(false),
                'body' => $req->getContent(false),
            ]);
            return api_error(
                r(
                    text: "Request for OAuth PIN returned with unexpected '{status_code}' status code.",
                    context: ['status_code' => $req->getStatusCode()],
                ),
                Status::from($req->getStatusCode()),
            );
        }

        return api_response(Status::OK, [...PlexClient::getHeaders(), ...$req->toArray()]);
    }

    /**
     * Check if given plex token is valid.
     *
     * @param iRequest $request The request object containing the parameters.
     * @param iHttp&HttpClient $http The HTTP client used to send the request.
     *
     * @return iResponse The response from the Plex API.
     * @throws ExceptionInterface When a network-related exception occurs.
     */
    #[Post(self::URL . '/check[/]', name: 'backends.plex.check')]
    public function check(iRequest $request, iHttp $http): iResponse
    {
        $params = DataUtil::fromRequest($request);

        $id = $params->get('id');
        $code = $params->get('code');

        if (empty($id) || empty($code)) {
            return api_error('Missing required parameters.', Status::BAD_REQUEST);
        }

        $req = $http->request(Method::GET, r('https://plex.tv/api/v2/pins/{id}', ['id' => $id]), [
            'headers' => [
                'accept' => 'application/json',
                ...PlexClient::getHeaders(),
            ],
            'query' => ['code' => $code],
        ]);

        if (Status::OK !== Status::from($req->getStatusCode())) {
            $this->logger->error("Request for OAuth PIN check returned with unexpected '{status_code}' status code.", [
                'status_code' => $req->getStatusCode(),
                'parsed' => $req->toArray(false),
                'body' => $req->getContent(false),
            ]);

            return api_error(
                r(
                    text: "Request for OAuth PIN check returned with unexpected '{status_code}' status code.",
                    context: ['status_code' => $req->getStatusCode()],
                ),
                Status::from($req->getStatusCode()),
            );
        }

        return api_response(Status::OK, $req->toArray());
    }
}
