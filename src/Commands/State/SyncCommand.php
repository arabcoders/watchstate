<?php

declare(strict_types=1);

namespace App\Commands\State;

use App\Backends\Common\ClientInterface as iClient;
use App\Command;
use App\Libs\Attributes\Route\Cli;
use App\Libs\Config;
use App\Libs\ConfigFile;
use App\Libs\Entity\StateInterface as iState;
use App\Libs\Extends\StreamLogHandler;
use App\Libs\LogSuppressor;
use App\Libs\Mappers\Import\NullMapper;
use App\Libs\Message;
use App\Libs\Options;
use App\Libs\QueueRequests;
use App\Libs\Stream;
use Monolog\Logger;
use Psr\Log\LoggerInterface as iLogger;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Input\InputInterface as iInput;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface as iOutput;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Throwable;

/**
 * Class ExportCommand
 *
 * Command for exporting play state to backends.
 *
 * @package App\Console\Commands\State
 */
#[Cli(command: self::ROUTE)]
class SyncCommand extends Command
{
    public const string ROUTE = 'state:sync';

    public const string TASK_NAME = 'sync';

    private array $mapping = [];

    /**
     * Class Constructor.
     *
     * @param NullMapper $mapper The instance of the DirectMapper class.
     * @param QueueRequests $queue The instance of the QueueRequests class.
     * @param iLogger $logger The instance of the iLogger class.
     */
    public function __construct(
        private readonly NullMapper $mapper,
        private readonly QueueRequests $queue,
        private readonly iLogger $logger,
        private readonly LogSuppressor $suppressor,
    ) {
        set_time_limit(0);
        ini_set('memory_limit', '-1');
        $this->mapper->setLogger(new NullLogger());
        parent::__construct();
    }

    /**
     * Configure the command.
     */
    protected function configure(): void
    {
        $this->setName(self::ROUTE)
            ->setDescription('Sync All users play state to backends.')
            ->addOption('force-full', 'f', InputOption::VALUE_NONE, 'Force full export. Ignore last export date.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Do not commit changes to backends.')
            ->addOption('timeout', null, InputOption::VALUE_REQUIRED, 'Set request timeout in seconds.')
            ->addOption(
                'select-backend',
                's',
                InputOption::VALUE_IS_ARRAY | InputOption::VALUE_OPTIONAL,
                'Select backend.'
            )
            ->addOption('exclude', null, InputOption::VALUE_NONE, 'Inverse --select-backend logic.')
            ->addOption('ignore-date', 'i', InputOption::VALUE_NONE, 'Ignore date comparison.')
            ->addOption('logfile', null, InputOption::VALUE_REQUIRED, 'Save console output to file.')
            ->setHelp(
                r(
                    <<<HELP

                    pre-alpha command, not ready for production use. it's not working yet as expected,
                    Use it at your own risk.

                    -------
                    <notice>[ FAQ ]</notice>
                    -------

                    <question>Will this work with limited tokens?</question>

                    No, This requires admin token for plex backend, and API keys for jellyfin/emby.
                    We need the admin token for plex to generate user tokens for each user, and we need the API keys
                    for jellyfin/emby to get the user list and update their play state.

                    <question>Known limitations</question>

                    We have some known limitations:
                     * Cannot be used with plex users that have PIN enabled.
                     * Can Only sync played status.
                     * Cannot sync play progress.

                    Some or all of these limitations will be fixed in future releases.

                    <question># How does this sync operation mode work?</question>

                    It works by first, getting all users from all backends, and trying to match them by name,
                    once we build a list of users that are matched, then we basically run the import/export for each user
                    using in memory storage, it should not have any impact on the real database and cache.

                    You can help the matching by using the mapper file, which is a simple YAML file that maps users from one
                    backend to another, this is useful when the usernames are different or when you want to merge users from
                    different backends into one user.

                    Example of a mapper.yaml file:

                    - backend1: "mike_james"
                      backend2: "james_mike"

                    - backend1: "john_doe"
                      backend2: "doe_john"

                    HELP,
                    [
                        'cmd' => trim(commandContext()),
                        'route' => self::ROUTE,

                    ]
                )
            );
    }

    /**
     * Make sure the command is not running in parallel.
     *
     * @param iInput $input The input object containing the command data.
     * @param iOutput $output The output object for displaying command output.
     *
     * @return int The exit code of the command execution.
     */
    protected function runCommand(iInput $input, iOutput $output): int
    {
        return $this->single(fn(): int => $this->process($input, $output), $output);
    }

    /**
     * Process the command by pulling and comparing status and then pushing.
     *
     * @param iInput $input
     * @param iOutput $output
     * @return int
     */
    protected function process(iInput $input, iOutput $output): int
    {
        if (null !== ($logfile = $input->getOption('logfile')) && true === ($this->logger instanceof Logger)) {
            $this->logger->setHandlers([
                $this->suppressor->withHandler(new StreamLogHandler(new Stream($logfile, 'w'), $output))
            ]);
        }

        $mapFile = Config::get('mapper_file');
        if (file_exists($mapFile) && filesize($mapFile) > 10) {
            $map = ConfigFile::open(Config::get('mapper_file'), 'yaml');
            $this->mapping = $map->getAll();
        }

        $configFile = ConfigFile::open(Config::get('backends_file'), 'yaml');
        $configFile->setLogger($this->logger);

        $backends = [];
        $selected = $input->getOption('select-backend');
        $isCustom = !empty($selected) && count($selected) > 0;
        $supported = Config::get('supported', []);

        if (true === $input->getOption('dry-run')) {
            $this->logger->notice('Dry run mode. No changes will be committed to backends.');
        }

        foreach ($configFile->getAll() as $backendName => $backend) {
            $type = strtolower(ag($backend, 'type', 'unknown'));

            if ($isCustom && $input->getOption('exclude') === in_array($backendName, $selected)) {
                $this->logger->info("SYSTEM: Ignoring '{backend}' as requested by [-s, --select-backend].", [
                    'backend' => $backendName
                ]);
                continue;
            }

            if (true !== (bool)ag($backend, 'export.enabled')) {
                $this->logger->info("SYSTEM: Ignoring '{backend}' as the backend has export disabled.", [
                    'backend' => $backendName
                ]);
                continue;
            }

            if (!isset($supported[$type])) {
                $this->logger->error(
                    "SYSTEM: Ignoring '{backend}' due to unexpected type '{type}'. Expecting '{types}'.",
                    [
                        'type' => $type,
                        'backend' => $backendName,
                        'types' => implode(', ', array_keys($supported)),
                    ]
                );
                continue;
            }

            if (null === ($url = ag($backend, 'url')) || false === isValidURL($url)) {
                $this->logger->error("SYSTEM: Ignoring '{backend}' due to invalid URL. '{url}'.", [
                    'url' => $url ?? 'None',
                    'backend' => $backendName,
                ]);
                continue;
            }

            $backend['name'] = $backendName;
            $backends[$backendName] = $backend;
        }

        if (empty($backends)) {
            $this->logger->warning('No backends were found.');
            return self::FAILURE;
        }

        foreach ($backends as &$backend) {
            if (null === ($name = ag($backend, 'name'))) {
                continue;
            }

            $opts = ag($backend, 'options', []);

            if ($input->getOption('ignore-date')) {
                $opts[Options::IGNORE_DATE] = true;
            }

            if ($input->getOption('trace')) {
                $opts[Options::DEBUG_TRACE] = true;
            }

            if ($input->getOption('dry-run')) {
                $opts[Options::DRY_RUN] = true;
            }

            if ($input->getOption('timeout')) {
                $opts['client']['timeout'] = $input->getOption('timeout');
            }

            $backend['options'] = $opts;
            $backend['class'] = $this->getBackend($name, $backend)->setLogger($this->logger);
        }

        unset($backend);

        $this->logger->notice("SYSTEM: Getting users list from '{backends}'.", [
                'backends' => join(', ', array_map(fn($backend) => $backend['name'], $backends))
            ]
        );

        $users = [];

        foreach ($backends as $backend) {
            /** @var iClient $client */
            $client = ag($backend, 'class');
            assert($backend instanceof iClient);
            $this->logger->info("SYSTEM: Getting users from '{backend}'.", [
                'backend' => $client->getContext()->backendName
            ]);
            try {
                foreach ($client->getUsersList(['tokens' => true]) as $user) {
                    $info = $backend;
                    $info['token'] = ag($user, 'token', ag($backend, 'token'));
                    $info['user'] = ag($user, 'id', ag($info, 'user'));
                    $info['backendName'] = r("{backend}_{user}", [
                        'backend' => ag($backend, 'name'),
                        'user' => ag($user, 'name'),
                    ]);
                    $info['displayName'] = ag($user, 'name');
                    $info = ag_delete($info, 'options.' . Options::PLEX_USER_PIN);
                    $info = ag_delete($info, 'options.' . Options::ADMIN_TOKEN);
                    $info = ag_set($info, 'options.' . Options::ALT_NAME, ag($backend, 'name'));

                    unset($info['class']);
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

        $users = $this->generate_users_list($users, $this->mapping);

        if (count($users) < 1) {
            $this->logger->warning('No users were found.');
            return self::FAILURE;
        }

        $this->logger->notice("SYSTEM: User matching results {results}.", [
            'results' => arrayToString($this->usersList($users)),
        ]);

        foreach (array_reverse($users) as $user) {
            $this->queue->reset();
            $this->mapper->reset();

            $list = [];
            $displayName = null;

            foreach (ag($user, 'backends', []) as $backend) {
                $name = ag($backend, 'client_data.backendName');
                $clientData = ag($backend, 'client_data');
                $clientData['name'] = $name;
                $clientData['class'] = makeBackend($clientData, $name)->setLogger($this->logger);
                $list[$name] = $clientData;
                $displayName = ag($backend, 'client_data.displayName', '??');
            }

            $start = makeDate();
            $this->logger->notice("SYSTEM: Syncing user '{user}' -> '{list}'.", [
                'user' => $displayName,
                'list' => join(', ', array_keys($list)),
                'started' => $start,
            ]);

            $this->handleImport($displayName, $list);

            $changes = $this->mapper->computeChanges(array_keys($list));

            foreach ($changes as $b => $changed) {
                $count = count($changed);
                $this->logger->notice("SYSTEM: Changes detected for '{name}: {backend}' are '{changes}'.", [
                    'name' => $displayName,
                    'backend' => $b,
                    'changes' => $count,
                    'items' => array_map(
                        fn(iState $i) => [
                            'title' => $i->getName(),
                            'state' => $i->isWatched() ? 'played' : 'unplayed',
                            'meta' => $i->isSynced(array_keys($list)),
                        ],
                        $changed
                    )
                ]);

                if ($count >= 1) {
                    /** @var iClient $client */
                    $client = $list[$b]['class'];
                    $client->updateState($changed, $this->queue);
                }
            }

            $this->handleExport($displayName);

            $end = makeDate();
            $this->logger->notice("SYSTEM: Completed syncing user '{name}' -> '{list}' in '{time.duration}'s", [
                'name' => $displayName,
                'list' => join(', ', array_keys($list)),
                'time' => [
                    'start' => $start,
                    'end' => $end,
                    'duration' => $end->getTimestamp() - $start->getTimestamp(),
                ],
                'memory' => [
                    'now' => getMemoryUsage(),
                    'peak' => getPeakMemoryUsage(),
                ],
            ]);
            exit(1);
        }

        return self::SUCCESS;
    }

    protected function handleImport(string $name, array $backends): void
    {
        /** @var array<array-key,ResponseInterface> $queue */
        $queue = [];

        foreach ($backends as $backend) {
            /** @var iClient $client */
            $client = ag($backend, 'class');
            array_push($queue, ...$client->pull($this->mapper));
        }

        $start = makeDate();
        $this->logger->notice("SYSTEM: Waiting on '{total}' requests for import '{name}' data.", [
            'name' => $name,
            'total' => number_format(count($queue)),
            'time' => [
                'start' => $start,
            ],
            'memory' => [
                'now' => getMemoryUsage(),
                'peak' => getPeakMemoryUsage(),
            ],
        ]);

        foreach ($queue as $_key => $response) {
            $requestData = $response->getInfo('user_data');

            try {
                $requestData['ok']($response);
            } catch (Throwable $e) {
                $requestData['error']($e);
            }

            $queue[$_key] = null;

            gc_collect_cycles();
        }

        $end = makeDate();
        $this->logger->notice(
            "SYSTEM: Completed waiting on '{total}' requests in '{time.duration}'s for importing '{name}' data. Parsed '{responses.size}' of data.",
            [
                'name' => $name,
                'total' => number_format(count($queue)),
                'time' => [
                    'start' => $start,
                    'end' => $end,
                    'duration' => $end->getTimestamp() - $start->getTimestamp(),
                ],
                'memory' => [
                    'now' => getMemoryUsage(),
                    'peak' => getPeakMemoryUsage(),
                ],
                'responses' => [
                    'size' => fsize((int)Message::get('response.size', 0)),
                ],
            ]
        );

        Message::add('response.size', 0);
    }

    protected function handleExport(string $name): void
    {
        $total = count($this->queue->getQueue());
        if ($total < 1) {
            $this->logger->notice("SYSTEM: No play state changes detected for '{name}' backends.", ['name' => $name]);
            return;
        }

        $this->logger->notice("SYSTEM: Sending '{total}' change play state requests for '{name}'.", [
            'name' => $name,
            'total' => $total
        ]);

        foreach ($this->queue->getQueue() as $response) {
            $context = ag($response->getInfo('user_data'), 'context', []);

            try {
                if (200 !== ($statusCode = $response->getStatusCode())) {
                    $this->logger->error(
                        "Request to change '{name}: {backend}' '{item.title}' play state returned with unexpected '{status_code}' status code.",
                        [
                            'name' => $name,
                            'status_code' => $statusCode,
                            ...$context,
                        ],
                    );
                    continue;
                }

                $this->logger->notice("Marked '{name}: {backend}' '{item.title}' as '{play_state}'.", [
                    'name' => $name,
                    ...$context
                ]);
            } catch (Throwable $e) {
                $this->logger->error(
                    message: "Exception '{error.kind}' was thrown unhandled during '{name}: {backend}' request to change play state of {item.type} '{item.title}'. '{error.message}' at '{error.file}:{error.line}'.",
                    context: [
                        'name' => $name,
                        'error' => [
                            'kind' => $e::class,
                            'line' => $e->getLine(),
                            'message' => $e->getMessage(),
                            'file' => after($e->getFile(), ROOT_PATH),
                        ],
                        ...$context,
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

        $this->logger->notice("SYSTEM: Sent '{total}' change play state requests for '{name}'.", [
            'name' => $name,
            'total' => $total
        ]);
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
         *     ...
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
