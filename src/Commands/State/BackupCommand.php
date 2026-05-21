<?php

declare(strict_types=1);

namespace App\Commands\State;

use App\Backends\Common\Request;
use App\Command;
use App\Libs\Attributes\DI\Inject;
use App\Libs\Attributes\Route\Cli;
use App\Libs\Config;
use App\Libs\Exceptions\RuntimeException;
use App\Libs\Extends\RetryableHttpClient;
use App\Libs\Extends\StreamLogHandler;
use App\Libs\LogSuppressor;
use App\Libs\Mappers\Import\DirectMapper;
use App\Libs\Options;
use App\Libs\Stream;
use App\Libs\UserContext;
use Monolog\Level;
use Monolog\Logger;
use Psr\Http\Message\StreamInterface as iStream;
use Psr\Log\LoggerInterface as iLogger;
use Symfony\Component\Console\Input\InputInterface as iInput;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface as iOutput;
use Symfony\Contracts\HttpClient\HttpClientInterface as iHttp;

/**
 * Class BackupCommand
 *
 * Generate portable backup of backends play state.
 */
#[Cli(command: self::ROUTE)]
class BackupCommand extends Command
{
    public const string ROUTE = 'state:backup';

    public const string TASK_NAME = 'backup';

    /**
     * Constructs a new instance of the class.
     *
     * @param DirectMapper $mapper The direct mapper instance.
     * @param iLogger $logger The logger instance.
     */
    public function __construct(
        private DirectMapper $mapper,
        private iLogger $logger,
        private LogSuppressor $suppressor,
        #[Inject(RetryableHttpClient::class)]
        private iHttp $http,
    ) {
        set_time_limit(0);
        ini_set('memory_limit', '-1');

        parent::__construct();
    }

    /**
     * Configures the command.
     */
    protected function configure(): void
    {
        $this
            ->setName(self::ROUTE)
            ->setDescription('Backup backends play state.')
            ->addOption('user', 'u', InputOption::VALUE_REQUIRED, 'Select user. Default is all users.')
            ->addOption(
                'keep',
                'k',
                InputOption::VALUE_NONE,
                'If this flag is used, backups will not be removed by system:purge task.',
            )
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'No actions will be committed.')
            ->addOption(
                'sync-requests',
                null,
                InputOption::VALUE_NONE,
                'Send one request at a time instead of all at once. note: Slower but more reliable.',
            )
            ->addOption(
                'async-requests',
                null,
                InputOption::VALUE_NONE,
                'Send all requests at once. note: Faster but less reliable. Default.',
            )
            ->addOption('timeout', null, InputOption::VALUE_REQUIRED, 'Set request timeout in seconds.')
            ->addOption(
                'select-backend',
                's',
                InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED,
                'Select backend.',
            )
            ->addOption('exclude', null, InputOption::VALUE_NONE, 'Inverse --select-backend logic.')
            ->addOption(
                'no-enhance',
                null,
                InputOption::VALUE_NONE,
                'Do not enhance the backup data using local db info.',
            )
            ->addOption(
                'file',
                null,
                InputOption::VALUE_REQUIRED,
                'Full path backup file. Will only be used if backup list is 1',
            )
            ->addOption('no-compress', 'N', InputOption::VALUE_NONE, 'Do not compress the backup file.')
            ->addOption('logfile', null, InputOption::VALUE_REQUIRED, 'Save console output to file.')
            ->setHelp(
                r(
                    <<<HELP

                        Generate <notice>portable</notice> backup of your backends play state that can be used to restore any supported backend type.

                        ------------------
                        <notice>[ Important info ]</notice>
                        ------------------

                        The command will only work on backends that has import enabled.

                        Backups generated without [<flag>-k</flag>, <flag>--keep</flag>] flag are subject to be <notice>REMOVED</notice> during system:prune run.
                        To keep permanent copy of your backups you can use the [<flag>-k</flag>, </flag>--keep</info>] flag. For example:

                        {cmd} <cmd>{route}</cmd> <info>--keep</info> [<flag>--select-backend</flag> <value>backend_name</value>]

                        Backups generated with [<flag>-k</flag>, <flag>--keep</flag>] flag will not contain a date and will be named [<value>backend_name.json</value>]
                        where automated backups will be named [<value>backend_name.00000000{date}.json</value>]

                        <notice>If filename already exists, it will be overwritten.</notice>

                        -------
                        <notice>[ FAQ ]</notice>
                        -------

                        <question># Backup specfic user backends data?</question>

                        Simply append [<flag>-u, --user</flag>] option flag to the command.

                        <question># Where are my backups stored?</question>

                        By default, we store backups at [<value>{backupDir}</value>].

                        <question># Why the external ids are not exactly the same from backend?</question>

                        By default we enhance the data from the backend to allow the backup to be usable by all if your backends,
                        The expanded external ids make the data more portable, However, if you do not wish to have this enabled. You can
                        disable it via [<flag>--no-enhance</flag>] flag. <notice>We recommend to keep this option enabled</notice>.

                        <question># I want different file name for my backup?</question>

                        Simply pass <flag>--file</flag> option flag, to choose the filename, however if you don't use <value>{backend}</value> in the filename,
                        the backup will be overwrriten. So, it's fine to use static filename like `/foo/mybackup.json` if used with only single backend.

                        However this become tricky if you to backup multiple backends, thus to make it work we have the following magic replacement words
                        to dynamicly update the filename.

                        * <value>{user}</value>    = sub user name.
                        * <value>{backend}</value> = the backend name
                        * <value>{date}</value>    = the current date.

                        so if you supply the following option flag [<flag>--file</flag> <value>./backup_{backend}.json</value>] the filename will be translated
                        to <value>./backup_backend_name.json</value> for each target. For example

                        {cmd} <cmd>{route}</cmd> <flag>--file</flag> <value>/tmp/{user}.{backend}.json</value>

                        This will save the following files.

                        * <value>/tmp/main.backend1.json.zip</value>
                        * <value>/tmp/main.backend2.json.zip</value>
                        * <value>/tmp/main.backend3.json.zip</value>

                        HELP,
                    [
                        'cmd' => trim(command_context()),
                        'route' => self::ROUTE,
                        'backupDir' => after(Config::get('path') . '/backup', ROOT_PATH),
                    ],
                ),
            );
    }

    /**
     * Make sure the command is not running in parallel.
     *
     * @param iInput $input The input interface instance.
     * @param iOutput $output The output interface instance.
     *
     * @return int The exit code of the command.
     */
    protected function runCommand(iInput $input, iOutput $output): int
    {
        if (null !== ($logfile = $input->getOption('logfile')) && true === $this->logger instanceof Logger) {
            $this->logger->setHandlers([
                $this->suppressor->withHandler(new StreamLogHandler(new Stream($logfile, 'w'), $output)),
            ]);
        }

        return $this->single(fn(): int => $this->process($input), $output, [
            iLogger::class => $this->logger,
            Level::class => Level::Error,
        ]);
    }

    /**
     * Execute the command.
     *
     * @param iInput $input The input interface.
     *
     * @return int The integer result.
     */
    protected function process(iInput $input): int
    {
        $mapperOpts = [];
        $dryRun = true === (bool) $input->getOption('dry-run');
        $keep = true === (bool) $input->getOption('keep');
        $noEnhance = true === (bool) $input->getOption('no-enhance');
        $noCompression = true === (bool) $input->getOption('no-compress');

        if (true === $dryRun) {
            $this->logger->notice('Dry run enabled; no backup files will be written.', [
                'event_name' => 'state.backup.dry_run.enabled',
                'subsystem' => 'state.backup',
                'operation' => 'backup',
                'outcome' => 'started',
                'command' => self::ROUTE,
                'dry_run' => true,
            ]);
            $mapperOpts[Options::DRY_RUN] = true;
        }

        if ($input->getOption('trace')) {
            $mapperOpts[Options::DEBUG_TRACE] = true;
        }

        if (!empty($mapperOpts)) {
            $this->mapper->setOptions(options: $mapperOpts);
        }

        try {
            $users = array_map(
                fn(string $user): UserContext => get_user_context($user, $this->mapper, $this->logger),
                select_users($input->getOption('user')),
            );
        } catch (RuntimeException $e) {
            $this->logger->error('Backup setup failed while loading selected users.', [
                'event_name' => 'state.backup.failed',
                'subsystem' => 'state.backup',
                'operation' => 'backup',
                'outcome' => 'failed',
                'command' => self::ROUTE,
                ...exception_log($e),
            ]);

            return self::FAILURE;
        }

        $totalStart = microtime(true);

        $this->logger->notice('Using WatchState {version.full}.', [
            'event_name' => 'app.version',
            'subsystem' => 'app',
            'operation' => 'version',
            'outcome' => 'resolved',
            'command' => self::ROUTE,
            'version' => [
                'full' => get_full_version(),
            ],
        ]);

        $this->logger->notice('Backup started for {user_count} users.', [
            'event_name' => 'state.backup.started',
            'subsystem' => 'state.backup',
            'operation' => 'backup',
            'outcome' => 'started',
            'command' => self::ROUTE,
            'user_count' => count($users),
            'dry_run' => $dryRun,
            'keep' => $keep,
            'no_enhance' => $noEnhance,
            'no_compress' => $noCompression,
        ]);

        foreach ($users as $userContext) {
            $userStart = microtime(true);

            try {
                $this->logger->notice("Backing up play states for '{user}'.", [
                    'event_name' => 'state.backup.user.started',
                    'subsystem' => 'state.backup',
                    'operation' => 'backup',
                    'outcome' => 'started',
                    'command' => self::ROUTE,
                    'user' => $userContext->name,
                    'memory' => [
                        'now' => get_memory_usage(),
                        'peak' => get_peak_memory_usage(),
                    ],
                ]);

                $this->process_backup($input, $userContext);

                $this->logger->notice("Completed backup for '{user}' in {duration_seconds}s.", [
                    'event_name' => 'state.backup.user.completed',
                    'subsystem' => 'state.backup',
                    'operation' => 'backup',
                    'outcome' => 'completed',
                    'command' => self::ROUTE,
                    'user' => $userContext->name,
                    'duration_seconds' => round(microtime(true) - $userStart, 4),
                    'memory' => [
                        'now' => get_memory_usage(),
                        'peak' => get_peak_memory_usage(),
                    ],
                ]);
            } finally {
                $userContext->mapper->reset();
            }
        }

        $this->logger->notice('Backup completed for {user_count} users in {duration_seconds}s.', [
            'event_name' => 'state.backup.completed',
            'subsystem' => 'state.backup',
            'operation' => 'backup',
            'outcome' => 'completed',
            'command' => self::ROUTE,
            'user_count' => count($users),
            'duration_seconds' => round(microtime(true) - $totalStart, 4),
            'dry_run' => $dryRun,
            'keep' => $keep,
            'no_enhance' => $noEnhance,
            'no_compress' => $noCompression,
        ]);

        return self::SUCCESS;
    }

    private function process_backup(iInput $input, UserContext $userContext): void
    {
        $list = [];

        $selected = $input->getOption('select-backend');
        $excludeSelected = true === (bool) $input->getOption('exclude');
        $isCustom = !empty($selected) && count($selected) > 0;
        $supported = Config::get('supported', []);
        $selection = [
            'mode' => $this->resolveSelectionMode((array) $selected, $excludeSelected),
            'backends' => array_values(array_filter(
                array_map(trim(...), array_map('strval', (array) $selected)),
                static fn(string $item): bool => '' !== $item,
            )),
        ];

        $noCompression = $input->getOption('no-compress');

        foreach ($userContext->config->getAll() as $backendName => $backend) {
            $type = strtolower(ag($backend, 'type', 'unknown'));

            if ($isCustom && $excludeSelected === $this->in_array($selected, $backendName)) {
                $this->logger->info("Skipping '{user}@{backend}': excluded by selection.", [
                    'event_name' => 'state.backup.backend.skipped',
                    'subsystem' => 'state.backup',
                    'operation' => 'select_backend',
                    'outcome' => 'skipped',
                    'command' => self::ROUTE,
                    'user' => $userContext->name,
                    'backend' => $backendName,
                    'reason' => 'selected_excluded',
                    'selection' => $selection,
                ]);
                continue;
            }

            if (!isset($supported[$type])) {
                $this->logger->warning("Skipping '{user}@{backend}': backend type '{backend_type}' is unsupported.", [
                    'event_name' => 'state.backup.backend.skipped',
                    'subsystem' => 'state.backup',
                    'operation' => 'select_backend',
                    'outcome' => 'skipped',
                    'command' => self::ROUTE,
                    'user' => $userContext->name,
                    'backend_type' => $type,
                    'backend' => $backendName,
                    'types' => implode(', ', array_keys($supported)),
                    'reason' => 'unsupported_type',
                ]);
                continue;
            }

            if (true !== (bool) ag($backend, 'import.enabled')) {
                if ($isCustom) {
                    $this->logger->notice(
                        "Backing up disabled import backend '{user}@{backend}' because it was explicitly selected.",
                        [
                            'event_name' => 'state.backup.backend.forced',
                            'subsystem' => 'state.backup',
                            'operation' => 'select_backend',
                            'outcome' => 'forced',
                            'command' => self::ROUTE,
                            'user' => $userContext->name,
                            'backend' => $backendName,
                            'reason' => 'explicitly_selected',
                            'import_enabled' => false,
                        ],
                    );
                } else {
                    $this->logger->info("Skipping '{user}@{backend}': import is disabled.", [
                        'event_name' => 'state.backup.backend.skipped',
                        'subsystem' => 'state.backup',
                        'operation' => 'select_backend',
                        'outcome' => 'skipped',
                        'command' => self::ROUTE,
                        'user' => $userContext->name,
                        'backend' => $backendName,
                        'reason' => 'import_disabled',
                    ]);
                    continue;
                }
            }

            if (null === ($url = ag($backend, 'url')) || false === is_valid_url($url)) {
                $this->logger->warning("Skipping '{user}@{backend}': URL '{url}' is invalid.", [
                    'event_name' => 'state.backup.backend.skipped',
                    'subsystem' => 'state.backup',
                    'operation' => 'select_backend',
                    'outcome' => 'skipped',
                    'command' => self::ROUTE,
                    'user' => $userContext->name,
                    'url' => $url ?? 'None',
                    'backend' => $backendName,
                    'reason' => 'invalid_url',
                ]);
                continue;
            }

            $backend['name'] = $backendName;
            $list[$backendName] = $backend;
        }

        if (empty($list)) {
            $this->logger->warning(
                $isCustom ? "No backup backends matched selection for '{user}'." : "No backup backends were available for '{user}'.",
                [
                    'event_name' => 'state.backup.backend.none_selected',
                    'subsystem' => 'state.backup',
                    'operation' => 'select_backend',
                    'outcome' => 'skipped',
                    'command' => self::ROUTE,
                    'user' => $userContext->name,
                    'reason' => $isCustom ? 'selection_no_match' : 'no_backends',
                    'selection' => $selection,
                ],
            );

            return;
        }

        if (true !== $input->getOption('no-enhance')) {
            $this->logger->notice("Preloading local state database for '{user}'.", [
                'event_name' => 'state.backup.preload.started',
                'subsystem' => 'state.backup',
                'operation' => 'preload',
                'outcome' => 'started',
                'command' => self::ROUTE,
                'user' => $userContext->name,
                'mapper' => after_last($userContext->mapper::class, '\\'),
                'memory' => [
                    'now' => get_memory_usage(),
                    'peak' => get_peak_memory_usage(),
                ],
            ]);

            $start = microtime(true);
            $userContext->mapper->loadData();

            $this->logger->notice("Preloaded local state database for '{user}' in {duration_seconds}s.", [
                'event_name' => 'state.backup.preload.completed',
                'subsystem' => 'state.backup',
                'operation' => 'preload',
                'outcome' => 'completed',
                'command' => self::ROUTE,
                'user' => $userContext->name,
                'mapper' => after_last($userContext->mapper::class, '\\'),
                'duration_seconds' => round(microtime(true) - $start, 4),
                'memory' => [
                    'now' => get_memory_usage(),
                    'peak' => get_peak_memory_usage(),
                ],
            ]);
        }

        /** @var array<array-key,Request> $queue */
        $queue = [];

        foreach ($list as $name => &$backend) {
            $opts = ag($backend, 'options', []);

            if ($input->getOption('trace')) {
                $opts = ag_set($opts, Options::DEBUG_TRACE, true);
            }

            if ($input->getOption('dry-run')) {
                $opts = ag_set($opts, Options::DEBUG_TRACE, true);
            }

            if ($input->getOption('timeout')) {
                $opts = ag_set($opts, 'client.timeout', (float) $input->getOption('timeout'));
            }

            $backend['options'] = $opts;
            $backend['class'] = make_backend(backend: $backend, name: $name, options: [
                UserContext::class => $userContext,
            ])->setLogger($this->logger);

            $this->logger->notice("Backing up play state for '{user}@{backend}'.", [
                'event_name' => 'state.backup.backend.started',
                'subsystem' => 'state.backup',
                'operation' => 'backup_backend',
                'outcome' => 'started',
                'command' => self::ROUTE,
                'user' => $userContext->name,
                'backend' => $name,
                'dry_run' => true === (bool) $input->getOption('dry-run'),
                'no_enhance' => true === (bool) $input->getOption('no-enhance'),
            ]);

            $fileName = $input->getOption('file');

            if (empty($fileName)) {
                $fileName = Config::get('path') . '/backup/{user}.{backend}.{date}.json';
                if ($input->getOption('keep')) {
                    $fileName = Config::get('path') . '/backup/{user}.{backend}.json';
                }
            }

            if (count($list) <= 1 && null !== ($file = $input->getOption('file'))) {
                $fileName = str_starts_with($file, '/') ? $file : Config::get('path') . '/backup' . '/' . $file;
            }

            if (false === $input->getOption('dry-run')) {
                $fileName = r($fileName ?? Config::get('path') . '/backup/{user}.{backend}.{date}.json', [
                    'user' => $userContext->name,
                    'backend' => ag($backend, 'name', 'Unknown'),
                    'date' => make_date()->format('Ymd'),
                ]);

                $directory = dirname($fileName);
                if (false === is_dir($directory) && false === @mkdir($directory, 0o755, true) && false === is_dir($directory)) {
                    throw new RuntimeException(r("Unable to create backup directory '{path}'.", ['path' => $directory]));
                }

                if (false === file_exists($fileName)) {
                    touch($fileName);
                }

                $this->logger->notice("Writing backup for '{user}@{backend}' to '{file}'.", [
                    'event_name' => 'state.backup.file.selected',
                    'subsystem' => 'state.backup',
                    'operation' => 'select_file',
                    'outcome' => 'completed',
                    'command' => self::ROUTE,
                    'user' => $userContext->name,
                    'file' => realpath($fileName),
                    'backend' => $name,
                ]);

                $backend['fp'] = new Stream($fileName, 'wb+');
                $backend['fp']->write('[');
            }

            array_push(
                $queue,
                ...$backend['class']->backup($userContext->mapper, $backend['fp'] ?? null, [
                    'no_enhance' => true === $input->getOption('no-enhance'),
                    Options::DRY_RUN => (bool) $input->getOption('dry-run'),
                ]),
            );
        }

        unset($backend);

        if (false === ($syncRequests = $input->getOption('sync-requests'))) {
            $syncRequests = (bool) Config::get('http.default.sync_requests', false);
        }

        if (true === $input->getOption('async-requests')) {
            $syncRequests = false;
        }

        $start = microtime(true);
        $this->logger->notice("Waiting for {request_count} backup requests for '{user}'.", [
            'event_name' => 'state.backup.requests.started',
            'subsystem' => 'state.backup',
            'operation' => 'send_requests',
            'outcome' => 'started',
            'command' => self::ROUTE,
            'user' => $userContext->name,
            'request_count' => count($queue),
            'backend_count' => count($list),
            'backends' => array_keys($list),
            'sync_requests' => $syncRequests,
            'memory' => [
                'now' => get_memory_usage(),
                'peak' => get_peak_memory_usage(),
            ],
        ]);

        send_requests(requests: $queue, client: $this->http, sync: $syncRequests, logger: $this->logger);

        foreach ($list as $b => $backend) {
            if (null === ($backend['fp'] ?? null)) {
                continue;
            }

            assert($backend['fp'] instanceof iStream, 'Expected backup file stream.');

            if (false === $input->getOption('dry-run')) {
                $backend['fp']->seek(-1, SEEK_END);
                $backend['fp']->write(PHP_EOL . ']');

                if (false === $noCompression) {
                    $file = $backend['fp']->getMetadata('uri');
                    $this->logger->notice("Compressing backup archive for '{user}@{backend}' to '{archive}'.", [
                        'event_name' => 'state.backup.file.compressing',
                        'subsystem' => 'state.backup',
                        'operation' => 'compress_file',
                        'outcome' => 'started',
                        'command' => self::ROUTE,
                        'backend' => $b,
                        'file' => $file,
                        'archive' => $file . '.zip',
                        'user' => $userContext->name,
                    ]);

                    $status = compress_files($file, [$file], ['affix' => 'zip']);

                    if (true === $status) {
                        unlink($file);
                    }
                }

                $backend['fp']->close();
            }
        }

        $this->logger->notice("Completed backup requests for '{user}' in {duration_seconds}s.", [
            'event_name' => 'state.backup.requests.completed',
            'subsystem' => 'state.backup',
            'operation' => 'send_requests',
            'outcome' => 'completed',
            'command' => self::ROUTE,
            'user' => $userContext->name,
            'request_count' => count($queue),
            'backend_count' => count($list),
            'backends' => array_keys($list),
            'duration_seconds' => round(microtime(true) - $start, 4),
            'sync_requests' => $syncRequests,
            'memory' => [
                'now' => get_memory_usage(),
                'peak' => get_peak_memory_usage(),
            ],
        ]);
    }

    private function in_array(array $list, string $search): bool
    {
        return array_any($list, static fn($item) => str_starts_with($search, $item));
    }

    /**
     * @param array<int|string,mixed> $selected
     */
    private function resolveSelectionMode(array $selected, bool $exclude): string
    {
        $selected = array_values(array_filter(
            array_map(trim(...), array_map('strval', $selected)),
            static fn(string $item): bool => '' !== $item,
        ));

        if ([] === $selected) {
            return 'all';
        }

        return true === $exclude ? 'exclude' : 'include';
    }
}
