<?php

declare(strict_types=1);

namespace App\API\Identities;

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
    public const string URL = '%{api.prefix}/identities';
    private const string IDENTITY_PARAM = '{identity:(?!provision(?:/|$))[a-z_0-9]+}';

    public function __construct(
        #[Inject(DirectMapper::class)]
        private readonly iImport $mapper,
        private readonly iLogger $logger,
    ) {}

    #[Get(self::URL . '[/]', name: 'identities.list')]
    public function identities_list(): iResponse
    {
        $identities = [];
        $identitiesContext = get_users_context($this->mapper, $this->logger);

        foreach ($identitiesContext as $identityName => $identityContext) {
            $identities[] = [
                'identity' => $identityName,
                'backends' => array_keys($identityContext->config->getAll()),
            ];
        }

        return api_response(Status::OK, ['identities' => $identities]);
    }

    #[Post(self::URL . '[/]', name: 'identities.add')]
    public function identity_add(iRequest $request): iResponse
    {
        $params = DataUtil::fromRequest($request);

        if (!($identityName = $params->get('identity', null))) {
            return api_error('Missing required parameter: identity', Status::BAD_REQUEST);
        }

        if (true !== is_valid_name($identityName)) {
            return api_error('Invalid identity format', Status::BAD_REQUEST);
        }

        $identityName = strtolower($identityName);

        $identities = array_map(strtolower(...), array_keys(get_users_context($this->mapper, $this->logger)));

        if (false !== in_array($identityName, $identities, true)) {
            return api_error(r("Identity '{identity}' already exists", ['identity' => $identityName]), Status::CONFLICT);
        }

        try {
            per_user_config($identityName);
            ensure_migration(get_user_db($identityName));
        } catch (Throwable $e) {
            $this->logger->error("Failed to create identity '{identity}'.", [
                'event_name' => 'identity.create.failed',
                'subsystem' => 'identity',
                'operation' => 'create',
                'outcome' => 'failed',
                'identity' => $identityName,
                ...exception_log($e),
            ]);

            return api_error(
                r('Failed to create identity {identity}', ['identity' => $identityName]),
                Status::INTERNAL_SERVER_ERROR,
            );
        }

        return api_response(Status::CREATED);
    }

    #[Delete(self::URL . '/' . self::IDENTITY_PARAM . '[/]', name: 'identities.delete')]
    public function identity_delete(string $identity, iCache $cache): iResponse
    {
        if (empty($identity)) {
            return api_error('Missing required parameter: identity', Status::BAD_REQUEST);
        }

        if (true !== is_valid_name($identity)) {
            return api_error('Invalid identity format', Status::BAD_REQUEST);
        }

        if ('main' === $identity) {
            return api_error(r("Identity '{identity}' cannot be deleted.", ['identity' => $identity]), Status::FORBIDDEN);
        }

        try {
            get_user_context($identity, $this->mapper, $this->logger);
        } catch (RuntimeException $e) {
            return api_error(r("Identity '{identity}' doesn't exist", ['identity' => $identity]), Status::NOT_FOUND);
        }

        try {
            delete_user_config($identity, $cache);
        } catch (Throwable $e) {
            $this->logger->error("Failed to delete identity '{identity}'.", [
                'event_name' => 'identity.delete.failed',
                'subsystem' => 'identity',
                'operation' => 'delete',
                'outcome' => 'failed',
                'identity' => $identity,
                ...exception_log($e),
            ]);

            return api_error(
                r('Failed to delete identity {identity}', ['identity' => $identity]),
                Status::INTERNAL_SERVER_ERROR,
            );
        }

        return api_response(Status::OK);
    }

    #[Get(self::URL . '/' . self::IDENTITY_PARAM . '[/]', name: 'identities.backends')]
    public function identity_backends(string $identity): iResponse
    {
        if (empty($identity)) {
            return api_error('Missing required parameter: identity', Status::BAD_REQUEST);
        }

        if (true !== is_valid_name($identity)) {
            return api_error('Invalid identity format', Status::BAD_REQUEST);
        }

        try {
            $identityContext = get_user_context($identity, $this->mapper, $this->logger);
        } catch (RuntimeException $e) {
            return api_error(r("Identity '{identity}' doesn't exist", ['identity' => $identity]), Status::NOT_FOUND);
        }

        return api_response(Status::OK, $identityContext->config->getAll());
    }

    #[Put(self::URL . '/' . self::IDENTITY_PARAM . '[/]', name: 'identities.backends.update')]
    public function identity_backends_update(iRequest $request, string $identity): iResponse
    {
        if (empty($identity)) {
            return api_error('Missing required parameter: identity', Status::BAD_REQUEST);
        }

        if (true !== is_valid_name($identity)) {
            return api_error('Invalid identity format', Status::BAD_REQUEST);
        }

        try {
            $identityContext = get_user_context($identity, $this->mapper, $this->logger);
        } catch (RuntimeException $e) {
            return api_error(r("Identity '{identity}' doesn't exist", ['identity' => $identity]), Status::NOT_FOUND);
        }

        try {
            $contents = (string) $request->getBody();
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

        $validation = validate_servers_data($data);

        if (false === $validation['valid']) {
            return api_error('Validation failed', Status::BAD_REQUEST, body: [
                'errors' => $validation['errors'],
            ]);
        }

        $identityContext->config->replaceAll($data)->persist();

        return api_response(Status::OK, $identityContext->config->getAll());
    }
}
