<?php

declare(strict_types=1);

namespace App\API\Backend;

use App\Backends\Common\ClientInterface as iClient;
use App\Libs\Attributes\Route\Patch;
use App\Libs\Attributes\Route\Put;
use App\Libs\Config;
use App\Libs\ConfigFile;
use App\Libs\DataUtil;
use App\Libs\HTTP_STATUS;
use App\Libs\Options;
use App\Libs\Traits\APITraits;
use JsonException;
use Psr\Http\Message\ResponseInterface as iResponse;
use Psr\Http\Message\ServerRequestInterface as iRequest;

final class Update
{
    use APITraits;

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

        $this->backendFile->set(
            $name,
            $this->fromRequest($this->backendFile->get($name), $request, $this->getClient($name))
        )->persist();

        $backend = $this->getBackends(name: $name);

        if (empty($backend)) {
            return api_error(r("Backend '{name}' not found.", ['name' => $name]), HTTP_STATUS::HTTP_NOT_FOUND);
        }

        $backend = array_pop($backend);

        return api_response(HTTP_STATUS::HTTP_OK, $backend);
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

        $spec = require __DIR__ . '/../../../config/backend.spec.php';

        foreach ($data as $update) {
            $key = ag($update, 'key');
            $value = ag($update, 'value');

            if (null === $key) {
                return api_error('No key to update was present.', HTTP_STATUS::HTTP_BAD_REQUEST);
            }

            if (false === in_array($key, $spec, true)) {
                return api_error(r('Invalid key to update: {key}', ['key' => $key]), HTTP_STATUS::HTTP_BAD_REQUEST);
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

        $optionals = [
            Options::DUMP_PAYLOAD => 'bool',
            Options::LIBRARY_SEGMENT => 'int',
            Options::IGNORE => 'string',
        ];

        foreach ($optionals as $key => $type) {
            if (null !== ($value = $data->get('options.' . $key))) {
                settype($value, $type);
                $newData = ag_set($newData, "options.{$key}", $value);
            }
        }

        return deepArrayMerge([$config, $client->fromRequest($newData, $request)]);
    }
}
