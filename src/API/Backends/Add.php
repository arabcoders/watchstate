<?php

declare(strict_types=1);

namespace App\API\Backends;

use App\Backends\Common\Cache as BackendCache;
use App\Backends\Common\ClientInterface;
use App\Backends\Common\Context;
use App\Libs\Attributes\Route\Post;
use App\Libs\Config;
use App\Libs\ConfigFile;
use App\Libs\Container;
use App\Libs\DataUtil;
use App\Libs\Exceptions\Backends\InvalidContextException;
use App\Libs\Exceptions\RuntimeException;
use App\Libs\HTTP_STATUS;
use App\Libs\Options;
use App\Libs\Traits\APITraits;
use App\Libs\Uri;
use Psr\Http\Message\ResponseInterface as iResponse;
use Psr\Http\Message\ServerRequestInterface as iRequest;

final class Add
{
    use APITraits;

    #[Post(Index::URL . '[/]', name: 'backends.add')]
    public function BackendAdd(iRequest $request): iResponse
    {
        $data = DataUtil::fromArray($request->getParsedBody());

        if (null === ($type = $data->get('type'))) {
            return api_error('No type was given.', HTTP_STATUS::HTTP_BAD_REQUEST);
        }

        if (null === ($name = $data->get('name'))) {
            return api_error('No name was given.', HTTP_STATUS::HTTP_BAD_REQUEST);
        }

        $backend = $this->getBackends(name: $name);

        if (!empty($backend)) {
            return api_error(r("Backend '{backend}' already exists.", [
                'backend' => $name
            ]), HTTP_STATUS::HTTP_CONFLICT);
        }

        if (null === ($class = Config::get("supported.{$type}", null))) {
            throw api_error(r("Unexpected client type '{type}' was given.", [
                'type' => $type
            ]), HTTP_STATUS::HTTP_BAD_REQUEST);
        }

        $instance = Container::getNew($class);
        assert($instance instanceof ClientInterface, new RuntimeException('Invalid client class.'));

        try {
            $config = DataUtil::fromArray($this->fromRequest($type, $request, $instance));

            $context = new Context(
                clientName: $type,
                backendName: $name,
                backendUrl: new Uri($config->get('url')),
                cache: Container::get(BackendCache::class),
                backendId: $config->get('uuid', null),
                backendToken: $config->get('token'),
                backendUser: $config->get('user', null),
                options: $config->get('options', []),
            );

            if (false === $instance->validateContext($context)) {
                return api_error('Invalid context information was given.', HTTP_STATUS::HTTP_BAD_REQUEST);
            }

            $configFile = ConfigFile::open(Config::get('backends_file'), 'yaml');
            $configFile->set($name, $config);
            $configFile->persist();
        } catch (InvalidContextException $e) {
            return api_error($e->getMessage(), HTTP_STATUS::HTTP_BAD_REQUEST);
        }

        $response = [
            'backends' => [],
            'links' => [],
        ];

        return api_response(HTTP_STATUS::HTTP_OK, $response);
    }

    private function fromRequest(string $type, iRequest $request, ClientInterface|null $client = null): array
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
                'lastSync' => (int)$data->get('export.lastSync', 0),
            ],
            'import' => [
                'enabled' => (bool)$data->get('import.enabled', false),
                'lastSync' => (int)$data->get('import.lastSync', 0),
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

        $optionals = [
            Options::DUMP_PAYLOAD => 'bool',
            Options::LIBRARY_SEGMENT => 'int',
            Options::IGNORE => 'string',
        ];

        foreach ($optionals as $key => $type) {
            if (null !== ($value = $data->get($key))) {
                $val = $data->get($value, $type);
                settype($val, $type);
                $config = ag_set($config, "options.{$key}", $val);
            }
        }

        if (null !== $client) {
            $config = ag_set($config, 'options', $client->fromRequest($request));
        }

        return $config;
    }
}
