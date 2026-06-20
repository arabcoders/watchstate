<?php

declare(strict_types=1);

namespace App\Libs\Identities;

use App\Backends\Common\ClientInterface as iClient;
use App\Backends\Plex\PlexClient;
use App\Commands\State\BackupCommand;
use App\Commands\System\TasksCommand;
use App\Libs\Config;
use App\Libs\ConfigFile;
use App\Libs\Exceptions\RuntimeException;
use App\Libs\Mappers\ImportInterface as iImport;
use App\Libs\Options;
use App\Libs\UserContext;
use Psr\Log\LoggerInterface as iLogger;
use Psr\SimpleCache\InvalidArgumentException;
use Throwable;

final class IdentityProvisionService
{
    public function __construct(
        private readonly iImport $mapper,
        private readonly iLogger $logger,
    ) {}

    /**
     * Load current main-identity backends.
     *
     * @return array<string, array<string, mixed>>
     */
    public function loadBackends(): array
    {
        $backends = [];
        $supported = Config::get('supported', []);
        $configFile = ConfigFile::open(Config::get('backends_file'), 'yaml');
        $configFile->setLogger($this->logger);

        foreach ($configFile->getAll() as $backendName => $backend) {
            $type = strtolower(ag($backend, 'type', 'unknown'));

            if (!isset($supported[$type])) {
                $this->logger->error("Ignoring '{identity.backend}'. Unexpected backend type '{type}'.", [
                    'operation' => 'identity.discover',
                    'error' => 'unexpected_backend_type',
                    'type' => $type,
                    'identity' => [
                        'backend' => $backendName,
                    ],
                    'types' => implode(', ', array_keys($supported)),
                ]);
                continue;
            }

            if (null === ($url = ag($backend, 'url')) || false === is_valid_url($url)) {
                $this->logger->error("Ignoring '{identity.backend}'. Invalid URL '{url}'.", [
                    'operation' => 'identity.discover',
                    'error' => 'invalid_url',
                    'url' => $url ?? 'None',
                    'identity' => [
                        'backend' => $backendName,
                    ],
                ]);
                continue;
            }

            $backend['name'] = $backendName;
            $backend['class'] = make_backend($backend, $backendName);
            $backend['class']->setLogger($this->logger);

            $backends[$backendName] = $backend;
        }

        return $backends;
    }

    /**
     * Load persisted identity mappings.
     *
     * @return array<int, array<string, mixed>>
     */
    public function loadMappings(): array
    {
        $mapFile = Config::get('mapper_file');

        if (false === file_exists($mapFile) || filesize($mapFile) < 10) {
            return [];
        }

        $map = ConfigFile::open($mapFile, 'yaml');
        $mapping = $map->get('map', $map->getAll());

        if (empty($mapping)) {
            return [];
        }

        if (false === $map->has('version')) {
            $this->logger->warning('mapper.yaml is missing the version key. Required since v1.5.', [
                'operation' => 'identity.mapper',
                'error' => 'missing_version_key',
            ]);
        }

        if (false === $map->has('map')) {
            $this->logger->warning('mapper.yaml is missing the map key. Upgrade to v1.5 format spec.', [
                'operation' => 'identity.mapper',
                'error' => 'missing_map_key',
            ]);
        }

        $this->logger->info('Mapper file found, using it to map identities.', [
            'map' => array_to_string($mapping),
        ]);

        return $mapping;
    }

    /**
     * Persist identity mappings.
     *
     * @param array<int, array<string, mixed>> $mapping
     *
     * @return array<string, mixed>
     */
    public function saveMappings(array $mapping, string $version = '1.6'): array
    {
        $mapFile = Config::get('mapper_file');

        if (true === file_exists($mapFile)) {
            unlink($mapFile);
        }

        return ConfigFile::open($mapFile, 'yaml', true, true, true)
            ->set('version', $version)
            ->set('map', $mapping)
            ->persist()
            ->getAll();
    }

    /**
     * Build the current provision preview.
     *
     * @param array<int, array<string, mixed>> $mapping
     *
     * @return array{matched: array<int, array<string, mixed>>, unmatched: array<int, array<string, mixed>>, backends: array<int, string>}
     */
    public function preview(array $mapping = []): array
    {
        if ([] === $mapping) {
            $mapping = $this->loadMappings();
        }

        $backends = $this->loadBackends();

        if (count($backends) < 1) {
            return [
                'matched' => [],
                'unmatched' => [],
                'backends' => [],
            ];
        }

        $backendUsers = $this->getBackendUsers($backends, $mapping);

        if (count($backendUsers) < 1) {
            return [
                'matched' => [],
                'unmatched' => [],
                'backends' => array_keys($backends),
            ];
        }

        $results = $this->generateIdentitiesList($backendUsers, $mapping);

        return [
            'matched' => ag($results, 'matched', []),
            'unmatched' => ag($results, 'unmatched', []),
            'backends' => array_keys($backends),
        ];
    }

    /**
     * Provision identities from backend users.
     *
     * @return array{identities: array<int, array<string, mixed>>, has_identities: bool}
     *
     * @throws RuntimeException
     * @throws InvalidArgumentException
     */
    public function provision(IdentityProvisionRequest $request): array
    {
        set_time_limit(60 * 10);

        $hasIdentities = $this->hasIdentities();

        if ($hasIdentities && 'create' === $request->mode) {
            throw new RuntimeException(
                'Identity configuration already exists. Use update or recreate mode instead.',
            );
        }

        if (true === $request->shouldRecreate()) {
            $this->purgeIdentitiesConfig(Config::get('path') . '/users', $request->dryRun);
            $hasIdentities = false;
        }

        $backends = $this->loadBackends();

        if (empty($backends)) {
            throw new RuntimeException('No valid backends were found.');
        }

        $mapping = [] !== $request->mapping ? $request->mapping : $this->loadMappings();

        if (true === $request->persistMapping && [] !== $request->mapping) {
            $this->saveMappings($request->mapping, $request->mappingVersion);
        }

        if (true === $request->allowSingleBackendIdentities) {
            $countBackends = count($backends);

            if (1 !== $countBackends) {
                throw new RuntimeException(
                    r('Single backend mode requires 1 backend configured. Found {count}.', [
                        'count' => $countBackends,
                    ]),
                );
            }

            $this->logger->notice('Running in single backend identity mode.');

            $backendUsers = $this->getBackendUsers($backends, $mapping, false);

            if (count($backendUsers) < 1) {
                throw new RuntimeException('No backend users were found.');
            }

            $results = $this->generateSingleBackendIdentities($backendUsers);
            $identities = ag($results, 'matched', []);

            if (count($identities) < 1) {
                throw new RuntimeException('No identities were found in the single backend.');
            }

            $this->logger->notice("Matched '{results}' from single backend.", [
                'results' => array_to_string($this->identitiesList($identities)),
            ]);

            $this->createIdentities($request, $identities);

            return [
                'identities' => $identities,
                'has_identities' => $hasIdentities,
            ];
        }

        $this->logger->notice("Getting users list from '{backends}'.", [
            'backends' => implode(', ', array_keys($backends)),
        ]);

        $backendUsers = $this->getBackendUsers($backends, $mapping);

        if (count($backendUsers) < 1) {
            throw new RuntimeException('No backend users were found.');
        }

        $results = $this->generateIdentitiesList($backendUsers, $mapping);
        $identities = ag($results, 'matched', []);

        if (count($identities) < 1) {
            throw new RuntimeException("We weren't able to match any identities across backends.");
        }

        $this->logger->notice("Matched '{results}'.", [
            'results' => array_to_string($this->identitiesList($identities)),
        ]);

        $this->createIdentities($request, $identities);

        return [
            'identities' => $identities,
            'has_identities' => $hasIdentities,
        ];
    }

    /**
     * Safely sync existing identity backends from the current main backend configuration.
     *
     * This only updates already-linked identity backends and never creates, deletes, or rematches identities.
     *
     * @return array{updated: array<int, array<string, string>>, skipped: array<int, array<string, string>>, failed: array<int, array<string, string>>}
     *
     * @throws InvalidArgumentException
     * @throws RuntimeException
     */
    public function syncBackends(bool $dryRun = false): array
    {
        $backends = $this->loadBackends();

        if ([] === $backends) {
            throw new RuntimeException('No valid backends were found.');
        }

        $contexts = get_users_context($this->mapper, $this->logger, ['no_main_user' => true]);
        $results = ['updated' => [], 'skipped' => [], 'failed' => []];

        foreach ($contexts as $identityName => $identityContext) {
            $changed = false;

            foreach ($identityContext->config->getAll() as $backendName => $identityBackend) {
                if (false === is_array($identityBackend)) {
                    $results['failed'][] = [
                        'identity' => $identityName,
                        'backend' => (string) $backendName,
                        'reason' => 'Invalid backend configuration.',
                    ];
                    continue;
                }

                $sourceBackendName = ag($identityBackend, 'options.' . Options::ALT_NAME);

                if (false === is_string($sourceBackendName) || '' === $sourceBackendName) {
                    $results['skipped'][] = [
                        'identity' => $identityName,
                        'backend' => (string) $backendName,
                        'reason' => 'Backend is not linked to a source backend.',
                    ];
                    continue;
                }

                if (false === array_key_exists($sourceBackendName, $backends)) {
                    $results['skipped'][] = [
                        'identity' => $identityName,
                        'backend' => (string) $backendName,
                        'source_backend' => $sourceBackendName,
                        'reason' => 'Source backend no longer exists.',
                    ];
                    continue;
                }

                $syncedBackend = $this->buildSyncedBackendConfig(
                    sourceBackendName: $sourceBackendName,
                    sourceBackend: $backends[$sourceBackendName],
                    identityBackend: $identityBackend,
                );

                if ($syncedBackend === $identityBackend) {
                    $results['skipped'][] = [
                        'identity' => $identityName,
                        'backend' => (string) $backendName,
                        'source_backend' => $sourceBackendName,
                        'reason' => 'Already in sync.',
                    ];
                    continue;
                }

                $results['updated'][] = [
                    'identity' => $identityName,
                    'backend' => (string) $backendName,
                    'source_backend' => $sourceBackendName,
                ];

                $this->logger->info("Syncing identity backend '{identity.name}@{identity.backend}' from '{source}'.", [
                    'identity' => [
                        'name' => $identityName,
                        'backend' => $backendName,
                    ],
                    'source' => $sourceBackendName,
                    'dry_run' => $dryRun,
                ]);

                if (false === $dryRun) {
                    $identityContext->config->set((string) $backendName, $syncedBackend);
                    $changed = true;
                }
            }

            if (true === $changed && false === $dryRun) {
                $this->applyRemovedKeysFilter($identityContext->config)->persist();
            }
        }

        return $results;
    }

    /**
     * Generate identities list for single backend mode.
     *
     * @param array<int, array<string, mixed>> $users
     *
     * @return array{matched: array<int, array<string, mixed>>, unmatched: array<int, array<string, mixed>>}
     */
    public function generateSingleBackendIdentities(array $users): array
    {
        $results = [];

        foreach ($users as $user) {
            $backend = $user['backend'];

            if (ag($user, 'id') === ag($user, 'client_data.options.' . Options::ALT_ID)) {
                $this->logger->debug('Skipping main user "{name}".', ['name' => $user['name']]);
                continue;
            }

            $results[] = [
                'name' => strtolower($user['name']),
                'backends' => [$backend => $user],
            ];
        }

        return ['matched' => $results, 'unmatched' => []];
    }

    /**
     * Get users from all configured backends.
     *
     * @param array<string, array<string, mixed>> $backends
     * @param array<int, array<string, mixed>> $map
     *
     * @return array<int, array<string, mixed>>
     */
    public function getBackendUsers(array $backends, array &$map, bool $noMapActions = false): array
    {
        $users = [];

        foreach ($backends as $backend) {
            $client = ag($backend, 'class');
            assert($client instanceof iClient, 'Expected backend client instance.');

            $this->logger->info("Getting users from '{identity.backend}'.", [
                'identity' => [
                    'backend' => $client->getContext()->backendName,
                ],
            ]);

            try {
                foreach ($client->getUsersList() as $user) {
                    $info = $backend;
                    $backendName = ag($backend, 'name');

                    $user['real_name'] = ag($user, 'name');
                    $user['protected'] = (bool) ag($user, 'protected', false);

                    // Normalize early so matching and filesystem identity names stay stable.
                    $user['name'] = normalize_name((string) ag($user, 'name'), $this->logger, [
                        'log_message' => "Normalized '{identity.backend}: {name}' to '{identity.backend}: {new_name}'",
                        'context' => ['identity' => ['backend' => $backendName]],
                    ]);

                    if (false === $noMapActions) {
                        $this->mapActions($backendName, $user, $map);
                    }

                    if (false === is_valid_name($user['name'])) {
                        $this->logger->error(
                            message: "Invalid user name '{identity.backend}: {name}'. User names must be in [a-z_0-9] format. Skipping user.",
                            context: [
                                'operation' => 'identity.sync',
                                'error' => 'invalid_user_name',
                                'constraint' => '[a-z_0-9]',
                                'name' => $user['name'],
                                'identity' => ['backend' => $backendName],
                            ],
                        );
                        continue;
                    }

                    $info['user'] = ag($user, 'id', ag($info, 'user'));
                    $info['displayName'] = $user['name'];
                    $info['backendName'] = normalize_name(r('{backend}_{user}', [
                        'backend' => $backendName,
                        'user' => $user['name'],
                    ]), $this->logger);

                    if (false === is_valid_name($info['backendName'])) {
                        $this->logger->error(
                            message: "Invalid backend name '{name}'. Backend name must be in [a-z_0-9] format. Skipping the associated users.",
                            context: [
                                'operation' => 'identity.sync',
                                'error' => 'invalid_backend_name',
                                'constraint' => '[a-z_0-9]',
                                'name' => $info['backendName'],
                            ],
                        );
                        continue;
                    }

                    $info = ag_delete($info, 'options.' . Options::PLEX_USER_PIN);
                    $info = ag_sets($info, [
                        'options.' . Options::ALT_NAME => ag($backend, 'name'),
                        'options.' . Options::ALT_ID => ag($backend, 'user'),
                    ]);

                    if (PlexClient::CLIENT_NAME === ucfirst(ag($backend, 'type'))) {
                        $info = ag_sets($info, [
                            /* @mago-expect lint:no-literal-password */
                            'token' => 'reuse_or_generate_token',
                            'options.' . Options::PLEX_USER_NAME => ag($user, 'name'),
                            'options.' . Options::PLEX_USER_UUID => ag($user, 'uuid'),
                            'options.' . Options::ADMIN_TOKEN => ag(
                                array: $backend,
                                path: ['options.' . Options::ADMIN_TOKEN, 'token'],
                            ),
                        ]);

                        if (null !== ($adminUserPIN = ag($user, 'options.' . Options::PLEX_USER_PIN))) {
                            $info = ag_set($info, 'options.' . Options::ADMIN_PLEX_USER_PIN, $adminUserPIN);
                        }

                        if (null !== ($userPin = ag($user, 'options.' . Options::PLEX_USER_PIN))) {
                            $info = ag_set($info, 'options.' . Options::PLEX_USER_PIN, $userPin);
                        }

                        if (true === (bool) ag($user, 'guest', false)) {
                            $info = ag_set($info, 'options.' . Options::PLEX_EXTERNAL_USER, true);
                        }
                    }

                    $user = ag_sets($user, ['backend' => $backendName, 'client_data' => $info]);
                    $users[] = $user;
                }
            } catch (Throwable $e) {
                $this->logger->error(
                    "Failed to get users list from '{identity.user}@{identity.backend}'. {exception.message}",
                    [
                        'operation' => 'identity.get_users',
                        'identity' => [
                            'client' => $client->getContext()->clientName,
                            'backend' => $client->getContext()->backendName,
                            'user' => $client->getContext()->userContext->name,
                        ],
                        ...exception_log($e),
                    ],
                );
            }
        }

        return $users;
    }

    /**
     * Create local identity configuration and data.
     *
     * @param array<int, array<string, mixed>> $identities
     *
     * @throws InvalidArgumentException
     */
    public function createIdentities(IdentityProvisionRequest $request, array $identities): void
    {
        $removedKeys = ag(include __DIR__ . '/../../../config/removed.keys.php', 'backend', []);

        foreach ($identities as $identity) {
            $identityName = normalize_name(ag($identity, 'name', 'unknown'), $this->logger);

            if (false === is_valid_name($identityName)) {
                $this->logger->error(
                    message: "Invalid identity name '{identity.name}'. Identity names must be in [a-z_0-9] format. Skipping identity.",
                    context: [
                        'operation' => 'identity.create',
                        'error' => 'invalid_identity_name',
                        'constraint' => '[a-z_0-9]',
                        'identity' => ['name' => $identityName],
                    ],
                );
                continue;
            }

            $identityPath = r(fix_path(Config::get('path') . '/users/{user}'), ['user' => $identityName]);

            $this->logger->info(
                false === is_dir($identityPath)
                    ? "Creating '{identity.name}' directory '{path}'."
                    : "'{identity.name}' directory '{path}' already exists.",
                [
                    'identity' => ['name' => $identityName],
                    'path' => $identityPath,
                ],
            );

            if (false === $request->dryRun && false === is_dir($identityPath) && false === mkdir($identityPath, 0o755, true)) {
                $this->logger->error("Failed to create '{identity.name}' directory '{path}'.", [
                    'identity' => ['name' => $identityName],
                    'path' => $identityPath,
                ]);
                continue;
            }

            $configFile = "{$identityPath}/servers.yaml";
            $this->logger->notice(
                file_exists($configFile)
                    ? "'{identity.name}' configuration file '{file}' already exists."
                    : "Creating '{identity.name}' configuration file '{file}'.",
                [
                    'identity' => ['name' => $identityName],
                    'file' => $configFile,
                ],
            );

            $perIdentity = ConfigFile::open(
                file: $request->dryRun ? 'php://memory' : $configFile,
                type: 'yaml',
                autoSave: !$request->dryRun,
                autoCreate: !$request->dryRun,
                autoBackup: !$request->dryRun,
            );
            $perIdentity->setLogger($this->logger);

            foreach (ag($identity, 'backends', []) as $backend) {
                $name = ag($backend, 'client_data.backendName');

                if (false === is_valid_name($name)) {
                    $this->logger->error(
                        message: "Invalid backend name '{name}'. Backend name must be in [a-z_0-9] format. Skipping backend.",
                        context: [
                            'operation' => 'identity.create',
                            'error' => 'invalid_backend_name',
                            'constraint' => '[a-z_0-9]',
                            'name' => $name,
                        ],
                    );
                    continue;
                }

                $clientData = ag_delete(ag($backend, 'client_data'), 'class');
                $clientData['name'] = $name;

                if (false === $perIdentity->has($name)) {
                    $data = $clientData;
                    $data = ag_sets($data, ['import.lastSync' => null, 'export.lastSync' => null]);
                    $data = ag_delete($data, [...$removedKeys, 'name', 'backendName', 'displayName']);
                    $perIdentity->set($name, $data);
                } else {
                    $clientData = ag_delete($clientData, ['token', 'import.lastSync', 'export.lastSync']);
                    $clientData = array_replace_recursive($perIdentity->get($name), $clientData);

                    if (true === $request->shouldUpdate()) {
                        $update = [
                            'url' => ag($backend, 'client_data.url'),
                            'options.ALT_NAME' => ag($backend, 'client_data.name'),
                            'options.ALT_ID' => ag($backend, 'client_data.user'),
                        ];

                        if (null !== ($val = ag($backend, 'client_data.options.' . Options::IGNORE))) {
                            $update['options.' . Options::IGNORE] = $val;
                        }

                        if (null !== ($val = ag($backend, 'client_data.options.' . Options::LIBRARY_SEGMENT))) {
                            $update['options.' . Options::LIBRARY_SEGMENT] = $val;
                        }

                        if (PlexClient::CLIENT_NAME === ucfirst(ag($backend, 'client_data.type'))) {
                            $update['options.' . Options::PLEX_USER_NAME] = $identityName;
                            $update['options.' . Options::PLEX_USER_UUID] = ag($backend, 'uuid');

                            if (null !== ($val = ag($backend, 'client_data.options.' . Options::PLEX_EXTERNAL_USER))) {
                                $update['options.' . Options::PLEX_EXTERNAL_USER] = (bool) $val;
                            }

                            $update['options.' . Options::ADMIN_TOKEN] = ag($backend, [
                                'client_data.options.' . Options::ADMIN_TOKEN,
                                'client_data.token',
                            ]);
                        }

                        $this->logger->info("Updating identity configuration for '{identity.name}@{name}' backend.", [
                            'name' => $name,
                            'identity' => ['name' => $identityName],
                        ]);

                        foreach ($update as $key => $value) {
                            $perIdentity->set("{$name}.{$key}", $value);
                        }
                    }
                }

                try {
                    /* @mago-expect lint:no-insecure-comparison */
                    if (true === $request->regenerateTokens || 'reuse_or_generate_token' === ag($clientData, 'token')) {
                        $client = ag($backend, 'client_data.class');
                        assert($client instanceof iClient, 'Expected backend client instance.');

                        if (PlexClient::CLIENT_NAME === $client->getType()) {
                            $requestOpts = [];

                            if (ag($clientData, 'options.' . Options::PLEX_EXTERNAL_USER, false)) {
                                $requestOpts[Options::PLEX_EXTERNAL_USER] = true;
                            }

                            if (null !== ($userPIN = ag($backend, 'options.' . Options::PLEX_USER_PIN))) {
                                $requestOpts[Options::PLEX_USER_PIN] = $userPIN;
                            }

                            $token = $client->getUserToken(
                                userId: ag($clientData, 'options.' . Options::PLEX_USER_UUID),
                                username: ag($clientData, 'options.' . Options::PLEX_USER_NAME),
                                opts: $requestOpts,
                            );

                            if (false === $token) {
                                $this->logger->error(
                                    message: "Failed to generate access token for '{identity.name}@{identity.backend}' backend.",
                                    context: [
                                        'operation' => 'identity.access_token',
                                        'error' => 'token_generation_failed',
                                        'identity' => ['name' => $identityName, 'backend' => $name],
                                    ],
                                );
                            } else {
                                $perIdentity->set("{$name}.token", $token);
                            }
                        }
                    }
                } catch (Throwable $e) {
                    $this->logger->error(
                        message: "Failed to generate access token for '{identity.name}@{name}' backend. {exception.message}",
                        context: [
                            'operation' => 'identity.access_token',
                            'error' => 'token_generation_failed',
                            'name' => $name,
                            'identity' => ['name' => $identityName],
                            ...exception_log($e),
                        ],
                    );
                    continue;
                }
            }

            $dbFile = get_user_db($identityName);
            $this->logger->notice(
                file_exists($dbFile)
                    ? "'{identity.name}' database file '{db}' already exists."
                    : "Creating '{identity.name}' database file '{db}'.",
                [
                    'identity' => ['name' => $identityName],
                    'db' => $dbFile,
                ],
            );

            if (false === $request->dryRun) {
                if (false === file_exists($dbFile)) {
                    $db = ensure_migration($dbFile);
                    $cache = per_user_cache_adapter($identityName);
                    $mapper = $this->mapper
                        ->withDB($db)
                        ->withCache($cache)
                        ->withLogger($this->logger)
                        ->withOptions(array_replace_recursive($this->mapper->getOptions(), [Options::ALT_NAME => $identityName]));
                    $userContext = new UserContext(
                        name: $identityName,
                        config: $perIdentity,
                        mapper: $mapper,
                        cache: $cache,
                        db: $db,
                    );

                    ensure_indexes($db->getDBLayer()->getBackend(), $this->logger, [
                        UserContext::class => $userContext,
                    ]);
                }

                $perIdentity
                    ->addFilter('removed.keys', static function (array $data) use ($removedKeys): array {
                        foreach ($removedKeys as $key) {
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
                    })
                    ->persist();
            }

            if (true === $request->generateBackup && false === $request->shouldRecreate()) {
                $this->logger->notice("Queuing event to backup '{identity.name}' remote watch state.", [
                    'identity' => ['name' => $identityName],
                ]);

                if (false === $request->dryRun) {
                    queue_event(TasksCommand::CNAME, [
                        'command' => BackupCommand::ROUTE,
                        'args' => ['-v', '-u', $identityName, '--file', '{user}.{backend}.{date}.initial_backup.json'],
                    ]);
                }
            }
        }
    }

    /**
     * Generate a list of identities matched across backends.
     *
     * @param array<int, array<string, mixed>> $users
     * @param array<int, array<string, mixed>> $map
     *
     * @return array{matched: array<int, array<string, mixed>>, unmatched: array<int, array<string, mixed>>}
     */
    public function generateIdentitiesList(array $users, array $map = []): array
    {
        $allBackends = [];

        foreach ($users as $u) {
            if (in_array($u['backend'], $allBackends, true)) {
                continue;
            }

            $allBackends[] = $u['backend'];
        }

        $usersBy = [];
        $usersList = [];

        foreach ($users as $user) {
            $backend = $user['backend'];
            $nameLower = strtolower($user['name']);

            if (ag($user, 'id') === ag($user, 'client_data.options.' . Options::ALT_ID)) {
                $this->logger->debug('Skipping main user "{name}".', ['name' => $user['name']]);
                continue;
            }

            if (!isset($usersBy[$backend])) {
                $usersBy[$backend] = [];
            }

            $usersBy[$backend][$nameLower] = $user;
            $usersList[$backend][] = $nameLower;
        }

        $unmatched = [];
        $results = [];
        $used = [];
        $alreadyUsed = static fn($backend, $name): bool => in_array([$backend, $name], $used, true);

        $buildUnifiedRow = static function (array $backendDict) use ($allBackends): array {
            $names = [];

            foreach ($allBackends as $backend) {
                if (!isset($backendDict[$backend])) {
                    continue;
                }

                $names[] = $backendDict[$backend]['name'];
            }

            $freq = [];

            foreach ($names as $name) {
                if (!isset($freq[$name])) {
                    $freq[$name] = 0;
                }

                $freq[$name]++;
            }

            if (empty($freq)) {
                $finalName = 'unknown';
            } else {
                $max = max($freq);
                $candidates = array_keys(array_filter($freq, static fn($count) => $count === $max));

                if (1 === count($candidates)) {
                    $finalName = $candidates[0];
                } else {
                    $finalName = null;

                    foreach ($names as $name) {
                        if (!in_array($name, $candidates, true)) {
                            continue;
                        }

                        $finalName = $name;
                        break;
                    }

                    if (!$finalName) {
                        $finalName = 'unknown';
                    }
                }
            }

            $row = [
                'name' => strtolower($finalName),
                'backends' => [],
            ];

            foreach ($allBackends as $backend) {
                if (!isset($backendDict[$backend])) {
                    continue;
                }

                $row['backends'][$backend] = $backendDict[$backend];
            }

            return $row;
        };

        foreach ($allBackends as $backend) {
            if (!isset($usersBy[$backend])) {
                continue;
            }

            foreach ($usersBy[$backend] as $nameLower => $userObj) {
                if ($alreadyUsed($backend, $nameLower)) {
                    continue;
                }

                $matchedMapEntry = null;

                foreach ($map as $mapRow) {
                    if (ag($mapRow, "{$backend}.name") !== $nameLower) {
                        continue;
                    }

                    $this->logger->notice("Found map entry for '{identity.backend}: {identity.user}'", [
                        'identity' => [
                            'backend' => $backend,
                            'user' => $nameLower,
                        ],
                        'map' => $mapRow,
                    ]);
                    $matchedMapEntry = $mapRow;
                    break;
                }

                if ($matchedMapEntry) {
                    $mapMatch = [$backend => $userObj];

                    foreach ($allBackends as $otherBackend) {
                        if ($otherBackend === $backend) {
                            continue;
                        }

                        if (isset($matchedMapEntry[$otherBackend]['name'])) {
                            $mappedNameLower = strtolower($matchedMapEntry[$otherBackend]['name']);

                            if (isset($usersBy[$otherBackend][$mappedNameLower])) {
                                $mapMatch[$otherBackend] = $usersBy[$otherBackend][$mappedNameLower];
                            }
                        }
                    }

                    if (count($mapMatch) >= 2) {
                        foreach ($mapMatch as $matchedBackend => &$matchedUser) {
                            if (
                                !(
                                    isset($matchedMapEntry[$matchedBackend]['options'])
                                    && is_array($matchedMapEntry[$matchedBackend]['options'])
                                )
                            ) {
                                continue;
                            }

                            $mapOptions = $matchedMapEntry[$matchedBackend]['options'];

                            if (!isset($matchedUser['client_data']) || !is_array($matchedUser['client_data'])) {
                                $matchedUser['client_data'] = [];
                            }

                            if (!isset($matchedUser['client_data']['options']) || !is_array($matchedUser['client_data']['options'])) {
                                $matchedUser['client_data']['options'] = [];
                            }

                            $matchedUser['client_data']['options'] = array_replace_recursive(
                                $matchedUser['client_data']['options'],
                                $mapOptions,
                            );
                        }
                        unset($matchedUser);

                        $results[] = $buildUnifiedRow($mapMatch);

                        foreach ($mapMatch as $matchedBackend => $matchedUser) {
                            $matchedName = strtolower($matchedUser['name']);
                            $used[] = [$matchedBackend, $matchedName];
                            unset($usersBy[$matchedBackend][$matchedName]);
                        }

                        continue;
                    }

                    $this->logger->error("No partial fallback match via map for '{identity.backend}: {identity.user}'", [
                        'operation' => 'identity.match',
                        'error' => 'no_partial_fallback',
                        'identity' => [
                            'backend' => $userObj['backend'],
                            'user' => $userObj['name'],
                        ],
                    ]);
                }

                $directMatch = [$backend => $userObj];

                foreach ($allBackends as $otherBackend) {
                    if ($otherBackend === $backend) {
                        continue;
                    }

                    if (isset($usersBy[$otherBackend][$nameLower])) {
                        $directMatch[$otherBackend] = $usersBy[$otherBackend][$nameLower];
                    }
                }

                if (count($directMatch) >= 2) {
                    $results[] = $buildUnifiedRow($directMatch);

                    foreach ($directMatch as $matchedBackend => $matchedUser) {
                        $matchedName = strtolower($matchedUser['name']);
                        $used[] = [$matchedBackend, $matchedName];
                        unset($usersBy[$matchedBackend][$matchedName]);
                    }

                    continue;
                }

                $this->logger->error("No other users were found that match '{identity.backend}: {identity.user}{real_name}'.", [
                    'operation' => 'identity.match',
                    'error' => 'no_user_match',
                    'identity' => [
                        'backend' => $userObj['backend'],
                        'user' => $userObj['name'],
                    ],
                    'real_name' => $userObj['real_name'] !== $userObj['name']
                        ? r(' ({rl})', ['rl' => $userObj['real_name']])
                        : '',
                    'map' => array_to_string($map),
                    'list' => array_to_string($usersList),
                ]);

                $unmatched[] = [
                    'id' => $userObj['id'],
                    'name' => $userObj['name'],
                    'backend' => $backend,
                    'protected' => (bool) ag($userObj, 'protected', false),
                    'client_data' => ag($userObj, 'client_data.type', null),
                    'real_name' => $userObj['real_name'] ?? null,
                    'options' => ag($userObj, 'options', []),
                ];
            }
        }

        return ['matched' => $results, 'unmatched' => $unmatched];
    }

    /**
     * Format identities for logging or API summaries.
     *
     * @param array<int, array<string, mixed>> $list
     *
     * @return array<int, string>
     */
    public function identitiesList(array $list): array
    {
        $chunks = [];

        foreach ($list as $row) {
            $name = $row['name'] ?? 'unknown';
            $pairs = [];

            if (!empty($row['backends']) && is_array($row['backends'])) {
                foreach ($row['backends'] as $backendName => $backendData) {
                    if (!isset($backendData['name'])) {
                        continue;
                    }

                    $pairs[] = r('{name}@{backend}', [
                        'backend' => $backendName,
                        'name' => $backendData['name'],
                    ]);
                }
            }

            $chunks[] = r('{name}: {pairs}', ['name' => $name, 'pairs' => implode(', ', $pairs)]);
        }

        return $chunks;
    }

    /**
     * Apply mapper actions before matching.
     *
     * @param array<string, mixed> $user
     * @param array<int, array<string, mixed>> $mapping
     */
    public function mapActions(string $backend, array &$user, array &$mapping): void
    {
        static $reported = [];

        if (null === ($username = ag($user, 'name'))) {
            $this->logger->error("No username was given from one user of '{identity.backend}' backend.", [
                'operation' => 'identity.match',
                'error' => 'missing_username',
                'identity' => [
                    'backend' => $backend,
                ],
            ]);
            return;
        }

        $hasMapping = array_filter($mapping, static fn($map) => array_key_exists($backend, $map));

        if (count($hasMapping) < 1) {
            if (!isset($reported[$backend])) {
                $reported[$backend] = true;
                $this->logger->info("No mapping with '{identity.backend}' as backend exists.", [
                    'identity' => [
                        'backend' => $backend,
                    ],
                ]);
            }
            return;
        }

        $found = false;
        $userMap = [];

        foreach ($mapping as &$map) {
            foreach ($map as $mapBackend => &$loopMap) {
                if ($backend !== $mapBackend) {
                    continue;
                }

                if (ag($loopMap, 'name') !== $username) {
                    continue;
                }

                $found = true;
                $userMap = &$loopMap;
                break 2;
            }
        }
        unset($loopMap, $map);

        if (false === $found) {
            $this->logger->debug("No map exists for '{identity.backend}: {username}'.", [
                'identity' => [
                    'backend' => $backend,
                ],
                'username' => $username,
            ]);
            return;
        }

        if (null !== ($pin = ag($userMap, 'options.' . Options::PLEX_USER_PIN))) {
            if (!isset($user['options'])) {
                $user['options'] = [];
            }

            $user['options'][Options::PLEX_USER_PIN] = $pin;
        }

        if (null !== ($newUsername = ag($userMap, 'replace_with'))) {
            if (false === is_string($newUsername) || false === is_valid_name($newUsername)) {
                $this->logger->error(
                    message: "Failed to replace '{identity.backend}: {username}' with '{identity.backend}: {new_username}' name must be in [a-z_0-9] format.",
                    context: [
                        'operation' => 'identity.rename',
                        'error' => 'invalid_replacement_name',
                        'constraint' => '[a-z_0-9]',
                        'identity' => [
                            'backend' => $backend,
                        ],
                        'username' => $username,
                        'new_username' => $newUsername,
                    ],
                );
                return;
            }

            $this->logger->notice(
                message: "Renaming '{identity.backend}: {username}' to '{identity.backend}: {new_username}'.",
                context: [
                    'identity' => [
                        'backend' => $backend,
                    ],
                    'username' => $username,
                    'new_username' => $newUsername,
                ],
            );

            $user['name'] = $newUsername;
            $userMap['name'] = $newUsername;
        }
    }

    /**
     * Build an in-place synced backend config for an existing identity backend.
     *
     * @param array<string, mixed> $sourceBackend
     * @param array<string, mixed> $identityBackend
     *
     * @return array<string, mixed>
     */
    private function buildSyncedBackendConfig(string $sourceBackendName, array $sourceBackend, array $identityBackend): array
    {
        $sourceType = strtolower((string) ag($sourceBackend, 'type', ag($identityBackend, 'type', '')));
        $currentOptions = ag($identityBackend, 'options', []);
        $sourceOptions = ag($sourceBackend, 'options', []);
        $currentImport = ag($identityBackend, 'import', []);
        $sourceImport = ag($sourceBackend, 'import', []);
        $currentExport = ag($identityBackend, 'export', []);
        $sourceExport = ag($sourceBackend, 'export', []);

        if (false === is_array($currentOptions)) {
            $currentOptions = [];
        }

        if (false === is_array($sourceOptions)) {
            $sourceOptions = [];
        }

        if (false === is_array($currentImport)) {
            $currentImport = [];
        }

        if (false === is_array($sourceImport)) {
            $sourceImport = [];
        }

        if (false === is_array($currentExport)) {
            $currentExport = [];
        }

        if (false === is_array($sourceExport)) {
            $sourceExport = [];
        }

        $syncedOptions = $currentOptions;
        $syncedOptions[Options::ALT_NAME] = $sourceBackendName;

        if (null !== ($sourceUser = ag($sourceBackend, 'user'))) {
            $syncedOptions[Options::ALT_ID] = $sourceUser;
        }

        foreach ([Options::IGNORE, Options::LIBRARY_SEGMENT] as $option) {
            if (true !== array_key_exists($option, $sourceOptions)) {
                continue;
            }

            $syncedOptions[$option] = $sourceOptions[$option];
        }

        $synced = $identityBackend;
        $synced['type'] = ag($sourceBackend, 'type', ag($identityBackend, 'type'));
        $synced['url'] = ag($sourceBackend, 'url', ag($identityBackend, 'url'));
        $synced['import'] = array_replace_recursive(
            $currentImport,
            ag_delete($sourceImport, ['lastSync']),
        );
        $synced['export'] = array_replace_recursive(
            $currentExport,
            ag_delete($sourceExport, ['lastSync']),
        );

        if (true === ag_exists($sourceBackend, 'uuid')) {
            $synced['uuid'] = ag($sourceBackend, 'uuid');
        }

        if (PlexClient::CLIENT_NAME !== ucfirst($sourceType) && true === ag_exists($sourceBackend, 'token')) {
            $synced['token'] = ag($sourceBackend, 'token');
        }

        if (PlexClient::CLIENT_NAME === ucfirst($sourceType)) {
            $adminToken = ag($sourceBackend, ['options.' . Options::ADMIN_TOKEN, 'token']);

            if (null !== $adminToken) {
                $syncedOptions[Options::ADMIN_TOKEN] = $adminToken;
            }
        }

        $synced['options'] = $syncedOptions;
        $synced['user'] = ag($identityBackend, 'user');

        return ag_delete(
            $synced,
            [...$this->getRemovedBackendKeys(), 'class', 'name', 'displayName', 'backendName'],
        );
    }

    /**
     * Get the backend keys that must not be persisted.
     *
     * @return array<int, string>
     */
    private function getRemovedBackendKeys(): array
    {
        return ag(include __DIR__ . '/../../../config/removed.keys.php', 'backend', []);
    }

    /**
     * Apply the removed-keys persistence filter to a config file.
     */
    private function applyRemovedKeysFilter(ConfigFile $config): ConfigFile
    {
        $removedKeys = $this->getRemovedBackendKeys();

        return $config->addFilter('removed.keys', static function (array $data) use ($removedKeys): array {
            foreach ($removedKeys as $key) {
                foreach ($data as &$value) {
                    if (false === is_array($value)) {
                        continue;
                    }

                    if (false === ag_exists($value, $key)) {
                        continue;
                    }

                    $value = ag_delete($value, $key);
                }
            }

            return $data;
        });
    }

    /**
     * Determine whether local identities already exist.
     */
    public function hasIdentities(): bool
    {
        $usersPath = Config::get('path') . '/users';
        return is_dir($usersPath) && count(glob($usersPath . '/*/*.yaml')) > 0;
    }

    private function purgeIdentitiesConfig(string $path, bool $dryRun): void
    {
        if (false === is_dir($path)) {
            return;
        }

        $this->logger->notice("Deleting identities directory '{path}' contents.", [
            'path' => $path,
        ]);

        delete_path(path: $path, logger: $this->logger, dryRun: $dryRun);
    }
}
