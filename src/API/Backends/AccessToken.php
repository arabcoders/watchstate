<?php

declare(strict_types=1);

namespace App\API\Backends;

use App\Libs\Attributes\Route\Post;
use App\Libs\DataUtil;
use App\Libs\Enums\Http\Status;
use App\Libs\Exceptions\AppExceptionInterface;
use App\Libs\Exceptions\InvalidArgumentException;
use App\Libs\Traits\APITraits;
use Psr\Http\Message\ResponseInterface as iResponse;
use Psr\Http\Message\ServerRequestInterface as iRequest;
use Psr\Log\LoggerInterface as iLogger;
use Throwable;

final class AccessToken
{
    use APITraits;

    public const string URL = Index::URL . '/accesstoken';

    #[Post(self::URL . '/{type}[/]', name: 'backends.get.accesstoken')]
    public function __invoke(iRequest $request, iLogger $logger, array $args = []): iResponse
    {
        if (null === ($type = ag($args, 'type'))) {
            return api_error('Invalid value for type path parameter.', Status::BAD_REQUEST);
        }

        $params = DataUtil::fromRequest($request);
        $username = $params->get('username');
        $password = $params->get('password');

        if (empty($username) || empty($password)) {
            return api_error('Invalid username or password.', Status::BAD_REQUEST);
        }

        if (false === in_array($type, ['jellyfin', 'emby'], true)) {
            return api_error('Access token endpoint only supported on jellyfin, emby.', Status::BAD_REQUEST);
        }

        try {
            $client = $this->getBasicClient($type, $params->with('token', 'accesstoken_request'));
        } catch (InvalidArgumentException $e) {
            $logger->error("Failed to build backend access-token request for '{backend_type}'.", [
                'event_name' => 'backend.context.access_token_failed',
                'subsystem' => 'backend.context',
                'operation' => 'access_token',
                'outcome' => 'failed',
                'backend_type' => $type,
                ...exception_log($e),
            ]);

            return api_error($e->getMessage(), Status::BAD_REQUEST);
        }

        try {
            $info = $client->generateAccessToken($username, $password);
        } catch (Throwable $e) {
            $errorContext = $e instanceof AppExceptionInterface && $e->hasContext() ? $e->getContext() : [];

            $logger->error("Failed to generate backend access token for '{backend_type}'.", [
                'event_name' => 'backend.context.access_token_failed',
                'subsystem' => 'backend.context',
                'operation' => 'access_token',
                'outcome' => 'failed',
                'backend_type' => $type,
                ...$errorContext,
                ...exception_log($e),
            ]);

            return api_error($e->getMessage(), Status::INTERNAL_SERVER_ERROR);
        }

        return api_response(Status::OK, $info);
    }
}
