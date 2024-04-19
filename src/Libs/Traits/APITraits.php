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
    protected function getBackend(string $name, array $config = []): iClient
    {
        $configFile = ConfigFile::open(Config::get('backends_file'), 'yaml');

        if (null === $configFile->get("{$name}.type", null)) {
            throw new RuntimeException(r("Backend '{backend}' doesn't exists.", ['backend' => $name]));
        }

        $default = $configFile->get($name);
        $default['name'] = $name;

        return makeBackend(array_replace_recursive($default, $config), $name);
    }
}
