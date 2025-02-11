<?php

declare(strict_types=1);

namespace App\Commands\Backend;

use App\Backends\Common\ClientInterface as iClient;
use App\Backends\Plex\PlexClient;
use App\Command;
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
            ->addOption('regenerate-tokens', 'g', InputOption::VALUE_NONE, 'Generate new tokens for PLEX users.')
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

                    -   my_plex_server:
                            name: "mike_jones"
                            options: { }
                        my_jellyfin_server:
                            name: "jones_mike"
                            options: { }
                        my_emby_server:
                            name: "mikeJones"
                            options: { }
                    # second user
                    -   my_emby_server:
                            name: "jiji_jones"
                            options: { }
                        my_plex_server:
                            name: "jones_jiji"
                            options: { }
                        my_jellyfin_server:
                            name: "jijiJones"
                            options: { }

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
        $supported = Config::get('supported', []);
        $configFile = ConfigFile::open(Config::get('backends_file'), 'yaml');
        $configFile->setLogger($this->logger);

        $mapFile = Config::get('mapper_file');
        $mapping = [];

        if (file_exists($mapFile) && filesize($mapFile) > 10) {
            $map = ConfigFile::open(Config::get('mapper_file'), 'yaml');
            $mapping = $map->getAll();
        }

        $backends = [];

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

        if (empty($backends)) {
            $this->logger->error('SYSTEM: No valid backends were found.');
            return self::FAILURE;
        }

        $this->logger->notice("SYSTEM: Getting users list from '{backends}'.", [
            'backends' => join(', ', array_keys($backends))
        ]);

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
                    $info['user'] = ag($user, 'id', ag($info, 'user'));
                    $info['backendName'] = r("{backend}_{user}", [
                        'backend' => ag($backend, 'name'),
                        'user' => ag($user, 'name'),
                    ]);
                    $info['displayName'] = ag($user, 'name');
                    $info = ag_delete($info, 'options.' . Options::PLEX_USER_PIN);
                    $info = ag_set($info, 'options.' . Options::ALT_NAME, ag($backend, 'name'));
                    $info = ag_set($info, 'options.' . Options::ALT_ID, ag($backend, 'user'));
                    if (PlexClient::CLIENT_NAME === ucfirst(ag($backend, 'type'))) {
                        $info = ag_set($info, 'token', 'reuse_or_generate_token');
                        $info = ag_set($info, 'options.' . Options::PLEX_USER_NAME, ag($user, 'name'));
                        $info = ag_set($info, 'options.' . Options::PLEX_USER_UUID, ag($user, 'uuid'));
                        if (true === (bool)ag($user, 'guest', false)) {
                            $info = ag_set($info, 'options.' . Options::PLEX_EXTERNAL_USER, true);
                        }
                    }

                    $user['backend'] = ag($backend, 'name');
                    $user['client_data'] = $info;
                    $users[] = $user;
                }
            } catch (Throwable $e) {
                $this->logger->error(
                    "Exception '{error.kind}' was thrown unhandled during '{client}: {backend}' get users list. '{error.message}' at '{error.file}:{error.line}'.",
                    [
                        'backend' => $client->getContext()->backendName,
                        'client' => $client->getContext()->clientName,
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

        $users = $this->generate_users_list($users, $mapping);

        if (count($users) < 1) {
            $this->logger->warning('No users were found.');
            return self::FAILURE;
        }

        $this->logger->notice("SYSTEM: User matching results {results}.", [
            'results' => arrayToString($this->usersList($users)),
        ]);

        foreach ($users as $user) {
            $userName = ag($user, 'name', 'Unknown');

            $subUserPath = r(fixPath(Config::get('path') . '/users/{user}'), ['user' => $userName]);

            if (false === is_dir($subUserPath)) {
                $this->logger->info("SYSTEM: Creating '{user}' directory '{path}'.", [
                    'user' => $userName,
                    'path' => $subUserPath
                ]);
                if (false === mkdir($subUserPath, 0755, true)) {
                    $this->logger->error("SYSTEM: Failed to '{user}' directory '{path}'.", [
                        'user' => $userName,
                        'path' => $subUserPath
                    ]);
                    continue;
                }
            }

            $config_file = "{$subUserPath}/servers.yaml";
            $this->logger->notice("SYSTEM: Creating '{user}' configuration file '{file}'.", [
                'user' => $userName,
                'file' => $config_file
            ]);

            $perUser = ConfigFile::open($config_file, 'yaml', autoCreate: true);
            $perUser->setLogger($this->logger);
            $regenerateTokens = $input->getOption('regenerate-tokens');

            foreach (ag($user, 'backends', []) as $backend) {
                $name = ag($backend, 'client_data.backendName');
                $clientData = ag_delete(ag($backend, 'client_data'), 'class');
                $clientData['name'] = $name;

                if (false === $perUser->has($name)) {
                    $data = $clientData;
                    $data = ag_set($data, 'import.lastSync', null);
                    $data = ag_set($data, 'export.lastSync', null);
                    $data = ag_delete($data, ['webhook', 'name', 'backendName', 'displayName']);
                    $perUser->set($name, $data);
                } else {
                    $clientData = ag_delete($clientData, ['token', 'import.lastSync', 'export.lastSync']);
                    $clientData = array_replace_recursive($perUser->get($name), $clientData);
                    if ($input->getOption('update')) {
                        $this->logger->info("SYSTEM: Updating user configuration for '{user}@{name}' backend.", [
                            'name' => $name,
                            'user' => $userName,
                        ]);
                        $perUser->set($name, $clientData);
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
                            $clientData['token'] = $client->getUserToken(
                                userId: ag($clientData, 'options.' . Options::PLEX_USER_UUID),
                                username: ag($clientData, 'options.' . Options::PLEX_USER_NAME),
                                opts: $requestOpts,
                            );
                            $perUser->set("{$name}.token", $clientData['token']);
                        }
                    }
                } catch (Throwable $e) {
                    $this->logger->error(
                        "Failed to generate access token for '{user}@{name}' backend. '{error}' at '{file}:{line}'.",
                        [
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

            $dbFile = r($subUserPath . "/{user}.db", ['user' => $userName]);
            if (false === file_exists($dbFile)) {
                $this->logger->notice("SYSTEM: Creating '{user}' database '{db}'.", [
                    'user' => $userName,
                    'db' => $dbFile
                ]);
                perUserDb($userName);
            }

            $perUser->persist();
        }

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
        }

        $results = [];

        // Track used combos: array of [backend, nameLower].
        $used = [];

        // Helper: check if a (backend, nameLower) is already used.
        $alreadyUsed = fn(string $b, string $n): bool => in_array([$b, $n], $used, true);

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
                'name' => $finalName,
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
                    if (isset($mapRow[$backend]['name']) && strtolower($mapRow[$backend]['name']) === $nameLower) {
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
                $this->logger->error("Cannot match user '{backend}: {user}' in any map row or direct match.", [
                    'backend' => $userObj['backend'],
                    'user' => $userObj['name']
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

}
