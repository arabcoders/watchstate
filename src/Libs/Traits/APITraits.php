<?php

declare(strict_types=1);

namespace App\Libs\Traits;

use App\Backends\Common\ClientInterface as iClient;
use App\Libs\Config;
use App\Libs\ConfigFile;
use App\Libs\Exceptions\RuntimeException;

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
        $configFile = ConfigFile::open(Config::get('backends_file'), 'yaml');

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

        foreach (ConfigFile::open(Config::get('backends_file'), 'yaml')->getAll() as $backendName => $backend) {
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
}
