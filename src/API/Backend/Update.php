<?php

declare(strict_types=1);

namespace App\API\Backend;

use App\Backends\Common\Cache as BackendCache;
use App\Backends\Common\ClientInterface as iClient;
use App\Backends\Common\Context;
use App\Libs\Attributes\Route\Patch;
use App\Libs\Attributes\Route\Put;
use App\Libs\Container;
use App\Libs\DataUtil;
use App\Libs\Enums\Http\Status;
use App\Libs\Exceptions\Backends\InvalidContextException;
use App\Libs\Exceptions\RuntimeException;
use App\Libs\Exceptions\ValidationException;
use App\Libs\Mappers\ImportInterface as iImport;
use App\Libs\Options;
use App\Libs\Traits\APITraits;
use App\Libs\Uri;
use JsonException;
use Psr\Http\Message\ResponseInterface as iResponse;
use Psr\Http\Message\ServerRequestInterface as iRequest;
use Psr\Log\LoggerInterface as iLogger;

final class Update
{
    use APITraits;

    private const array IMMUTABLE_KEYS = ['name', 'type', 'options', 'import', 'export'];

    public function __construct(private readonly iImport $mapper, private readonly iLogger $logger)
    {
    }

    #[Put(Index::URL . '/{name:backend}[/]', name: 'backend.update')]
    public function update(iRequest $request, string $name): iResponse
    {
        try {
            $userContext = $this->getUserContext($request, $this->mapper, $this->logger);
        } catch (RuntimeException $e) {
            return api_error($e->getMessage(), Status::NOT_FOUND);
        }

        if (false === $userContext->config->has($name)) {
            return api_error(r("Backend '{name}' not found.", ['name' => $name]), Status::NOT_FOUND);
        }

        try {
            $client = $this->getClient($name, userContext: $userContext);

            $config = DataUtil::fromArray($this->fromRequest($userContext->config->get($name), $request, $client));

            $context = new Context(
                clientName: $userContext->config->get("{$name}.type"),
                backendName: $name,
                backendUrl: new Uri($config->get('url')),
                cache: Container::get(BackendCache::class)->with(adapter: $userContext->cache),
                userContext: $userContext,
                logger: Container::get(iLogger::class),
                backendId: $config->get('uuid', null),
                backendToken: $userContext->config->get("{$name}.token", null),
                backendUser: $config->get('user', null),
                options: $config->get('options', []),
            );

            if (false === $client->validateContext($context)) {
                return api_error('Context information validation failed.', Status::BAD_REQUEST);
            }
            $userContext->config->set($name, $config->getAll())
                ->addFilter('removed.keys', function (array $data): array {
                    $removed = include __DIR__ . '/../../../config/removed.keys.php';
                    foreach (ag($removed, 'backend', []) as $key) {
                        foreach ($data as &$v) {
                            if (false === is_array($v)) {
                                continue;
                            }
                            if (false === ag_exists($v, $key)) {
                                continue;
                            }
                            $v = ag_delete($v, $key);
                        }
                    }
                    return $data;
                })->persist();

            // -- sanity check.
            if (true === (bool)$userContext->config->get("{$name}.import.enabled", false)) {
                if ($userContext->config->has("{$name}.options." . Options::IMPORT_METADATA_ONLY)) {
                    $userContext->config->delete("{$name}.options." . Options::IMPORT_METADATA_ONLY);
                }
            }

            $userContext->config->persist();

            $backend = $this->getBackends(name: $name, userContext: $userContext);

            if (empty($backend)) {
                return api_error(r("Backend '{name}' not found.", ['name' => $name]), Status::NOT_FOUND);
            }

            $backend = array_pop($backend);

            return api_response(Status::OK, $backend);
        } catch (InvalidContextException|ValidationException $e) {
            return api_error($e->getMessage(), Status::BAD_REQUEST);
        }
    }

    #[Patch(Index::URL . '/{name:backend}[/]', name: 'backend.patch')]
    public function patchUpdate(iRequest $request, string $name): iResponse
    {
        try {
            $userContext = $this->getUserContext($request, $this->mapper, $this->logger);
        } catch (RuntimeException $e) {
            return api_error($e->getMessage(), Status::NOT_FOUND);
        }

        if (false === $userContext->config->has($name)) {
            return api_error(r("Backend '{name}' not found.", ['name' => $name]), Status::NOT_FOUND);
        }

        try {
            $data = json_decode((string)$request->getBody(), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            return api_error(r('Invalid JSON data. {error}', ['error' => $e->getMessage()]), Status::BAD_REQUEST);
        }

        $updates = [];
        $removedKeys = ag(include __DIR__ . '/../../../config/removed.keys.php', 'backend', []);

        foreach ($data as $update) {
            $value = ag($update, 'value');

            if (null === ($key = ag($update, 'key'))) {
                return api_error('No key to update was present.', Status::BAD_REQUEST);
            }

            $spec = getServerColumnSpec($key);

            if (empty($spec)) {
                return api_error(r("Invalid key '{key}' was given.", ['key' => $key]), Status::BAD_REQUEST);
            }

            settype($value, ag($spec, 'type', 'string'));

            if (true === ag_exists($spec, 'validate')) {
                try {
                    $value = $spec['validate']($value);
                } catch (ValidationException $e) {
                    return api_error(r("Value validation for '{key}' failed. {error}", [
                        'key' => $key,
                        'error' => $e->getMessage()
                    ]), Status::BAD_REQUEST);
                }
            }

            if (in_array($key, [...$removedKeys, ...self::IMMUTABLE_KEYS], true)) {
                return api_error(r('Key {key} is immutable.', ['key' => $key]), Status::BAD_REQUEST);
            }

            $updates["{$name}.{$key}"] = $value;
        }

        foreach ($updates as $key => $value) {
            $userContext->config->set($key, $value);
        }

        # -- sanity check.
        if (true === (bool)$userContext->config->get("{$name}.import.enabled", false)) {
            if ($userContext->config->has("{$name}.options." . Options::IMPORT_METADATA_ONLY)) {
                $userContext->config->delete("{$name}.options." . Options::IMPORT_METADATA_ONLY);
            }
        }

        $userContext->config->persist();

        $backend = $this->getBackends(name: $name, userContext: $userContext);

        if (empty($backend)) {
            return api_error(r("Backend '{name}' not found.", ['name' => $name]), Status::NOT_FOUND);
        }

        $backend = array_pop($backend);

        return api_response(Status::OK, $backend);
    }

    private function fromRequest(array $config, iRequest $request, iClient $client): array
    {
        $data = DataUtil::fromArray(
            array_map(fn($v) => false === is_string($v) ? $v : trim($v), $request->getParsedBody())
        );

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
        ];

        foreach ($data->get('options', []) as $key => $value) {
            $key = "options.{$key}";
            $spec = getServerColumnSpec($key);

            if (empty($spec) || null === $value) {
                continue;
            }

            settype($value, ag($spec, 'type', 'string'));

            if (true === ag_exists($spec, 'validate')) {
                try {
                    $value = $spec['validate']($value);
                } catch (ValidationException $e) {
                    throw new ValidationException(r("Value validation for '{key}' failed. {error}", [
                        'key' => $key,
                        'error' => $e->getMessage()
                    ]), $e->getCode(), $e);
                }
            }

            $newData = ag_set($newData, $key, $value);
        }

        return deepArrayMerge([$config, $client->fromRequest($newData, $request)]);
    }
}
