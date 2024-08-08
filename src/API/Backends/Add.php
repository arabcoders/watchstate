<?php

declare(strict_types=1);

namespace App\API\Backends;

use App\Backends\Common\Cache as BackendCache;
use App\Backends\Common\ClientInterface as iClient;
use App\Backends\Common\Context;
use App\Libs\Attributes\Route\Post;
use App\Libs\Config;
use App\Libs\ConfigFile;
use App\Libs\Container;
use App\Libs\DataUtil;
use App\Libs\Enums\Http\Status;
use App\Libs\Exceptions\Backends\InvalidContextException;
use App\Libs\Exceptions\RuntimeException;
use App\Libs\Traits\APITraits;
use App\Libs\Uri;
use Psr\Http\Message\ResponseInterface as iResponse;
use Psr\Http\Message\ServerRequestInterface as iRequest;
use Random\RandomException;

final class Add
{
    use APITraits;

    /**
     * @throws RandomException
     */
    #[Post(Index::URL . '[/]', name: 'backends.add')]
    public function BackendAdd(iRequest $request): iResponse
    {
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
            return api_error('Invalid name was given.', Status::BAD_REQUEST);
        }

        $backend = $this->getBackends(name: $name);

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
            return api_error(r("Unexpected client type '{type}' was given.", ['type' => $type]),
                Status::BAD_REQUEST);
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
                backendId: $config->get('uuid', null),
                backendToken: $token,
                backendUser: $config->get('user', null),
                options: $config->get('options', []),
            );

            if (false === $instance->validateContext($context)) {
                return api_error('Context information validation failed.', Status::BAD_REQUEST);
            }

            if (!$config->get('uuid')) {
                $config = $config->with('uuid', $instance->withContext($context)->getIdentifier());
            }

            if (!$config->has('webhook.token')) {
                $config = $config->with('webhook.token', bin2hex(random_bytes(Config::get('webhook.tokenLength'))));
            }

            ConfigFile::open(Config::get('backends_file'), 'yaml')
                ->set($name, $config->getAll())
                ->persist();
        } catch (InvalidContextException $e) {
            return api_error($e->getMessage(), Status::BAD_REQUEST);
        }

        $data = $this->getBackends(name: $name);
        $data = array_pop($data);

        return api_response(Status::CREATED, $data);
    }

    private function fromRequest(string $type, iRequest $request, iClient $client): array
    {
        $data = DataUtil::fromArray($request->getParsedBody());

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
            'webhook' => [
                'token' => $data->get('webhook.token'),
                'match' => [
                    'user' => (bool)$data->get('webhook.match.user', false),
                    'uuid' => (bool)$data->get('webhook.match.uuid', false),
                ],
            ],
            'options' => [],
        ];

        foreach ($data->get('options', []) as $key => $value) {
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
