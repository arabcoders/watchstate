<?php

declare(strict_types=1);

namespace App\Commands\State;

use App\Command;
use App\Commands\Backend\Library\UnmatchedCommand;
use App\Commands\Config\EditCommand;
use App\Libs\Attributes\DI\Inject;
use App\Libs\Attributes\Route\Cli;
use App\Libs\Config;
use App\Libs\Container;
use App\Libs\Database\DatabaseInterface;
use App\Libs\Entity\StateInterface as iState;
use App\Libs\Extends\StreamLogHandler;
use App\Libs\LogSuppressor;
use App\Libs\Mappers\Import\DirectMapper;
use App\Libs\Mappers\Import\MemoryMapper;
use App\Libs\Mappers\ImportInterface as iImport;
use App\Libs\Message;
use App\Libs\Options;
use App\Libs\Stream;
use App\Libs\UserContext;
use Monolog\Level;
use Monolog\Logger;
use Psr\Log\LoggerInterface as iLogger;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Helper\TableSeparator;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Throwable;

/**
 * Class ImportCommand
 *
 * This command imports metadata and play state of items from backends and updates the local database.
 */
#[Cli(command: self::ROUTE)]
class ImportCommand extends Command
{
    public const string ROUTE = 'state:import';

    public const string TASK_NAME = 'import';

    /**
     * Class Constructor.
     *
     * @param iImport $mapper The import interface object.
     * @param iLogger $logger The logger interface object.
     * @param LogSuppressor $suppressor The log suppressor object.
     *
     */
    public function __construct(
        #[Inject(MemoryMapper::class)]
        private iImport $mapper,
        private iLogger $logger,
        private LogSuppressor $suppressor
    ) {
        set_time_limit(0);
        ini_set('memory_limit', '-1');

        parent::__construct();
    }

    /**
     * Configure the method.
     */
    protected function configure(): void
    {
        $this->setName(self::ROUTE)
            ->setDescription('Import play state and metadata from backends.')
            ->addOption('force-full', 'f', InputOption::VALUE_NONE, 'Force full import. Ignore last sync date.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Do not commit any changes.')
            ->addOption('timeout', null, InputOption::VALUE_REQUIRED, 'Set request timeout in seconds.')
            ->addOption('user', 'u', InputOption::VALUE_REQUIRED, 'Select sub user. Default all users.')
            ->addOption(
                'select-backend',
                's',
                InputOption::VALUE_IS_ARRAY | InputOption::VALUE_OPTIONAL,
                'Select backend.'
            )
            ->addOption('exclude', null, InputOption::VALUE_NONE, 'Inverse --select-backend logic.')
            ->addOption(
                'direct-mapper',
                null,
                InputOption::VALUE_NONE,
                'Direct mapper is memory efficient, However its slower than the default mapper.'
            )
            ->addOption(
                'metadata-only',
                null,
                InputOption::VALUE_NONE,
                'import metadata changes only. Works when there are records in database.'
            )
            ->addOption(
                'always-update-metadata',
                null,
                InputOption::VALUE_NONE,
                'Mapper option. Always update the locally stored metadata from backend.'
            )
            ->addOption('show-messages', null, InputOption::VALUE_NONE, 'Show internal messages.')
            ->addOption('logfile', null, InputOption::VALUE_REQUIRED, 'Save console output to file.')
            ->setHelp(
                r(
                    <<<HELP

                    This command import <notice>metadata</notice> and the <notice>play state</notice> of items from backends.

                    ------------------
                    <notice>[ Important info ]</notice>
                    ------------------

                    You MUST import the metadata as minimum to have efficient push/export.

                    -------
                    <notice>[ FAQ ]</notice>
                    -------

                    <question># How to import from specific backend?</question>

                    {cmd} <cmd>{route}</cmd> <flag>-s</flag> <value>backend_name</value>

                    <question># How to Import the metadata only?</question>

                    {cmd} <cmd>{route}</cmd> <flag>--metadata-only</flag>

                    <question># Import is failing due to timeout?</question>

                    If you want to permanently increase the <notice>timeout</notice> for specific backend, you can do the following

                    {cmd} <cmd>{config_edit}</cmd> <flag>-k</flag> <value>options.client.timeout</value> <flag>-e</flag> <value>600.0</value> <flag>-s</flag> <value>backend_name</value>

                    <value>600.0</value> seconds is equal to 10 minutes before the timeout handler kicks in. Alternatively, you can also increase the
                    timeout temporarily by using the [<flag>--timeout</flag>] flag. It will increase the timeout for all backends during this run.

                    {cmd} <cmd>{route}</cmd> <flag>--timeout</flag> <value>600.0</value>

                    <question># Import is failing due to memory constraint?</question>

                    This is a tricky situation, the default mapper we use is a memory mapper that load the entire state into memory, However
                    This may not be possible for memory constraint systems, You can use the alternative mapper implementation, it's less
                    memory hungry. However, it is slower than the default mapper. to use alternative mapper you can do the following:

                    {cmd} <cmd>{route}</cmd> <flag>--direct-mapper</flag>

                    <question># Run import to see changes without altering the database?</question>

                    Most important commands have [<flag>--dry-run</flag>] flag. This flag signal that the changes will not be committed if the flag is used.
                    To see the changes that will happen during an import run you could for example run the following command

                    {cmd} <cmd>{route}</cmd> <flag>--dry-run</flag> <flag>-vvv</flag>

                    <question># Import command does not show any output?</question>

                    By default commands only show log level <value>WARNING</value> and higher, to see more verbose output
                    You can use the [<flag>-v|-vv|-vvv</flag>] flag to signal that you want more output. And you can enable
                    even more info by using [<flag>--debug</flag>] flag. Be warned the output is quite excessive
                    and shouldn't be used unless told by the team.

                    {cmd} <cmd>{route}</cmd> <flag>-vvv --trace --context</flag>

                    <question># The Import operation keep updating some items repeatedly even when play state did not change?</question>

                    This most likely means your media backends have conflicting external ids for the reported items, and thus triggering an
                    Update as the importer see different external ids on each item from backends. you could diagnose the problem by
                    viewing each item and comparing the external ids being reported. This less likely to happen if you have parsing
                    external guids for episodes disabled by using the environment variable [<flag>WS_EPISODES_DISABLE_GUID</flag>=<value>1</value>].

                    <question># "No valid/supported external ids." in logs?</question>

                    This most likely means that the item is not matched in your media backend.

                    For [<comment>Movies</comment>] check the following:

                    [<info>jellyfin/emby</info>]: Go to the movie, click edit metadata and make sure there are external ids listed.
                    [<info>Plex</info>]: Go to the movie, click the (...), and click view info, then click view xml and look for tag called [<info>Guid</info>] tag.

                    For [<comment>Series</comment>] check the following:

                    [<info>jellyfin/emby</info>]: Go to the series, click edit metadata and make sure there are external ids listed.
                    [<info>Plex</info>]: Go to the series, click the (...), then click view info, then click view xml and look for tag called [<info>Guid</info>] tag.

                    or you could use the built-in unmatched checker.

                    {cmd} <cmd>{unmatched_route}</cmd> <flag>-s</flag> <value>backend_name</value>

                    If you don't have any unmatched items, this likely means you are using unsupported external db ids.

                    <question># I removed the database and the import command is not importing the metadata again?</question>

                    This is caused by the key [<value>import.lastSync</value>] found in [<value>servers.yaml</value>]. You have to bypass it
                    by using [<flag>-f</flag>, <flag>--force-full</flag>] flag. This flag will cause the importer to not consider the last
                    import date and instead import all the items.

                    {cmd} <cmd>{route}</cmd> <flag>-force-full</flag>

                    <question># My new backend overriding my old backend state / My watch state is not correct?</question>

                    This likely due to the new backend reporting newer date than your old backend. as such the typical setup is to
                    prioritize items with newer date compared to old ones. This is what you want to happen normally. However, if the new
                    media backend state is not correct this might override your current watch state.

                    The solution to get both in sync, and to do so follow these steps:

                    1. Add your backend that has correct watch state and enable full import.
                    2. Add your new backend as metadata source only, when adding a backend you will get
                       asked <question>Enable importing of metadata and play state from this backend?</question> answer with <value>N</value> for the new backend.

                    After that, do single backend export by using the following command:

                    {cmd} <cmd>state:export</cmd> <flag>-vv -ifs</flag> <value>new_backend_name</value>

                    Running this command will force full export your current database state to the selected backend. Once that done you can
                    turn on import from the new backend. by editing the backend setting:

                    {cmd} <cmd>config:manage</cmd> <flag>-s</flag> <value>backend_name</value>

                    HELP,
                    [
                        'cmd' => trim(commandContext()),
                        'route' => self::ROUTE,
                        'config_edit' => EditCommand::ROUTE,
                        'unmatched_route' => UnmatchedCommand::ROUTE,
                    ]
                )
            );
    }

    /**
     * Make sure the command is not running in parallel.
     *
     * @param InputInterface $input The input interface object.
     * @param OutputInterface $output The output interface object.
     *
     * @return int The status code of the command execution.
     */
    protected function runCommand(InputInterface $input, OutputInterface $output): int
    {
        return $this->single(fn(): int => $this->process($input, $output), $output, [
            iLogger::class => $this->logger,
            Level::class => Level::Error,
        ]);
    }

    /**
     * Import the state from the backends.
     *
     * @param InputInterface $input The input interface object.
     * @param OutputInterface $output The output interface object.
     *
     * @return int The return status code.
     */
    protected function process(InputInterface $input, OutputInterface $output): int
    {
        if (null !== ($logfile = $input->getOption('logfile')) && true === ($this->logger instanceof Logger)) {
            $this->logger->setHandlers([
                $this->suppressor->withHandler(new StreamLogHandler(new Stream($logfile, 'w'), $output))
            ]);
        }

        if ($input->getOption('direct-mapper')) {
            $this->mapper = Container::get(DirectMapper::class);
        }

        $mapperOpts = $dbOpts = [];

        if ($input->getOption('dry-run')) {
            $this->logger->notice('Dry run mode. No changes will be committed.');
            $mapperOpts[Options::DRY_RUN] = true;
        }

        if ($input->getOption('trace')) {
            $mapperOpts[Options::DEBUG_TRACE] = true;
            $dbOpts[Options::DEBUG_TRACE] = true;
        }

        if ($input->getOption('always-update-metadata')) {
            $mapperOpts[Options::MAPPER_ALWAYS_UPDATE_META] = true;
        }

        $this->mapper->setOptions($mapperOpts);

        $users = getUsersContext(mapper: $this->mapper, logger: $this->logger, opts: [
            DatabaseInterface::class => $dbOpts,
        ]);
        if (null !== ($user = $input->getOption('user'))) {
            $users = array_filter($users, fn($k) => $k === $user, mode: ARRAY_FILTER_USE_KEY);
            if (empty($users)) {
                $output->writeln(r("<error>User '{user}' not found.</error>", ['user' => $user]));
                return self::FAILURE;
            }
        }

        $selected = $input->getOption('select-backend');
        $isCustom = !empty($selected) && count($selected) > 0;
        $supported = Config::get('supported', []);

        foreach ($users as $userContext) {
            $list = [];
            $userStart = microtime(true);

            $this->logger->notice("SYSTEM: Importing user '{user}' play states.", [
                'user' => $userContext->name,
                'backends' => join(', ', array_keys($list)),
            ]);

            foreach ($userContext->config->getAll() as $backendName => $backend) {
                $type = strtolower(ag($backend, 'type', 'unknown'));
                $metadata = false;

                if ($isCustom && $input->getOption('exclude') === $this->in_array($selected, $backendName)) {
                    $this->logger->info("SYSTEM: Ignoring '{user}@{backend}'. as requested.", [
                        'user' => $userContext->name,
                        'backend' => $backendName
                    ]);
                    continue;
                }

                // -- sanity check in case user has both import.enabled and options.IMPORT_METADATA_ONLY enabled.
                if (true === (bool)ag($backend, 'import.enabled')) {
                    if (true === ag_exists($backend, 'options.' . Options::IMPORT_METADATA_ONLY)) {
                        $backend = ag_delete($backend, 'options.' . Options::IMPORT_METADATA_ONLY);
                    }
                }

                if (true === (bool)ag($backend, 'options.' . Options::IMPORT_METADATA_ONLY)) {
                    $metadata = true;
                }

                if (true === $input->getOption('metadata-only')) {
                    $metadata = true;
                }

                if (true !== $metadata && true !== (bool)ag($backend, 'import.enabled')) {
                    if ($isCustom) {
                        $this->logger->warning(
                            message: "SYSTEM: Importing from import disabled '{user}@{backend}' As requested.",
                            context: [
                                'user' => $userContext->name,
                                'backend' => $backendName
                            ]
                        );
                    } else {
                        $this->logger->info("SYSTEM: Ignoring '{user}@{backend}'. Import disabled.", [
                            'user' => $userContext->name,
                            'backend' => $backendName
                        ]);
                        continue;
                    }
                }

                if (!isset($supported[$type])) {
                    $this->logger->error("SYSTEM: Ignoring '{user}@{backend}'. Unexpected type '{type}'.", [
                        'user' => $userContext->name,
                        'type' => $type,
                        'backend' => $backendName,
                        'types' => implode(', ', array_keys($supported)),
                    ]);
                    continue;
                }

                if (null === ($url = ag($backend, 'url')) || false === isValidURL($url)) {
                    $this->logger->error("SYSTEM: Ignoring '{user}@{backend}'. Invalid URL '{url}'.", [
                        'user' => $userContext->name,
                        'url' => $url ?? 'None',
                        'backend' => $backendName,
                    ]);
                    continue;
                }

                $backend['name'] = $backendName;
                $list[$backendName] = $backend;
            }

            if (empty($list)) {
                $this->logger->warning(
                    $isCustom ? r("[-s, --select-backend] flag did not match any backend for '{user}'.", [
                        'user' => $userContext->name,
                    ]) : 'No backends were found.'
                );
                continue;
            }

            /** @var array<array-key,ResponseInterface> $queue */
            $queue = [];

            $this->logger->notice(
                message: "SYSTEM: Preloading user '{user}: {mapper}' mapping data. Memory usage '{memory.now}'.",
                context: [
                    'user' => $userContext->name,
                    'mapper' => afterLast($userContext->mapper::class, '\\'),
                    'memory' => [
                        'now' => getMemoryUsage(),
                        'peak' => getPeakMemoryUsage(),
                    ],
                ]
            );

            $time = microtime(true);
            $userContext->mapper->loadData();

            $this->logger->notice(
                message: "SYSTEM: Preloading user '{user}: {mapper}' mapping data completed in '{duration}s'. Memory usage '{memory.now}'.",
                context: [
                    'user' => $userContext->name,
                    'mapper' => afterLast($userContext->mapper::class, '\\'),
                    'duration' => round(microtime(true) - $time, 4),
                    'memory' => [
                        'now' => getMemoryUsage(),
                        'peak' => getPeakMemoryUsage(),
                    ],
                ]
            );

            foreach ($list as $name => &$backend) {
                $metadata = false;
                $opts = ag($backend, 'options', []);

                if (true === (bool)ag($backend, 'options.' . Options::IMPORT_METADATA_ONLY)) {
                    $opts[Options::IMPORT_METADATA_ONLY] = true;
                    $metadata = true;
                }

                if (true === $input->getOption('metadata-only')) {
                    $opts[Options::IMPORT_METADATA_ONLY] = true;
                    $metadata = true;
                }

                if ($input->getOption('trace')) {
                    $opts[Options::DEBUG_TRACE] = true;
                }

                if ($input->getOption('timeout')) {
                    $opts['client']['timeout'] = (float)$input->getOption('timeout');
                }

                $backend['options'] = $opts;
                $backend['class'] = makeBackend(backend: $backend, name: $name, options: [
                    UserContext::class => $userContext,
                ]);

                $after = ag($backend, 'import.lastSync', null);

                if (true === (bool)ag($opts, Options::FORCE_FULL, false) || true === $input->getOption('force-full')) {
                    $after = null;
                }

                if (null !== $after) {
                    $after = makeDate($after);
                }

                $this->logger->notice("SYSTEM: Importing '{user}@{backend}' {import_type} changes.", [
                    'user' => $userContext->name,
                    'backend' => $name,
                    'import_type' => true === $metadata ? 'metadata' : 'metadata & play state',
                    'since' => null === $after ? 'Beginning' : (string)$after,
                ]);

                array_push($queue, ...$backend['class']->pull($userContext->mapper, $after));

                $inDryMode = $userContext->mapper->inDryRunMode() || ag($backend, 'options.' . Options::DRY_RUN);

                if (false === $inDryMode) {
                    if (true === (bool)Message::get("{$name}.has_errors")) {
                        $this->logger->warning(
                            message: "SYSTEM: Not updating '{user}@{backend}' import last sync date. There was errors recorded during the operation.",
                            context: [
                                'user' => $userContext->name,
                                'backend' => $name,
                            ]
                        );
                    } else {
                        $userContext->config->set("{$name}.import.lastSync", time());
                    }
                }
            }

            unset($backend);

            $start = microtime(true);
            $this->logger->notice("SYSTEM: Waiting on '{total}' requests for '{user}' backends.", [
                'user' => $userContext->name,
                'total' => number_format(count($queue)),
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

            $this->logger->notice(
                "SYSTEM: Completed '{total}' requests in '{duration}'s for '{user}' backends. Parsed '{responses.size}' of data.",
                [
                    'user' => $userContext->name,
                    'total' => number_format(count($queue)),
                    'duration' => round(microtime(true) - $start, 4),
                    'memory' => [
                        'now' => getMemoryUsage(),
                        'peak' => getPeakMemoryUsage(),
                    ],
                    'responses' => [
                        'size' => fsize((int)Message::get('response.size', 0)),
                    ],
                ]
            );

            $queue = $requestData = null;

            $total = count($userContext->mapper);

            if ($total >= 1) {
                $this->logger->notice("SYSTEM: Found '{total}' updated items from '{user}' backends.", [
                    'user' => $userContext->name,
                    'total' => $total,
                    'memory' => [
                        'now' => getMemoryUsage(),
                        'peak' => getPeakMemoryUsage(),
                    ],
                ]);
            }

            $operations = $userContext->mapper->commit();

            $a = [
                [
                    'Type' => ucfirst(iState::TYPE_MOVIE),
                    'Added' => $operations[iState::TYPE_MOVIE]['added'] ?? '-',
                    'Updated' => $operations[iState::TYPE_MOVIE]['updated'] ?? '-',
                    'Failed' => $operations[iState::TYPE_MOVIE]['failed'] ?? '-',
                ],
                new TableSeparator(),
                [
                    'Type' => ucfirst(iState::TYPE_EPISODE),
                    'Added' => $operations[iState::TYPE_EPISODE]['added'] ?? '-',
                    'Updated' => $operations[iState::TYPE_EPISODE]['updated'] ?? '-',
                    'Failed' => $operations[iState::TYPE_EPISODE]['failed'] ?? '-',
                ],
            ];

            Message::reset();
            $userContext->mapper->reset();

            $this->logger->info(
                "SYSTEM: Importing '{user}' play states completed in '{duration}'s. Memory usage '{memory.now}'.",
                [
                    'user' => $userContext->name,
                    'backends' => join(', ', array_keys($list)),
                    'duration' => round(microtime(true) - $userStart, 4),
                    'memory' => [
                        'now' => getMemoryUsage(),
                        'peak' => getPeakMemoryUsage(),
                    ],
                ]
            );

            $output->writeln('');
            new Table($output)->setHeaders(array_keys($a[0]))->setStyle('box')->setRows(array_values($a))->render();
            $output->writeln('');

            if (false === $input->getOption('dry-run')) {
                $userContext->config->persist();
            }

            if ($input->getOption('show-messages')) {
                $this->displayContent(
                    Message::getAll(),
                    $output,
                    $input->getOption('output') === 'json' ? 'json' : 'yaml'
                );
            }
        }

        return self::SUCCESS;
    }

    private function in_array(array $list, string $search): bool
    {
        return array_any($list, fn($item) => str_starts_with($search, $item));
    }
}
