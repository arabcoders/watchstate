<?php

declare(strict_types=1);

namespace App\Commands\State;

use App\Backends\Common\Request;
use App\Command;
use App\Libs\Attributes\DI\Inject;
use App\Libs\Attributes\Route\Cli;
use App\Libs\Config;
use App\Libs\Database\DatabaseInterface;
use App\Libs\Entity\StateInterface as iState;
use App\Libs\Extends\RetryableHttpClient;
use App\Libs\Extends\StreamLogHandler;
use App\Libs\LogSuppressor;
use App\Libs\Mappers\Import\DirectMapper;
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
use Symfony\Contracts\HttpClient\HttpClientInterface as iHttp;

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
        #[Inject(DirectMapper::class)]
        private readonly iImport $mapper,
        private readonly iLogger $logger,
        private readonly LogSuppressor $suppressor,
        #[Inject(RetryableHttpClient::class)]
        private readonly iHttp $http,
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
            ->addOption(
                'sync-requests',
                null,
                InputOption::VALUE_NONE,
                'Send one request at a time instead of all at once. note: Slower but more reliable.'
            )
            ->addOption(
                'async-requests',
                null,
                InputOption::VALUE_NONE,
                'Send all requests at once. note: Faster but less reliable. Default.'
            )
            ->addOption('timeout', null, InputOption::VALUE_REQUIRED, 'Set request timeout in seconds.')
            ->addOption('user', 'u', InputOption::VALUE_REQUIRED, 'Select sub user. Default all users.')
            ->addOption(
                'select-backend',
                's',
                InputOption::VALUE_IS_ARRAY | InputOption::VALUE_OPTIONAL,
                'Select backend.'
            )
            ->addOption(
                'exclude',
                'e',
                InputOption::VALUE_NONE,
                'Inverse --select-backend logic. Exclude selected backends.'
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
            ->addOption('logfile', null, InputOption::VALUE_REQUIRED, 'Save console output to file.');
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

        $mapperOpts = $dbOpts = [];

        if ($input->getOption('dry-run')) {
            $this->logger->notice('Dry run mode. No changes will be committed.');
            $mapperOpts[Options::DRY_RUN] = true;
        }

        if (true === (bool)Config::get('guid.disable.episode', false)) {
            $this->logger->notice("Mapper: Matching episodes via GUID is disabled.");
        }

        if ($input->getOption('trace')) {
            $mapperOpts[Options::DEBUG_TRACE] = true;
            $dbOpts[Options::DEBUG_TRACE] = true;
        }

        if ($input->getOption('always-update-metadata')) {
            $mapperOpts[Options::MAPPER_ALWAYS_UPDATE_META] = true;
        }

        if (false === ($syncRequests = $input->getOption('sync-requests'))) {
            $syncRequests = (bool)Config::get('http.default.sync_requests', false);
        }

        if (true === $input->getOption('async-requests')) {
            $syncRequests = false;
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

            /** @var array<array-key,Request> $queue */
            $queue = [];

            $this->logger->notice(
                message: "SYSTEM: Preloading user '{user}: {mapper}' data. Memory usage '{memory.now}'.",
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
            $userContext->mapper->reset()->loadData();

            $this->logger->notice(
                message: "SYSTEM: Preloading user '{user}: {mapper}' data completed in '{duration}s'. Memory usage '{memory.now}'.",
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
            $this->logger->notice("SYSTEM: Waiting on '{total}' {sync}requests for '{user}' backends.", [
                'user' => $userContext->name,
                'total' => number_format(count($queue)),
                'sync' => $syncRequests ? 'sync ' : '',
                'memory' => [
                    'now' => getMemoryUsage(),
                    'peak' => getPeakMemoryUsage(),
                ],
            ]);

            send_requests(requests: $queue, client: $this->http, sync: $syncRequests, logger: $this->logger);

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

            $queue = null;

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
