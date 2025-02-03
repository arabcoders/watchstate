<?php

declare(strict_types=1);

namespace App\Libs\Traits;

use App\API\Backend\Index;
use App\Backends\Common\Cache as BackendCache;
use App\Backends\Common\ClientInterface;
use App\Backends\Common\ClientInterface as iClient;
use App\Backends\Common\Context;
use App\Libs\Config;
use App\Libs\ConfigFile;
use App\Libs\Container;
use App\Libs\DataUtil;
use App\Libs\Entity\StateInterface as iState;
use App\Libs\Exceptions\InvalidArgumentException;
use App\Libs\Exceptions\RuntimeException;
use App\Libs\Mappers\ExtendedImportInterface as iEImport;
use App\Libs\Options;
use App\Libs\Uri;
use App\Libs\UserContext;
use Psr\Http\Message\ServerRequestInterface as iRequest;
use Psr\Http\Message\UriInterface as iUri;
use Psr\Log\LoggerInterface as iLogger;

trait APITraits
{
    private array $_backendsNames = [];

    /**
     * Retrieves the backend client for the specified name.
     *
     * @param string $name The name of the backend.
     * @param array $config (Optional) Override the default configuration for the backend.
     *
     * @return iClient The backend client instance.
     * @throws RuntimeException If no backend with the specified name is found.
     */
    protected function getClient(string $name, array $config = [], UserContext|null $userContext = null): iClient
    {
        $configFile = $userContext?->config ?? ConfigFile::open(Config::get('backends_file'), 'yaml', autoCreate: true);

        if (null === $configFile->get("{$name}.type", null)) {
            throw new RuntimeException(r("Backend '{backend}' doesn't exists.", ['backend' => $name]), 1000);
        }

        $opts = [];
        $default = $configFile->get($name);
        $default['name'] = $name;

        if (null !== $userContext) {
            $opts[BackendCache::class] = Container::get(BackendCache::class)->with(
                adapter: $userContext->cache
            );
        }

        return makeBackend(array_replace_recursive($default, $config), $name, options: $opts);
    }

    /**
     * Get the list of backends.
     *
     * @param string|null $name Filter result by backend name.
     * @param UserContext|null $userContext (Optional) The user context.
     *
     * @return array The list of backends.
     */
    protected function getBackends(string|null $name = null, UserContext|null $userContext = null): array
    {
        $backends = [];

        $list = $userContext?->config ?? ConfigFile::open(Config::get('backends_file'), 'yaml', autoCreate: true);

        foreach ($list->getAll() as $backendName => $backend) {
            $backend = ['name' => $backendName, ...$backend];

            if (null !== ($import = ag($backend, 'import.lastSync'))) {
                $backend = ag_set($backend, 'import.lastSync', $import ? makeDate($import) : null);
            }

            if (null !== ($export = ag($backend, 'export.lastSync'))) {
                $backend = ag_set($backend, 'export.lastSync', $export ? makeDate($export) : null);
            }

            $webhookUrl = parseConfigValue(Index::URL) . "/{$backendName}/webhook";

            if (true === (bool)Config::get('api.secure')) {
                $webhookUrl .= '?apikey=' . Config::get('api.key');
            }

            $backend['urls'] = [
                'webhook' => $webhookUrl,
            ];

            if (empty($backend['options'])) {
                $backend['options'] = [];
            }

            $backends[] = $backend;
        }

        if (null !== $name) {
            return array_filter($backends, fn($backend) => $backend['name'] === $name);
        }

        return $backends;
    }

    protected function getBackend(string $name, UserContext|null $userContext = null): array|null
    {
        $backends = $this->getBackends($name, userContext: $userContext);
        return count($backends) > 0 ? array_pop($backends) : null;
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
            throw new InvalidArgumentException(r("Unexpected client type '{type}' was given.", ['type' => $type]), 2000
            );
        }

        $options = [];

        if (null === $data->get('url')) {
            throw new InvalidArgumentException('No URL was given.', 2001);
        }

        if (null === $data->get('token')) {
            throw new InvalidArgumentException('No token was given.', 2002);
        }

        if (null !== $data->get('options.' . Options::ADMIN_TOKEN)) {
            $options[Options::ADMIN_TOKEN] = $data->get('options.' . Options::ADMIN_TOKEN);
        }

        if (null !== $data->get('options.' . Options::PLEX_USER_PIN)) {
            $options[Options::PLEX_USER_PIN] = $data->get('options.' . Options::PLEX_USER_PIN);
        }

        if (null !== $data->get('options.' . Options::IS_LIMITED_TOKEN)) {
            $options[Options::IS_LIMITED_TOKEN] = (bool)$data->get('options.' . Options::IS_LIMITED_TOKEN, false);
        }

        $instance = Container::getNew($class);
        assert($instance instanceof ClientInterface, new InvalidArgumentException('Invalid client class.'));
        return $instance->withContext(
            new Context(
                clientName: $type,
                backendName: $data->get('name', 'basic_' . $type),
                backendUrl: new Uri($data->get('url')),
                cache: Container::get(BackendCache::class),
                backendId: $data->get('uuid'),
                backendToken: $data->get('token'),
                backendUser: $data->get('user'),
                options: $options,
            )
        );
    }

    /**
     * Get the web URL for the specified item.
     *
     * @param string $backend The backend name.
     * @param string $type The item type.
     * @param string|int $id The item ID.
     * @param UserContext|null $userContext (Optional) The user context.
     *
     * @return iUri The web URL.
     */
    protected function getBackendItemWebUrl(
        string $backend,
        string $type,
        string|int $id,
        UserContext|null $userContext = null
    ): iUri {
        static $clients = [];

        if (!isset($clients[$backend])) {
            $clients[$backend] = $this->getClient(name: $backend, userContext: $userContext);
        }

        return $clients[$backend]->getWebUrl($type, $id);
    }

    /**
     * The Standardize entity data for presentation in API responses.
     *
     * @param iState|array $entity The entity to format.
     * @param bool $includeContext (Optional) Include the contextual data.
     * @param UserContext|null $userContext (Optional) The user context.
     *
     * @return array The formatted entity.
     */
    protected function formatEntity(
        iState|array $entity,
        bool $includeContext = false,
        UserContext|null $userContext = null
    ): array {
        if (true === is_array($entity)) {
            $entity = Container::get(iState::class)::fromArray($entity);
        }

        if (empty($this->_backendsNames)) {
            $this->_backendsNames = array_column($this->getBackends(userContext: $userContext), 'name');
        }

        $item = $entity->getAll();

        $item[iState::COLUMN_META_DATA_PROGRESS] = $entity->hasPlayProgress() ? $entity->getPlayProgress() : null;
        $item[iState::COLUMN_EXTRA_EVENT] = ag($entity->getExtra($entity->via), iState::COLUMN_EXTRA_EVENT, null);

        $item['content_title'] = $entity->getMeta(iState::COLUMN_EXTRA . '.' . iState::COLUMN_TITLE, null);
        $item['content_path'] = ag($entity->getMetadata($entity->via), iState::COLUMN_META_PATH);

        $item['rguids'] = [];
        $item['reported_by'] = [];

        if ($entity->isEpisode()) {
            foreach ($entity->getRelativeGuids() as $rKey => $rGuid) {
                $item['rguids'][$rKey] = $rGuid;
            }
        }

        if (!empty($item[iState::COLUMN_META_DATA])) {
            foreach ($item[iState::COLUMN_META_DATA] as $key => &$metadata) {
                $metadata['webUrl'] = (string)$this->getBackendItemWebUrl(
                    backend: $key,
                    type: ag($metadata, iState::COLUMN_TYPE),
                    id: ag($metadata, iState::COLUMN_ID),
                    userContext: $userContext
                );
                $item['reported_by'][] = $key;
            }
        }

        $item['webUrl'] = (string)$this->getBackendItemWebUrl(
            backend: $entity->via,
            type: $entity->type,
            id: ag($entity->getMetadata($entity->via), iState::COLUMN_ID, 0),
            userContext: $userContext
        );

        $item['not_reported_by'] = array_values(
            array_filter($this->_backendsNames, fn($key) => false === in_array($key, ag($item, 'reported_by', [])))
        );

        $item['isTainted'] = $entity->isTainted();

        if (true === $includeContext) {
            $item = array_replace_recursive($item, $entity->getContext());
        }

        return $item;
    }

    /**
     * Get the user context.
     *
     * @param iRequest $request The request object.
     *
     * @return UserContext The user context.
     * @throws RuntimeException If the user is not found.
     */
    protected function getUserContext(iRequest $request, iEImport $mapper, iLogger $logger): UserContext
    {
        if (null === ($user = $request->hasHeader('X-User') ? $request->getHeaderLine('X-User') : null)) {
            $user = ag($request->getQueryParams(), 'user', 'main');
        }

        return getUserContext($user, $mapper, $logger);
    }
}
