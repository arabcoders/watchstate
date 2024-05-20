<?php

declare(strict_types=1);

namespace App\API\Backend;

use App\Backends\Common\Cache as BackendCache;
use App\Backends\Common\ClientInterface as iClient;
use App\Backends\Common\Context;
use App\Libs\Attributes\Route\Patch;
use App\Libs\Attributes\Route\Put;
use App\Libs\Config;
use App\Libs\ConfigFile;
use App\Libs\Container;
use App\Libs\DataUtil;
use App\Libs\Exceptions\Backends\InvalidContextException;
use App\Libs\HTTP_STATUS;
use App\Libs\Traits\APITraits;
use App\Libs\Uri;
use JsonException;
use Psr\Http\Message\ResponseInterface as iResponse;
use Psr\Http\Message\ServerRequestInterface as iRequest;

final class Update
{
    use APITraits;

    private const array IMMUTABLE_KEYS = [
        'name',
        'type',
        'options',
        'webhook',
        'webhook.match',
        'import',
        'export',
    ];

    private ConfigFile $backendFile;

    public function __construct()
    {
        $this->backendFile = ConfigFile::open(Config::get('backends_file'), 'yaml', autoCreate: true);
    }

    #[Put(Index::URL . '/{name:backend}[/]', name: 'backend.update')]
    public function update(iRequest $request, array $args = []): iResponse
    {
        if (null === ($name = ag($args, 'name'))) {
            return api_error('Invalid value for name path parameter.', HTTP_STATUS::HTTP_BAD_REQUEST);
        }

        if (false === $this->backendFile->has($name)) {
            return api_error(r("Backend '{name}' not found.", ['name' => $name]), HTTP_STATUS::HTTP_NOT_FOUND);
        }

        try {
            $client = $this->getClient($name);

            $config = DataUtil::fromArray($this->fromRequest($this->backendFile->get($name), $request, $client));

            $context = new Context(
                clientName: $this->backendFile->get("{$name}.type"),
                backendName: $name,
                backendUrl: new Uri($config->get('url')),
                cache: Container::get(BackendCache::class),
                backendId: $config->get('uuid', null),
                backendToken: $this->backendFile->get("{$name}.token", null),
                backendUser: $config->get('user', null),
                options: $config->get('options', []),
            );

            if (false === $client->validateContext($context)) {
                return api_error('Context information validation failed.', HTTP_STATUS::HTTP_BAD_REQUEST);
            }

            $this->backendFile->set($name, $config->getAll())->persist();

            $backend = $this->getBackends(name: $name);

            if (empty($backend)) {
                return api_error(r("Backend '{name}' not found.", ['name' => $name]), HTTP_STATUS::HTTP_NOT_FOUND);
            }

            $backend = array_pop($backend);

            return api_response(HTTP_STATUS::HTTP_OK, $backend);
        } catch (InvalidContextException $e) {
            return api_error($e->getMessage(), HTTP_STATUS::HTTP_BAD_REQUEST);
        }
    }

    #[Patch(Index::URL . '/{name:backend}[/]', name: 'backend.patch')]
    public function patchUpdate(iRequest $request, array $args = []): iResponse
    {
        if (null === ($name = ag($args, 'name'))) {
            return api_error('Invalid value for name path parameter.', HTTP_STATUS::HTTP_BAD_REQUEST);
        }

        if (false === $this->backendFile->has($name)) {
            return api_error(r("Backend '{name}' not found.", ['name' => $name]), HTTP_STATUS::HTTP_NOT_FOUND);
        }

        try {
            $data = json_decode((string)$request->getBody(), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            return api_error(r('Invalid JSON data. {error}', ['error' => $e->getMessage()]),
                HTTP_STATUS::HTTP_BAD_REQUEST);
        }

        foreach ($data as $update) {
            $value = ag($update, 'value');

            if (null === ($key = ag($update, 'key'))) {
                return api_error('No key to update was present.', HTTP_STATUS::HTTP_BAD_REQUEST);
            }

            $spec = getServerColumnSpec($key);

            if (empty($spec)) {
                return api_error(r('Invalid key to update: {key}', ['key' => $key]), HTTP_STATUS::HTTP_BAD_REQUEST);
            }

            if (in_array($key, self::IMMUTABLE_KEYS, true)) {
                return api_error(r('Key {key} is immutable.', ['key' => $key]), HTTP_STATUS::HTTP_BAD_REQUEST);
            }

            $this->backendFile->set("{$name}.{$key}", $value);
        }

        $this->backendFile->persist();

        $backend = $this->getBackends(name: $name);

        if (empty($backend)) {
            return api_error(r("Backend '{name}' not found.", ['name' => $name]), HTTP_STATUS::HTTP_NOT_FOUND);
        }

        $backend = array_pop($backend);

        return api_response(HTTP_STATUS::HTTP_OK, $backend);
    }

    private function fromRequest(array $config, iRequest $request, iClient $client): array
    {
        $data = DataUtil::fromArray($request->getParsedBody());

        $newData = [
            'url' => $data->get('url'),
            'token' => $data->get('token'),
            'user' => $data->get('user'),
            'uuid' => $data->get('uuid'),
            'export' => [
                'enabled' => (bool)$data->get('export.enabled', false),
            ],
            'import' => [
                'enabled' => (bool)$data->get('import.enabled', false),
            ],
            'webhook' => [
                'match' => [
                    'user' => (bool)$data->get('webhook.match.user'),
                    'uuid' => (bool)$data->get('webhook.match.uuid'),
                ],
            ],
        ];

        foreach ($data->get('options', []) as $key => $value) {
            $key = "options.{$key}";
            $spec = getServerColumnSpec($key);

            if (empty($spec) || null === $value) {
                continue;
            }

            $newData = ag_set($newData, $key, $value);
        }

        return deepArrayMerge([$config, $client->fromRequest($newData, $request)]);
    }
}
