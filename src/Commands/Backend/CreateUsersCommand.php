<?php

declare(strict_types=1);

namespace App\Commands\Backend;

use App\Backends\Common\ClientInterface as iClient;
use App\Backends\Plex\PlexClient;
use App\Command;
use App\Commands\State\BackupCommand;
use App\Commands\System\TasksCommand;
use App\Libs\Attributes\Route\Cli;
use App\Libs\Config;
use App\Libs\ConfigFile;
use App\Libs\Options;
use Psr\Log\LoggerInterface as iLogger;
use Symfony\Component\Console\Input\InputInterface as iInput;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface as iOutput;
use Throwable;

/**
 * Class CreateUsersCommand
 *
 * This command generates per user backends files, based on the main user configuration.
 *
 * @Routable(command: self::ROUTE)
 */
#[Cli(command: self::ROUTE)]
class CreateUsersCommand extends Command
{
    public const string ROUTE = 'backend:create';

    public function __construct(private iLogger $logger)
    {
        parent::__construct();
    }

    /**
     * Configures the command.
     *
     * @return void
     */
    protected function configure(): void
    {
        $this->setName(self::ROUTE)
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Do not commit any changes.')
            ->addOption(
                're-create',
                'r',
                InputOption::VALUE_NONE,
                'Delete current users configuration files and re-create them.'
            )
            ->addOption('regenerate-tokens', 'g', InputOption::VALUE_NONE, 'Generate new tokens for PLEX users.')
            ->addOption('run', null, InputOption::VALUE_NONE, 'Allow creating the users even if data already exists.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Do not commit any changes.')
            ->addOption(
                'generate-backup',
                'B',
                InputOption::VALUE_NONE,
                'Generate initial backups for the remote user data.'
            )
            ->addOption(
                'update',
                'u',
                InputOption::VALUE_NONE,
                'Override sub users configuration based on main user configuration.'
            )
            ->setDescription('Generate per user configuration, based on the main user data.')
            ->setHelp(
                r(
                    <<<HELP

                    This command create per user configuration files based on the main user backends configuration.

                    ------------------
                    <notice>[ Important info ]</notice>
                    ------------------

                    You must have already configured your main user backends with admin access this means:
                        * For plex: you have admin token
                        * For jellyfin/emby: you have an APIKEY.

                    -------
                    <notice>[ FAQ ]</notice>
                    -------

                    <question># How to map users?</question>

                    Mapping is done automatically based on the username, however, if your users have different usernames
                    on each backend, you can create <value>{path}/config/mapper.yaml</value> file with the following format:

                    version: "1.5"
                    map:
                        # first user
                        -
                          my_plex_server:
                            name: "mike_jones"
                          my_jellyfin_server:
                            name: "jones_mike"
                            options: { }
                          my_emby_server:
                            name: "mikeJones"
                            replace_with: "mike_jones"
                        # second user
                        -
                          my_emby_server:
                            name: "jiji_jones"
                            options: { }
                          my_plex_server:
                            name: "jones_jiji"
                          my_jellyfin_server:
                            name: "jijiJones"
                            replace_with: "jiji_jones"

                    <question># How to regenerate tokens?</question>

                    If you want to regenerate tokens for PLEX users, you can use the <flag>--regenerate-tokens</flag> option.

                    <question># How to update user configuration?</question>

                    If you want to update the user configuration based on the main user configuration, you can use the <flag>--update</flag> option.

                    <question># Do I need to map the main user?</question>

                    No, There is no need, as the main user is already configured.

                    HELP,
                    [
                        'cmd' => trim(commandContext()),
                        'route' => self::ROUTE,
                        'path' => Config::get('path'),
                    ]
                )
            );
    }

    private function purgeUsersConfig(string $path, bool $dryRun): void
    {
        if (false === is_dir($path)) {
            return;
        }

        $this->logger->notice("SYSTEM: Deleting users directory '{path}' contents.", [
            'path' => $path
        ]);

        deletePath(path: $path, logger: $this->logger, dryRun: $dryRun);
    }

    /**
     * Load current user backends.
     *
     * @return array The list of backends.
     */
    private function loadBackends(): array
    {
        $backends = [];
        $supported = Config::get('supported', []);
        $configFile = ConfigFile::open(Config::get('backends_file'), 'yaml');
        $configFile->setLogger($this->logger);

        foreach ($configFile->getAll() as $backendName => $backend) {
            $type = strtolower(ag($backend, 'type', 'unknown'));

            if (!isset($supported[$type])) {
                $this->logger->error("SYSTEM: Ignoring '{backend}'. Unexpected backend type '{type}'.", [
                    'type' => $type,
                    'backend' => $backendName,
                    'types' => implode(', ', array_keys($supported)),
                ]);
                continue;
            }

            if (null === ($url = ag($backend, 'url')) || false === isValidURL($url)) {
                $this->logger->error("SYSTEM: Ignoring '{backend}'. Invalid url '{url}'.", [
                    'url' => $url ?? 'None',
                    'backend' => $backendName,
                ]);
                continue;
            }

            $backend['name'] = $backendName;
            $backend['class'] = $this->getBackend($backendName, $backend)->setLogger($this->logger);
            $backends[$backendName] = $backend;
        }

        return $backends;
    }

    /**
     * Load user mappings from the mapper file.
     *
     * @return array The list of user mappings. or empty array.
     */
    private function loadMappings(): array
    {
        $mapFile = Config::get('mapper_file');

        if (false === file_exists($mapFile) || filesize($mapFile) < 10) {
            return [];
        }

        $map = ConfigFile::open(Config::get('mapper_file'), 'yaml');

        $mapping = $map->get('map', $map->getAll());

        if (empty($mapping)) {
            return [];
        }

        if (false === $map->has('version')) {
            $this->logger->warning("SYSTEM: Starting with mapper.yaml v1.5, the version key is required.");
        }

        if (false === $map->has('map')) {
            $this->logger->warning("SYSTEM: Please upgrade your mapper.yaml file to v1.5 format spec.");
        }

        $this->logger->info("SYSTEM: Mapper file found, using it to map users.", [
            'map' => arrayToString($mapping)
        ]);

        return $mapping;
    }

    /**
     * Get backends users.
     *
     * @param array $backends The list of backends.
     * @param array $map The user mappings.
     *
     * @return array The list of backends users.
     */
    private function get_backends_users(array $backends, array &$map): array
    {
        $users = [];

        foreach ($backends as $backend) {
            /** @var iClient $client */
            $client = ag($backend, 'class');
            assert($backend instanceof iClient);

            $this->logger->info("SYSTEM: Getting users from '{backend}'.", [
                'backend' => $client->getContext()->backendName
            ]);

            try {
                foreach ($client->getUsersList() as $user) {
                    /** @var array $info */
                    $info = $backend;

                    $backedName = ag($backend, 'name');

                    // -- this was source of lots of bugs and confusion for users,
                    // -- we decided to normalize the user-names early in the process.
                    $user['name'] = normalizeName((string)$user['name']);

                    // -- run map actions.
                    $this->map_actions($backedName, $user, $map);

                    // -- If normalization fails, ignore the user.
                    if (false === isValidName($user['name'])) {
                        $this->logger->error(
                            message: "SYSTEM: Invalid user name '{backend}: {name}'. User names must be in [a-z_0-9] format. Skipping user.",
                            context: ['name' => $user['name'], 'backend' => $backedName]
                        );
                        continue;
                    }

                    // -- user here refers to user_id not the name.
                    $info['user'] = ag($user, 'id', ag($info, 'user'));

                    // -- The display name is used to create user directory.
                    $info['displayName'] = $user['name'];

                    $info['backendName'] = normalizeName(r("{backend}_{user}", [
                        'backend' => $backedName,
                        'user' => $user['name']
                    ]));

                    if (false === isValidName($info['backendName'])) {
                        $this->logger->error(
                            message: "SYSTEM: Invalid backend name '{name}'. Backend name must be in [a-z_0-9] format. skipping the associated users.",
                            context: ['name' => $info['backendName']]
                        );
                        continue;
                    }

                    $info = ag_delete($info, 'options.' . Options::PLEX_USER_PIN);
                    $info = ag_sets($info, [
                        'options.' . Options::ALT_NAME => ag($backend, 'name'),
                        'options.' . Options::ALT_ID => ag($backend, 'user')
                    ]);

                    // -- Of course, Plex has to be special.
                    if (PlexClient::CLIENT_NAME === ucfirst(ag($backend, 'type'))) {
                        $info = ag_sets($info, [
                            'token' => 'reuse_or_generate_token',
                            'options.' . Options::PLEX_USER_NAME => ag($user, 'name'),
                            'options.' . Options::PLEX_USER_UUID => ag($user, 'uuid'),
                            'options.' . Options::ADMIN_TOKEN => ag(
                                array: $backend,
                                path: ['options.' . Options::ADMIN_TOKEN, 'token']
                            )
                        ]);
                        if (true === (bool)ag($user, 'guest', false)) {
                            $info = ag_set($info, 'options.' . Options::PLEX_EXTERNAL_USER, true);
                        }
                    }

                    $user = ag_sets($user, ['backend' => $backedName, 'client_data' => $info]);
                    $users[] = $user;
                }
            } catch (Throwable $e) {
                $this->logger->error(
                    "Exception '{error.kind}' was thrown unhandled during '{client}: {user}@{backend}' get users list. '{error.message}' at '{error.file}:{error.line}'.",
                    [
                        'client' => $client->getContext()->clientName,
                        'backend' => $client->getContext()->backendName,
                        'user' => $client->getContext()->userContext->name,
                        'error' => [
                            'kind' => $e::class,
                            'line' => $e->getLine(),
                            'message' => $e->getMessage(),
                            'file' => after($e->getFile(), ROOT_PATH),
                        ],
                        'exception' => [
                            'file' => $e->getFile(),
                            'line' => $e->getLine(),
                            'kind' => get_class($e),
                            'message' => $e->getMessage(),
                        ],
                    ]
                );
            }
        }

        return $users;
    }

    /**
     * Create user configuration files.
     *
     * @param iInput $input The input interface.
     * @param array $users The list of users to create.
     *
     * @return void
     */
    private function create_user(iInput $input, array $users): void
    {
        $dryRun = (bool)$input->getOption('dry-run');
        $updateUsers = (bool)$input->getOption('update');
        $regenerateTokens = (bool)$input->getOption('regenerate-tokens');
        $generateBackups = (bool)$input->getOption('generate-backup');
        $isReCreate = (bool)$input->getOption('re-create');

        foreach ($users as $user) {
            // -- User subdirectory name.
            $userName = normalizeName(ag($user, 'name', 'unknown'));

            if (false === isValidName($userName)) {
                $this->logger->error(
                    message: "SYSTEM: Invalid username '{user}'. User names must be in [a-z_0-9] format. skipping user.",
                    context: ['user' => $userName]
                );
                continue;
            }

            $subUserPath = r(fixPath(Config::get('path') . '/users/{user}'), ['user' => $userName]);

            $this->logger->info(
                false === is_dir(
                    $subUserPath
                ) ? "SYSTEM: Creating '{user}' directory '{path}'." : "SYSTEM: '{user}' directory '{path}' already exists.",
                [
                    'user' => $userName,
                    'path' => $subUserPath,
                ]
            );

            if (false === $dryRun && false === is_dir($subUserPath) && false === mkdir($subUserPath, 0755, true)) {
                $this->logger->error("SYSTEM: Failed to create '{user}' directory '{path}'.", [
                    'user' => $userName,
                    'path' => $subUserPath
                ]);
                continue;
            }

            $config_file = "{$subUserPath}/servers.yaml";
            $this->logger->notice(
                file_exists(
                    $config_file
                ) ? "SYSTEM: '{user}' configuration file '{file}' already exists." : "SYSTEM: Creating '{user}' configuration file '{file}'.",
                [
                    'user' => $userName,
                    'file' => $config_file
                ]
            );

            $perUser = ConfigFile::open(
                file: $dryRun ? "php://memory" : $config_file,
                type: 'yaml',
                autoSave: !$dryRun,
                autoCreate: !$dryRun,
                autoBackup: !$dryRun
            );

            $perUser->setLogger($this->logger);

            foreach (ag($user, 'backends', []) as $backend) {
                $name = ag($backend, 'client_data.backendName');

                if (false === isValidName($name)) {
                    $this->logger->error(
                        message: "SYSTEM: Invalid backend name '{name}'. Backend name must be in [a-z_0-9] format. skipping backend.",
                        context: ['name' => $name]
                    );
                    continue;
                }

                $clientData = ag_delete(ag($backend, 'client_data'), 'class');
                $clientData['name'] = $name;

                if (false === $perUser->has($name)) {
                    $data = $clientData;
                    $data = ag_sets($data, ['import.lastSync' => null, 'export.lastSync' => null]);
                    $data = ag_delete($data, ['webhook', 'name', 'backendName', 'displayName']);
                    $perUser->set($name, $data);
                } else {
                    $clientData = ag_delete($clientData, ['token', 'import.lastSync', 'export.lastSync']);
                    $clientData = array_replace_recursive($perUser->get($name), $clientData);
                    if (true === $updateUsers) {
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
                            $update['options.' . Options::PLEX_USER_NAME] = $userName;
                            $update['options.' . Options::PLEX_USER_UUID] = ag($backend, 'uuid');

                            if (null !== ($val = ag($backend, 'client_data.options.' . Options::PLEX_EXTERNAL_USER))) {
                                $update['options.' . Options::PLEX_EXTERNAL_USER] = (bool)$val;
                            }

                            if (null !== ($val = ag($backend, 'client_data.options.use_old_progress_endpoint'))) {
                                $update['options.use_old_progress_endpoint'] = $val;
                            }
                            $adminToken = ag($backend, [
                                'client_data.options.' . Options::ADMIN_TOKEN,
                                'client_data.token'
                            ]);
                            $update['options.' . Options::ADMIN_TOKEN] = $adminToken;
                        }

                        $this->logger->info("SYSTEM: Updating user configuration for '{user}@{name}' backend.", [
                            'name' => $name,
                            'user' => $userName,
                        ]);

                        foreach ($update as $key => $value) {
                            $perUser->set("{$name}.{$key}", $value);
                        }
                    }
                }

                try {
                    if (true === $regenerateTokens || 'reuse_or_generate_token' === ag($clientData, 'token')) {
                        /** @var iClient $client */
                        $client = ag($backend, 'client_data.class');
                        assert($client instanceof iClient);
                        if (PlexClient::CLIENT_NAME === $client->getType()) {
                            $requestOpts = [];
                            if (ag($clientData, 'options.' . Options::PLEX_EXTERNAL_USER, false)) {
                                $requestOpts[Options::PLEX_EXTERNAL_USER] = true;
                            }
                            $token = $client->getUserToken(
                                userId: ag($clientData, 'options.' . Options::PLEX_USER_UUID),
                                username: ag($clientData, 'options.' . Options::PLEX_USER_NAME),
                                opts: $requestOpts,
                            );
                            if (false === $token) {
                                $this->logger->error(
                                    message: "Failed to generate access token for '{user}@{backend}' backend.",
                                    context: ['user' => $userName, 'backend' => $name]
                                );
                            } else {
                                $perUser->set("{$name}.token", $token);
                            }
                        }
                    }
                } catch (Throwable $e) {
                    $this->logger->error(
                        message: "Failed to generate access token for '{user}@{name}' backend. {error} at '{file}:{line}'.",
                        context: [
                            'name' => $name,
                            'user' => $userName,
                            'error' => [
                                'kind' => $e::class,
                                'line' => $e->getLine(),
                                'message' => $e->getMessage(),
                                'file' => after($e->getFile(), ROOT_PATH),
                            ],
                            'exception' => [
                                'file' => $e->getFile(),
                                'line' => $e->getLine(),
                                'kind' => get_class($e),
                                'message' => $e->getMessage(),
                            ],
                        ]
                    );
                    continue;
                }
            }

            $dbFile = $subUserPath . "/user.db";
            $this->logger->notice(
                file_exists(
                    $dbFile
                ) ? "SYSTEM: '{user}' database file '{db}' already exists." : "SYSTEM: Creating '{user}' database file '{db}'.",
                [
                    'user' => $userName,
                    'db' => $dbFile
                ]
            );

            if (false === $dryRun) {
                if (false === file_exists($dbFile)) {
                    perUserDb($userName);
                }

                $perUser->persist();
            }

            if (true === $generateBackups && false === $isReCreate) {
                $this->logger->notice("SYSTEM: Queuing event to backup '{user}' remote watch state.", [
                    'user' => $userName
                ]);

                if (false === $dryRun) {
                    queueEvent(TasksCommand::CNAME, [
                        'command' => BackupCommand::ROUTE,
                        'args' => ['-v', '-u', $userName, '--file', '{user}.{backend}.{date}.initial_backup.json'],
                    ]);
                }
            }
        }
    }

    /**
     * Executes the command.
     *
     * @param iInput $input The input interface.
     * @param iOutput $output The output interface.
     *
     * @return int The exit code. 0 for success, 1 for failure.
     */
    protected function runCommand(iInput $input, iOutput $output): int
    {
        if (true === ($dryRun = $input->getOption('dry-run'))) {
            $this->logger->notice('SYSTEM: Running in dry-run mode. No changes will be made.');
        }

        $usersPath = Config::get('path') . '/users';
        $hasConfig = is_dir($usersPath) && count(glob($usersPath . '/*/*.yaml')) > 0;

        if ($hasConfig && (false === $input->getOption('run') && false === $input->getOption('re-create'))) {
            $output->writeln(
                <<<Text
                <error>ERROR:</error> Users configuration already exists.

                If you want to re-create the users configuration, run the same command with [<flag>-r, --re-create</flag>] flag, This will do the following:

                1. Delete the current sub-users configuration and data.
                2. Re-create the sub-users configuration.

                Otherwise, you can use the [<flag>--run</flag>] to keep current configuration and update it with the new users.
                <value>
                Beware, we have recently changed how we do matching, most likely if you run without re-creating the configuration.
                it will result in double users for same user or more.

                -------
                
                We suggest to re-create the configuration. If you generated your users before date 2025-04-06.
                </notice>
                Text
            );
            return self::FAILURE;
        }

        if (true === $input->getOption('re-create')) {
            $this->purgeUsersConfig($usersPath, $dryRun);
        }

        $backends = $this->loadBackends();

        if (empty($backends)) {
            $this->logger->error('SYSTEM: No valid backends were found.');
            return self::FAILURE;
        }

        $mapping = $this->loadMappings();

        $this->logger->notice("SYSTEM: Getting users list from '{backends}'.", [
            'backends' => join(', ', array_keys($backends))
        ]);

        $backendsUser = $this->get_backends_users($backends, $mapping);

        if (count($backendsUser) < 1) {
            $this->logger->error('SYSTEM: No Backend users were found.');
            return self::FAILURE;
        }

        $users = $this->generate_users_list($backendsUser, $mapping);

        if (count($users) < 1) {
            $this->logger->warning("We weren't able to match any users across backends.");
            return self::FAILURE;
        }

        $this->logger->notice("SYSTEM: User matching results {results}.", [
            'results' => arrayToString($this->usersList($users)),
        ]);

        $this->create_user(input: $input, users: $users);

        return self::SUCCESS;
    }

    /**
     * Generate a list of users that are matched across all backends.
     *
     * @param array $users The list of users from all backends.
     * @param array{string: array{string: string, options: array}} $map The map of users to match.
     *
     * @return array{name: string, backends: array<string, array<string, mixed>>}[] The list of matched users.
     */
    private function generate_users_list(array $users, array $map = []): array
    {
        $allBackends = [];
        foreach ($users as $u) {
            if (!in_array($u['backend'], $allBackends, true)) {
                $allBackends[] = $u['backend'];
            }
        }

        // Build a lookup: $usersBy[backend][lowercased_name] = userObject
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

        $results = [];

        // Track used combos: array of [backend, nameLower].
        $used = [];

        // Helper: check if a (backend, nameLower) is already used.
        $alreadyUsed = fn($b, $n): bool => in_array([$b, $n], $used, true);

        /**
         * Build a "unified" row from matched users across backends.
         * - $backendDict example: [ 'backend1' => userObj, 'backend2' => userObj, ... ]
         * - Picks a 'name' by "most frequent name" logic (with tie fallback).
         *
         * Returns an array shaped like:
         * <code language="php">
         * return [
         *   'name'     => 'something',
         *   'backends' => [
         *     'backend1' => userObj,
         *     'backend2' => userObj,
         *     ...,
         *   ]
         * ]
         * </code>
         */
        $buildUnifiedRow = function (array $backendDict) use ($allBackends): array {
            // Collect the names in the order of $allBackends for tie-breaking.
            $names = [];
            foreach ($allBackends as $b) {
                if (isset($backendDict[$b])) {
                    $names[] = $backendDict[$b]['name'];
                }
            }

            // Tally frequencies
            $freq = [];
            foreach ($names as $n) {
                if (!isset($freq[$n])) {
                    $freq[$n] = 0;
                }
                $freq[$n]++;
            }

            // Decide a final 'name'
            if (empty($freq)) {
                $finalName = 'unknown';
            } else {
                $max = max($freq);
                $candidates = array_keys(array_filter($freq, fn($count) => $count === $max));

                if (1 === count($candidates)) {
                    $finalName = $candidates[0];
                } else {
                    // Tie => pick the first from $names that’s in $candidates
                    $finalName = null;
                    foreach ($names as $n) {
                        if (in_array($n, $candidates, true)) {
                            $finalName = $n;
                            break;
                        }
                    }
                    if (!$finalName) {
                        $finalName = 'unknown';
                    }
                }
            }

            // Build final row: "name" + sub-array "backends"
            $row = [
                'name' => strtolower($finalName),
                'backends' => [],
            ];

            // Fill 'backends'
            foreach ($allBackends as $b) {
                if (isset($backendDict[$b])) {
                    $row['backends'][$b] = $backendDict[$b];
                }
            }

            return $row;
        };

        // Main logic: For each backend and each user in that backend, unify them if we find a match in ≥2 backends.
        // We do map-based matching first, then direct-name matching.
        foreach ($allBackends as $backend) {
            if (!isset($usersBy[$backend])) {
                continue;
            }

            // For each user in this backend
            foreach ($usersBy[$backend] as $nameLower => $userObj) {
                // Skip if already used
                if ($alreadyUsed($backend, $nameLower)) {
                    continue;
                }

                // Map-based matching first
                $matchedMapEntry = null;
                foreach ($map as $mapRow) {
                    if (ag($mapRow, "{$backend}.name") === $nameLower) {
                        $this->logger->notice("Mapper: Found map entry for '{backend}: {user}'", [
                            'backend' => $backend,
                            'user' => $nameLower,
                            'map' => $mapRow,
                        ]);
                        $matchedMapEntry = $mapRow;
                        break;
                    }
                }

                if ($matchedMapEntry) {
                    // Build mapMatch from the map row.
                    $mapMatch = [$backend => $userObj];

                    // Gather all the other backends from the map
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

                    // If we matched ≥ 2 backends, unify them
                    if (count($mapMatch) >= 2) {
                        // --- MERGE map-based "options" into client_data => options, if any ---
                        foreach ($mapMatch as $b => &$matchedUser) {
                            // If the map entry has an 'options' array for this backend,
                            // merge it into $matchedUser['client_data']['options'].
                            if (isset($matchedMapEntry[$b]['options']) && is_array($matchedMapEntry[$b]['options'])) {
                                $mapOptions = $matchedMapEntry[$b]['options'];

                                // Ensure $matchedUser['client_data'] is an array
                                if (!isset($matchedUser['client_data']) || !is_array($matchedUser['client_data'])) {
                                    $matchedUser['client_data'] = [];
                                }

                                // Ensure $matchedUser['client_data']['options'] is an array
                                if (!isset($matchedUser['client_data']['options']) || !is_array(
                                        $matchedUser['client_data']['options']
                                    )) {
                                    $matchedUser['client_data']['options'] = [];
                                }

                                // Merge the map's options
                                $matchedUser['client_data']['options'] = array_replace_recursive(
                                    $matchedUser['client_data']['options'],
                                    $mapOptions
                                );
                            }
                        }
                        unset($matchedUser); // break reference from the loop

                        // Build final row
                        $results[] = $buildUnifiedRow($mapMatch);

                        // Mark & remove from $usersBy
                        foreach ($mapMatch as $b => $mu) {
                            $nm = strtolower($mu['name']);
                            $used[] = [$b, $nm];
                            unset($usersBy[$b][$nm]);
                        }
                        continue;
                    } else {
                        $this->logger->error("No partial fallback match via map for '{backend}: {user}'", [
                            'backend' => $userObj['backend'],
                            'user' => $userObj['name'],
                        ]);
                    }
                }

                // Direct-name matching if map fails
                $directMatch = [$backend => $userObj];
                foreach ($allBackends as $otherBackend) {
                    if ($otherBackend === $backend) {
                        continue;
                    }
                    // Same name => direct match
                    if (isset($usersBy[$otherBackend][$nameLower])) {
                        $directMatch[$otherBackend] = $usersBy[$otherBackend][$nameLower];
                    }
                }

                // If direct matched ≥ 2 backends, unify
                if (count($directMatch) >= 2) {
                    // No map "options" to merge here
                    $results[] = $buildUnifiedRow($directMatch);

                    // Mark & remove them from $usersBy
                    foreach ($directMatch as $b => $matchedUser) {
                        $nm = strtolower($matchedUser['name']);
                        $used[] = [$b, $nm];
                        unset($usersBy[$b][$nm]);
                    }
                    continue;
                }

                // If neither map nor direct matched for ≥2
                $this->logger->error("No other users were found that match '{backend}: {user}'.", [
                    'backend' => $userObj['backend'],
                    'user' => $userObj['name'],
                    'map' => arrayToString($map),
                    'list' => arrayToString($usersList),
                ]);
            }
        }

        return $results;
    }

    private function usersList(array $list): array
    {
        $chunks = [];

        foreach ($list as $row) {
            $name = $row['name'] ?? 'unknown';

            $pairs = [];
            if (!empty($row['backends']) && is_array($row['backends'])) {
                foreach ($row['backends'] as $backendName => $backendData) {
                    if (isset($backendData['name'])) {
                        $pairs[] = r("{name}@{backend}", ['backend' => $backendName, 'name' => $backendData['name']]);
                    }
                }
            }

            $chunks[] = r("{name}: {pairs}", ['name' => $name, 'pairs' => implode(', ', $pairs)]);
        }

        return $chunks;
    }

    /**
     * Run actions on the user data, early.
     *
     * @param array $user The remote user data.
     * @param string $backend The backend name.
     * @param array $mapping the mapper file data.
     *
     * - my_plex_server:
     *      name: "mike_jones"
     *      options: { }
     *   my_jellyfin_server:
     *      name: "jones_mike"
     *      options: { }
     *   my_emby_server:
     *      name: "mikeJones"
     *      replace_with: "mike_jones"
     *      options: { }
     * ```
     */
    private function map_actions(string $backend, array &$user, array &$mapping): void
    {
        if (null === ($username = ag($user, 'name'))) {
            $this->logger->error("MAPPER: No username was given from one user of '{backend}' backend.", [
                'backend' => $backend
            ]);
            return;
        }

        $hasMapping = array_filter($mapping, fn($map) => array_key_exists($backend, $map));
        if (count($hasMapping) < 1) {
            $this->logger->info("MAPPER: No mapping exists for '{backend}' backend.", [
                'backend' => $backend
            ]);
            return;
        }

        $found = false;
        $user_map = [];

        foreach ($mapping as &$map) {
            foreach ($map as $map_backend => &$loop_map) {
                if ($backend !== $map_backend) {
                    continue;
                }
                if (ag($loop_map, "name") !== $username) {
                    continue;
                }
                $found = true;
                $user_map = &$loop_map;
                break 2;
            }
        }

        if (false === $found) {
            $this->logger->debug("MAPPER: No map exists for '{backend}: {username}'.", [
                'backend' => $backend,
                'username' => $username
            ]);
            return;
        }

        if (null !== ($newUsername = ag($user_map, 'replace_with'))) {
            if (false === is_string($newUsername) || false === isValidName($newUsername)) {
                $this->logger->error(
                    message: "MAPPER: Failed to replace '{backend}: {username}' with '{backend}: {new_username}' name must be in [a-z_0-9] format.",
                    context: [
                        'backend' => $backend,
                        'username' => $username,
                        'new_username' => $newUsername
                    ]
                );
                return;
            }

            $this->logger->notice(
                message: "MAPPER: Renaming '{backend}: {username}' to '{backend}: {new_username}'.",
                context: [
                    'backend' => $backend,
                    'username' => $username,
                    'new_username' => $newUsername
                ]
            );

            $user['name'] = $newUsername;
            $user_map['name'] = $newUsername;
        }
    }
}
