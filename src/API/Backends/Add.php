<?php

declare(strict_types=1);

namespace App\API\Backends;

use App\Backends\Common\Cache as BackendCache;
use App\Backends\Common\ClientInterface as iClient;
use App\Backends\Common\Context;
use App\Libs\Attributes\Route\Post;
use App\Libs\Config;
use App\Libs\Container;
use App\Libs\DataUtil;
use App\Libs\Enums\Http\Status;
use App\Libs\Exceptions\Backends\InvalidContextException;
use App\Libs\Exceptions\RuntimeException;
use App\Libs\Mappers\ImportInterface as iImport;
use App\Libs\Traits\APITraits;
use App\Libs\Uri;
use Psr\Http\Message\ResponseInterface as iResponse;
use Psr\Http\Message\ServerRequestInterface as iRequest;
use Psr\Log\LoggerInterface as iLogger;

final class Add
{
    use APITraits;

    #[Post(Index::URL . '[/]', name: 'backends.add')]
    public function BackendAdd(iRequest $request, iImport $mapper, iLogger $logger): iResponse
    {
        try {
            $userContext = $this->getUserContext($request, $mapper, $logger);
        } catch (RuntimeException $e) {
            return api_error($e->getMessage(), Status::NOT_FOUND);
        }

        $requestData = $request->getParsedBody();

        if (!is_array($requestData)) {
            return api_error('Invalid request data.', Status::BAD_REQUEST);
        }

        $data = DataUtil::fromArray($request->getParsedBody());

        if (null === ($type = $data->get('type'))) {
            return api_error('No type was given.', Status::BAD_REQUEST);
        }

        $type = strtolower($type);

        if (null === ($name = $data->get('name'))) {
            return api_error('No name was given.', Status::BAD_REQUEST);
        }

        if (false === isValidName($name)) {
            return api_error(
                'Invalid name was given. Backend name must only contain [lowercase a-z, 0-9, _].',
                Status::BAD_REQUEST
            );
        }

        $backend = $this->getBackends(name: $name, userContext: $userContext);

        if (!empty($backend)) {
            return api_error(r("Backend '{backend}' already exists.", [
                'backend' => $name
            ]), Status::CONFLICT);
        }

        if (null === ($url = $data->get('url'))) {
            return api_error('No url was given.', Status::BAD_REQUEST);
        }

        if (false === isValidUrl($url)) {
            return api_error('Invalid url was given.', Status::BAD_REQUEST);
        }

        if (null === ($token = $data->get('token'))) {
            return api_error('No access token was given.', Status::BAD_REQUEST);
        }

        if (null === ($class = Config::get("supported.{$type}", null))) {
            return api_error(r("Unexpected client type '{type}' was given.", ['type' => $type]), Status::BAD_REQUEST);
        }

        $instance = Container::getNew($class);
        assert($instance instanceof iClient, new RuntimeException('Invalid client class.'));

        try {
            $config = DataUtil::fromArray($this->fromRequest($type, $request, $instance));

            $context = new Context(
                clientName: $type,
                backendName: $name,
                backendUrl: new Uri($config->get('url')),
                cache: Container::get(BackendCache::class),
                userContext: $userContext,
                backendId: $config->get('uuid', null),
                backendToken: $token,
                backendUser: $config->get('user', null),
                options: $config->get('options', []),
            );

            set_time_limit(60 * 10);

            if (false === $instance->validateContext($context)) {
                return api_error('Context information validation failed.', Status::BAD_REQUEST);
            }

            if (!$config->get('uuid')) {
                $config = $config->with('uuid', $instance->withContext($context)->getIdentifier());
            }

            $userContext->config->set($name, $config->getAll())->persist();
        } catch (InvalidContextException $e) {
            return api_error($e->getMessage(), Status::BAD_REQUEST);
        }

        $data = $this->getBackends(name: $name, userContext: $userContext);
        $data = array_pop($data);

        return api_response(Status::CREATED, $data);
    }

    private function fromRequest(string $type, iRequest $request, iClient $client): array
    {
        $data = DataUtil::fromArray(
            array_map(fn($v) => false === is_string($v) ? $v : trim($v), $request->getParsedBody())
        );

        $config = [
            'type' => $type,
            'url' => $data->get('url'),
            'token' => $data->get('token'),
            'user' => $data->get('user'),
            'uuid' => $data->get('uuid'),
            'export' => [
                'enabled' => (bool)$data->get('export.enabled', false),
                'lastSync' => (int)$data->get('export.lastSync', null),
            ],
            'import' => [
                'enabled' => (bool)$data->get('import.enabled', false),
                'lastSync' => (int)$data->get('import.lastSync', null),
            ],
            'options' => [],
        ];

        foreach (flatArray($data->get('options', [])) as $key => $value) {
            $key = "options.{$key}";
            $spec = getServerColumnSpec($key);

            if (empty($spec) || null === $value) {
                continue;
            }

            $config = ag_set($config, $key, $value);
        }
        return $client->fromRequest($config, $request);
    }
}
