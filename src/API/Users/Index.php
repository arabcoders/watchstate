<?php

declare(strict_types=1);

namespace App\API\Users;

use App\Libs\Attributes\DI\Inject;
use App\Libs\Attributes\Route\Delete;
use App\Libs\Attributes\Route\Get;
use App\Libs\Attributes\Route\Post;
use App\Libs\Attributes\Route\Put;
use App\Libs\DataUtil;
use App\Libs\Enums\Http\Status;
use App\Libs\Exceptions\RuntimeException;
use App\Libs\Mappers\Import\DirectMapper;
use App\Libs\Mappers\ImportInterface as iImport;
use App\Libs\Traits\APITraits;
use JsonException;
use Psr\Http\Message\ResponseInterface as iResponse;
use Psr\Http\Message\ServerRequestInterface as iRequest;
use Psr\Log\LoggerInterface as iLogger;
use Psr\SimpleCache\CacheInterface as iCache;
use Symfony\Component\Yaml\Exception\ExceptionInterface;
use Symfony\Component\Yaml\Yaml;
use Throwable;

final class Index
{
    use APITraits;

    public const string URL = '%{api.prefix}/users';

    private array $cache = [];

    public function __construct(
        #[Inject(DirectMapper::class)]
        private readonly iImport $mapper,
        private readonly iLogger $logger
    ) {
    }

    #[Get(self::URL . '[/]', name: 'users.list')]
    public function users_list(): iResponse
    {
        $users = [];
        $usersContext = getUsersContext($this->mapper, $this->logger);
        foreach ($usersContext as $username => $userContext) {
            $users[] = [
                'user' => $username,
                'backends' => array_keys($userContext->config->getAll())
            ];
        }

        return api_response(Status::OK, ['users' => $users]);
    }

    #[Post(self::URL . '[/]', name: 'users.add')]
    public function user_add(iRequest $request): iResponse
    {
        $params = DataUtil::fromRequest($request);

        if (!($username = $params->get('user', null))) {
            return api_error('Missing required parameter: user', Status::BAD_REQUEST);
        }

        if (true !== isValidName($username)) {
            return api_error('Invalid username format', Status::BAD_REQUEST);
        }

        $username = strtolower($username);

        $users = array_map(fn($fn) => strtolower($fn), array_keys(getUsersContext($this->mapper, $this->logger)));

        if (false !== in_array($username, $users, true)) {
            return api_error(r("User '{user}' already exists", ['user' => $username]), Status::CONFLICT);
        }

        try {
            perUserConfig($username);
        } catch (Throwable $e) {
            $this->logger->error(r('Failed to create user {user}: {error.message}', [
                'user' => $username,
                ...exception_log($e)
            ]));

            return api_error(r('Failed to create user {user}', ['user' => $username]), Status::INTERNAL_SERVER_ERROR);
        }

        return api_response(Status::CREATED);
    }

    #[Delete(self::URL . '/{user}[/]', name: 'users.delete')]
    public function user_delete(string $user, iCache $cache): iResponse
    {
        if (empty($user)) {
            return api_error('Missing required parameter: user', Status::BAD_REQUEST);
        }

        if (true !== isValidName($user)) {
            return api_error('Invalid username format', Status::BAD_REQUEST);
        }

        if ('main' === $user) {
            return api_error(r("User '{user}' cannot be deleted.", ['user' => $user]), Status::FORBIDDEN);
        }

        try {
            getUserContext($user, $this->mapper, $this->logger);
        } catch (RuntimeException $e) {
            return api_error(r("User '{user}' doesn't exist", ['user' => $user]), Status::NOT_FOUND);
        }

        try {
            deleteUserConfig($user, $cache);
        } catch (Throwable $e) {
            $this->logger->error(r('Failed to create user {user}: {error.message}', [
                'user' => $user,
                ...exception_log($e)
            ]));

            return api_error(r('Failed to create user {user}', ['user' => $user]), Status::INTERNAL_SERVER_ERROR);
        }

        return api_response(Status::OK);
    }

    #[Get(self::URL . '/{user}[/]', name: 'users.servers')]
    public function user_servers(string $user): iResponse
    {
        if (empty($user)) {
            return api_error('Missing required parameter: user', Status::BAD_REQUEST);
        }

        if (true !== isValidName($user)) {
            return api_error('Invalid username format', Status::BAD_REQUEST);
        }

        try {
            $userContext = getUserContext($user, $this->mapper, $this->logger);
        } catch (RuntimeException $e) {
            return api_error(r("User '{user}' doesn't exist", ['user' => $user]), Status::NOT_FOUND);
        }

        return api_response(Status::OK, $userContext->config->getAll());
    }

    #[Put(self::URL . '/{user}[/]', name: 'users.servers.update')]
    public function user_servers_update(iRequest $request, string $user): iResponse
    {
        if (empty($user)) {
            return api_error('Missing required parameter: user', Status::BAD_REQUEST);
        }

        if (true !== isValidName($user)) {
            return api_error('Invalid username format', Status::BAD_REQUEST);
        }

        try {
            $userContext = getUserContext($user, $this->mapper, $this->logger);
        } catch (RuntimeException $e) {
            return api_error(r("User '{user}' doesn't exist", ['user' => $user]), Status::NOT_FOUND);
        }

        try {
            $contents = (string)$request->getBody();
            if (true === str_contains($request->getHeaderLine('Content-Type'), 'application/json')) {
                $data = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
            } else {
                $data = Yaml::parse($contents);
            }
        } catch (ExceptionInterface|JsonException $e) {
            return api_error('Failed to parse request body: ' . $e->getMessage(), Status::BAD_REQUEST);
        }

        if (false === is_array($data)) {
            return api_error('Request body must be a JSON object', Status::BAD_REQUEST);
        }

        $validation = validateServersData($data);

        if (false === $validation['valid']) {
            return api_error('Validation failed', Status::BAD_REQUEST, body: [
                'errors' => $validation['errors']
            ]);
        }

        $userContext->config->replaceAll($data)->persist();

        return api_response(Status::OK, $userContext->config->getAll());
    }
}
