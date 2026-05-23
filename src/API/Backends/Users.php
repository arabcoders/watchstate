<?php

declare(strict_types=1);

namespace App\API\Backends;

use App\Libs\Attributes\Route\Route;
use App\Libs\DataUtil;
use App\Libs\Enums\Http\Status;
use App\Libs\Exceptions\AppExceptionInterface;
use App\Libs\Exceptions\InvalidArgumentException;
use App\Libs\Options;
use App\Libs\Traits\APITraits;
use Psr\Http\Message\ResponseInterface as iResponse;
use Psr\Http\Message\ServerRequestInterface as iRequest;
use Psr\Log\LoggerInterface as iLogger;
use Throwable;

final class Users
{
    use APITraits;

    #[Route(['GET', 'POST'], Index::URL . '/users/{type}[/]', name: 'backends.get.backend.users')]
    public function __invoke(iRequest $request, string $type, iLogger $logger): iResponse
    {
        $params = DataUtil::fromRequest($request, true);

        try {
            $client = $this->getBasicClient($type, $params);
        } catch (InvalidArgumentException $e) {
            $logger->error("Failed to build backend users request for '{backend_type}'.", [
                'event_name' => 'backend.context.users_failed',
                'subsystem' => 'backend.context',
                'operation' => 'users_list',
                'outcome' => 'failed',
                'backend_type' => $type,
                ...exception_log($e),
            ]);

            return api_error($e->getMessage(), Status::BAD_REQUEST);
        }

        $users = $opts = [];

        if (true === (bool) $params->get(Options::GET_TOKENS, false)) {
            $opts[Options::GET_TOKENS] = true;
        }

        if (null !== ($user = $params->get(Options::TARGET_USER, null))) {
            $opts[Options::TARGET_USER] = $user;
        }

        if (true === (bool) $params->get('no_cache', false)) {
            $opts[Options::NO_CACHE] = true;
        }

        try {
            foreach ($client->getUsersList($opts) as $user) {
                $users[] = $user;
            }
        } catch (Throwable $e) {
            $errorContext = $e instanceof AppExceptionInterface && $e->hasContext() ? $e->getContext() : [];

            $logger->error("Failed to fetch backend users for '{backend_type}'.", [
                'event_name' => 'backend.context.users_failed',
                'subsystem' => 'backend.context',
                'operation' => 'users_list',
                'outcome' => 'failed',
                'backend_type' => $type,
                'target_user' => $opts[Options::TARGET_USER] ?? null,
                'get_tokens' => (bool) ($opts[Options::GET_TOKENS] ?? false),
                'no_cache' => (bool) ($opts[Options::NO_CACHE] ?? false),
                ...$errorContext,
                ...exception_log($e),
            ]);
            return api_error($e->getMessage(), Status::INTERNAL_SERVER_ERROR);
        }

        return api_response(Status::OK, $users);
    }
}
