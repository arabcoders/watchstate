<?php

declare(strict_types=1);

namespace App\Libs\Traits;

use App\Backends\Common\Cache as BackendCache;
use App\Backends\Common\ClientInterface;
use App\Backends\Common\ClientInterface as iClient;
use App\Backends\Common\Context;
use App\Libs\Config;
use App\Libs\ConfigFile;
use App\Libs\Container;
use App\Libs\DataUtil;
use App\Libs\Exceptions\InvalidArgumentException;
use App\Libs\Exceptions\RuntimeException;
use App\Libs\Options;
use App\Libs\Uri;

trait APITraits
{
    /**
     * Retrieves the backend client for the specified name.
     *
     * @param string $name The name of the backend.
     * @param array $config (Optional) Override the default configuration for the backend.
     *
     * @return iClient The backend client instance.
     * @throws RuntimeException If no backend with the specified name is found.
     */
    protected function getClient(string $name, array $config = []): iClient
    {
        $configFile = ConfigFile::open(Config::get('backends_file'), 'yaml', autoCreate: true);

        if (null === $configFile->get("{$name}.type", null)) {
            throw new RuntimeException(r("Backend '{backend}' doesn't exists.", ['backend' => $name]));
        }

        $default = $configFile->get($name);
        $default['name'] = $name;

        return makeBackend(array_replace_recursive($default, $config), $name);
    }

    /**
     * Get the list of backends.
     *
     * @param string|null $name Filter result by backend name.
     * @return array The list of backends.
     */
    protected function getBackends(string|null $name = null): array
    {
        $backends = [];

        $list = ConfigFile::open(Config::get('backends_file'), 'yaml', autoCreate: true)->getAll();

        foreach ($list as $backendName => $backend) {
            $backend = ['name' => $backendName, ...$backend];

            if (null !== ag($backend, 'import.lastSync')) {
                $backend = ag_set($backend, 'import.lastSync', makeDate(ag($backend, 'import.lastSync')));
            }

            if (null !== ag($backend, 'export.lastSync')) {
                $backend = ag_set($backend, 'export.lastSync', makeDate(ag($backend, 'export.lastSync')));
            }

            $backends[] = $backend;
        }

        if (null !== $name) {
            return array_filter($backends, fn($backend) => $backend['name'] === $name);
        }

        return $backends;
    }

    /**
     * Create basic client to inquiry about the backend.
     *
     * @param string $type Backend type.
     * @param DataUtil $data The request data.
     *
     * @return iClient The client instance.
     * @throws InvalidArgumentException If url, token is missing or type is incorrect.
     */
    protected function getBasicClient(string $type, DataUtil $data): iClient
    {
        if (null === ($class = Config::get("supported.{$type}", null))) {
            throw new InvalidArgumentException(r("Unexpected client type '{type}' was given.", ['type' => $type]));
        }

        $options = [];

        if (null === $data->get('url')) {
            throw new InvalidArgumentException('No URL was given.');
        }

        if (null === $data->get('token')) {
            throw new InvalidArgumentException('No token was given.');
        }

        if (null !== $data->get('options.' . Options::ADMIN_TOKEN)) {
            $options[Options::ADMIN_TOKEN] = $data->get('options.' . Options::ADMIN_TOKEN);
        }

        $instance = Container::getNew($class);
        assert($instance instanceof ClientInterface, new InvalidArgumentException('Invalid client class.'));
        return $instance->withContext(
            new Context(
                clientName: $type,
                backendName: 'basic_' . $type,
                backendUrl: new Uri($data->get('url')),
                cache: Container::get(BackendCache::class),
                backendId: $data->get('uuid'),
                backendToken: $data->get('token'),
                backendUser: $data->get('user'),
                options: $options,
            )
        );
    }
}
