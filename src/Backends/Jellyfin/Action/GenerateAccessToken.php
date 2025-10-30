<?php

declare(strict_types=1);

namespace App\Backends\Jellyfin\Action;

use App\Backends\Common\CommonTrait;
use App\Backends\Common\Context;
use App\Backends\Common\Error;
use App\Backends\Common\Levels;
use App\Backends\Common\Response;
use App\Libs\Config;
use App\Libs\Enums\Http\Method;
use App\Libs\Enums\Http\Status;
use App\Libs\Options;
use JsonException;
use Psr\Log\LoggerInterface as iLogger;
use SensitiveParameter;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface as iHttp;

/**
 * Class Generate Access token.
 *
 * This class is responsible for generating the access token for Jellyfin API.
 */
class GenerateAccessToken
{
    use CommonTrait;

    /**
     * @var string Action name.
     */
    protected string $action = 'jellyfin.generateAccessToken';

    /**
     * Class Constructor.
     *
     * @param iHttp $http The HTTP client instance.
     * @param iLogger $logger The logger instance.
     */
    public function __construct(protected readonly iHttp $http, protected readonly iLogger $logger)
    {
    }

    /**
     * Generate Access Token.
     *
     * @param Context $context The context instance.
     * @param string|int $identifier
     * @param string $password
     * @param array $opts Additional options.
     *
     * @return Response The response received.
     */
    public function __invoke(Context $context, string|int $identifier, string $password, array $opts = []): Response
    {
        return $this->tryResponse(
            context: $context,
            fn: fn() => $this->generateToken($context, $identifier, $password, $opts),
            action: $this->action
        );
    }

    /**
     * Generate Access Token.
     *
     * @param Context $context The context instance.
     * @param string|int $identifier
     * @param string $password
     * @param array $opts Additional options.
     *
     * @return Response The response received.
     * @throws JsonException When the response is not a valid JSON.
     * @throws ExceptionInterface When the request fails.
     */
    private function generateToken(
        Context $context,
        string|int $identifier,
        #[SensitiveParameter] string $password,
        array $opts = []
    ): Response {
        $url = $context->backendUrl->withPath('/Users/AuthenticateByName');

        $logContext = [
            'action' => $this->action,
            'client' => $context->clientName,
            'backend' => $context->backendName,
            'user' => $context->userContext->name,
            'url' => (string)$url,
            'username' => (string)$identifier,
        ];

        $this->logger->debug(
            message: "{action}: Requesting '{client}: {user}@{backend}' to generate access token for '{username}'.",
            context: $logContext
        );

        $response = $this->http->request(Method::POST, (string)$url, [
            'json' => [
                'Username' => (string)$identifier,
                'Pw' => $password,
            ],
            'headers' => [
                'Accept' => 'application/json',
                'Authorization' => r('{Agent} Client="{app}", Device="{os}", DeviceId="{id}", Version="{version}"', [
                    'Agent' => 'Emby' == $context->clientName ? 'Emby' : 'MediaBrowser',
                    'app' => Config::get('name') . '/' . $context->clientName,
                    'os' => PHP_OS,
                    'id' => md5(Config::get('name') . '/' . $context->clientName),
                    'version' => getAppVersion(),
                ]),
            ],
            ...ag($context->backendHeaders, 'client', []),
        ]);

        if (Status::OK !== Status::tryFrom($response->getStatusCode())) {
            return new Response(
                status: false,
                error: new Error(
                    message: "{action}: Request for '{client}: {user}@{backend}' to generate access for '{username}' token returned with unexpected '{status_code}' status code. {body}",
                    context: [
                        ...$logContext,
                        'status_code' => $response->getStatusCode(),
                        'response' => [
                            'body' => $response->getContent(false),
                        ],
                    ],
                    level: Levels::ERROR
                ),
            );
        }

        $json = json_decode(
            json: $response->getContent(),
            associative: true,
            flags: JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_IGNORE
        );

        if ($context->trace) {
            $this->logger->debug(
                message: "{action}: Parsing '{client}: {user}@{backend}' - '{username}' access token response payload.",
                context: [
                    ...$logContext,
                    'trace' => $json,
                ]
            );
        }

        $info = [
            'user' => ag($json, 'User.Id'),
            'identifier' => ag($json, 'ServerId'),
            'accesstoken' => ag($json, 'AccessToken'),
            'username' => ag($json, 'User.Name'),
        ];

        if (true === ag_exists($opts, Options::RAW_RESPONSE)) {
            $info[Options::RAW_RESPONSE] = $json;
        }

        return new Response(status: true, response: $info);
    }
}
