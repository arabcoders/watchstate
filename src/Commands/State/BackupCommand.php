<?php

declare(strict_types=1);

namespace App\Commands\State;

use App\Backends\Common\Cache as BackendCache;
use App\Command;
use App\Libs\Attributes\Route\Cli;
use App\Libs\Config;
use App\Libs\ConfigFile;
use App\Libs\Container;
use App\Libs\Mappers\ExtendedImportInterface as iEImport;
use App\Libs\Mappers\Import\DirectMapper;
use App\Libs\Options;
use App\Libs\Stream;
use DirectoryIterator;
use Psr\Http\Message\StreamInterface as iStream;
use Psr\Log\LoggerInterface as iLogger;
use Symfony\Component\Console\Input\InputInterface as iInput;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface as iOutput;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Throwable;

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
    public function __construct(private DirectMapper $mapper, private iLogger $logger)
    {
        set_time_limit(0);
        ini_set('memory_limit', '-1');

        parent::__construct();
    }

    /**
     * Configures the command.
     */
    protected function configure(): void
    {
        $this->setName(self::ROUTE)
            ->setDescription('Backup backends play state.')
            ->addOption(
                'keep',
                'k',
                InputOption::VALUE_NONE,
                'If this flag is used, backups will not be removed by system:purge task.'
            )
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'No actions will be committed.')
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
                'Do not enhance the backup data using local db info.'
            )
            ->addOption(
                'file',
                null,
                InputOption::VALUE_REQUIRED,
                'Full path backup file. Will only be used if backup list is 1'
            )
            ->addOption('only-main-user', 'M', InputOption::VALUE_NONE, 'Only backup main user data.')
            ->addOption('no-compress', 'N', InputOption::VALUE_NONE, 'Do not compress the backup file.')
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

                    <question># Where are my backups stored?</question>

                    By default, we store backups at [<value>{backupDir}</value>].

                    <question># Why the external ids are not exactly the same from backend?</question>

                    By default we enhance the data from the backend to allow the backup to be usable by all if your backends,
                    The expanded external ids make the data more portable, However, if you do not wish to have this enabled. You can
                    disable it via [<flag>--no-enhance</flag>] flag. <notice>We recommend to keep this option enabled</notice>.

                    <question># I want different file name for my backup?</question>

                    Backup names are something tricky, however it's possible to choose the backup filename if the total number
                    of backed up backends are 1. So, in essence you have to combine two flags [<flag>-s</flag>, <flag>--select-backend</flag>] and [<flag>--file</flag>].

                    For example, to back up [<value>backend_name</value>] backend data to [<value>/tmp/backend_name.json</value>] do the following:

                    {cmd} <cmd>{route}</cmd> <flag>--select-backend</flag> <value>backend_name</value> <flag>--file</flag> <value>/tmp/my_backend.json</value>

                    HELP,
                    [
                        'cmd' => trim(commandContext()),
                        'route' => self::ROUTE,
                        'backupDir' => after(Config::get('path') . '/backup', ROOT_PATH),

                    ]
                )
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
        return $this->single(fn(): int => $this->process($input), $output);
    }

    private function getBackends(iInput $input): array
    {
        $configs = [
            'main' => [
                'config' => ConfigFile::open(Config::get('backends_file'), 'yaml'),
                'mapper' => $this->mapper,
                'cache' => null,
            ]
        ];

        if (true === $input->getOption('only-main-user')) {
            return $configs;
        }

        $usersDir = Config::get('path') . '/users';

        if (false === is_dir($usersDir)) {
            return $configs;
        }

        if (!is_readable($usersDir)) {
            $this->logger->error("SYSTEM: Unable to read '{dir}' directory.", ['dir' => $usersDir]);
            return $configs;
        }

        $mainUserIds = array_map(fn($backend) => ag($backend, 'user'), ag($configs, 'main.config')->getAll());

        foreach (new DirectoryIterator(Config::get('path') . '/users') as $dir) {
            if ($dir->isDot() || false === $dir->isDir()) {
                continue;
            }

            $config = perUserConfig($dir->getBasename());
            $subUserIds = array_map(fn($backend) => ag($backend, 'user'), $config->getAll());

            foreach ($mainUserIds as $mainId) {
                if (false === in_array($mainId, $subUserIds)) {
                    continue;
                }

                $this->logger->debug("SYSTEM: Skipping '{user}' backends as it's same as main user.", [
                    'user' => $dir->getBasename(),
                    'main' => $mainUserIds,
                    'sub' => $subUserIds,
                ]);
                continue 2;
            }

            $userName = $dir->getBasename();
            $perUserCache = perUserCacheAdapter($userName);

            $configs[$userName] = [
                'config' => $config,
                'mapper' => $this->mapper->withDB(perUserDb($userName))
                    ->withCache($perUserCache)
                    ->withLogger($this->logger)
                    ->withOptions(
                        array_replace_recursive($this->mapper->getOptions(), [Options::ALT_NAME => $userName])
                    )
                    ->loadData(),
                'cache' => $perUserCache,
            ];
        }

        return $configs;
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

        if ($input->getOption('dry-run')) {
            $this->logger->notice('SYSTEM: Dry run mode. No changes will be committed.');
            $mapperOpts[Options::DRY_RUN] = true;
        }

        if ($input->getOption('trace')) {
            $mapperOpts[Options::DEBUG_TRACE] = true;
        }

        if (!empty($mapperOpts)) {
            $this->mapper->setOptions(options: $mapperOpts);
        }

        $this->logger->notice("Using WatchState version - '{version}'.", ['version' => getAppVersion()]);
        foreach ($this->getBackends($input) as $user => $opt) {
            try {
                $this->process_backup($input, $user, $opt);
            } finally {
                ag($opt, 'mapper')->reset();
            }
        }

        return self::SUCCESS;
    }

    private function process_backup(iInput $input, string $user, array $opt): void
    {
        $list = [];

        $selected = $input->getOption('select-backend');
        $isCustom = !empty($selected) && count($selected) > 0;
        $supported = Config::get('supported', []);

        $noCompression = $input->getOption('no-compress');

        $config = ag($opt, 'config');
        assert($config instanceof ConfigFile);

        foreach ($config->getAll() as $backendName => $backend) {
            $type = strtolower(ag($backend, 'type', 'unknown'));

            if ($isCustom && $input->getOption('exclude') === $this->in_array($selected, $backendName)) {
                $this->logger->info("SYSTEM: Ignoring '{user}@{backend}' as requested by [-s, --select-backend].", [
                    'user' => $user,
                    'backend' => $backendName
                ]);
                continue;
            }

            if (true !== (bool)ag($backend, 'import.enabled')) {
                $this->logger->info("SYSTEM: Ignoring '{user}@{backend}' as the backend has import disabled.", [
                    'user' => $user,
                    'backend' => $backendName
                ]);
                continue;
            }

            if (!isset($supported[$type])) {
                $this->logger->error(
                    "SYSTEM: Ignoring '{user}@{backend}' due to unexpected type '{type}'. Expecting '{types}'.",
                    [
                        'user' => $user,
                        'type' => $type,
                        'backend' => $backendName,
                        'types' => implode(', ', array_keys($supported)),
                    ]
                );
                continue;
            }

            if (null === ($url = ag($backend, 'url')) || false === isValidURL($url)) {
                $this->logger->error("SYSTEM: Ignoring '{user}@{backend}' due to invalid URL. '{url}'.", [
                    'user' => $user,
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
                $isCustom ? '[-s, --select-backend] flag did not match any backend.' : 'No backends were found.'
            );
            return;
        }

        $mapper = ag($opt, 'mapper');
        assert($mapper instanceof iEImport);

        if (true !== $input->getOption('no-enhance')) {
            $this->logger->notice("SYSTEM: Preloading '{user}@{mapper}' data.", [
                'user' => $user,
                'mapper' => afterLast($mapper::class, '\\'),
                'memory' => [
                    'now' => getMemoryUsage(),
                    'peak' => getPeakMemoryUsage(),
                ],
            ]);

            $start = microtime(true);
            $this->mapper->loadData();

            $this->logger->notice("SYSTEM: Preloading '{user}@{mapper}' data completed in '{duration}s'.", [
                'user' => $user,
                'mapper' => afterLast($this->mapper::class, '\\'),
                'duration' => round(microtime(true) - $start, 2),
                'memory' => [
                    'now' => getMemoryUsage(),
                    'peak' => getPeakMemoryUsage(),
                ],
            ]);
        }

        /** @var array<array-key,ResponseInterface> $queue */
        $queue = [];

        foreach ($list as $name => &$backend) {
            $opts = ag($backend, 'options', []);

            if ($input->getOption('trace')) {
                $opts[Options::DEBUG_TRACE] = true;
            }

            if ($input->getOption('dry-run')) {
                $opts[Options::DRY_RUN] = true;
            }

            if ($input->getOption('timeout')) {
                $opts['client']['timeout'] = (float)$input->getOption('timeout');
            }

            $backend['options'] = $opts;

            $backendOpts = [];

            if (null !== ag($opt, 'cache')) {
                $backendOpts = [
                    BackendCache::class => Container::get(BackendCache::class)->with(adapter: ag($opt, 'cache')),
                ];
            }

            $backend['class'] = makeBackend($backend, $name, $backendOpts)->setLogger($this->logger);

            $this->logger->notice("SYSTEM: Backing up '{user}@{backend}' play state.", [
                'user' => $user,
                'backend' => $name,
            ]);

            if (null === ($fileName = $input->getOption('file')) || empty($fileName)) {
                $fileName = Config::get('path') . '/backup/{backend}.{date}.json';
            }

            if ($input->getOption('keep')) {
                $fileName = Config::get('path') . '/backup/{backend}.json';
            }

            if (count($list) <= 1 && null !== ($file = $input->getOption('file'))) {
                $fileName = str_starts_with($file, '/') ? $file : Config::get('path') . '/backup' . '/' . $file;
            }

            if (false === $input->getOption('dry-run')) {
                $fileName = r($fileName ?? Config::get('path') . '/backup/{backend}.{date}.json', [
                    'backend' => ag($backend, 'name', 'Unknown??'),
                    'date' => makeDate()->format('Ymd'),
                ]);

                if (!file_exists($fileName)) {
                    touch($fileName);
                }

                $this->logger->notice("SYSTEM: '{user}@{backend}' is using '{file}' as backup target.", [
                    'user' => $user,
                    'file' => realpath($fileName),
                    'backend' => $name,
                ]);

                $backend['fp'] = new Stream($fileName, 'wb+');
                $backend['fp']->write('[');
            }

            array_push($queue, ...$backend['class']->backup($mapper, $backend['fp'] ?? null, [
                'no_enhance' => true === $input->getOption('no-enhance'),
                Options::DRY_RUN => (bool)$input->getOption('dry-run'),
            ]));
        }

        unset($backend);

        $start = microtime(true);
        $this->logger->notice("SYSTEM: Waiting on '{total}' requests for '{user}: {backends}' backends.", [
            'user' => $user,
            'total' => number_format(count($queue)),
            'backends' => implode(', ', array_keys($list)),
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

        foreach ($list as $backend) {
            if (null === ($backend['fp'] ?? null)) {
                continue;
            }

            assert($backend['fp'] instanceof iStream);

            if (false === $input->getOption('dry-run')) {
                $backend['fp']->seek(-1, SEEK_END);
                $backend['fp']->write(PHP_EOL . ']');

                if (false === $noCompression) {
                    $file = $backend['fp']->getMetadata('uri');
                    $this->logger->notice("SYSTEM: Compressing '{user}@{file}'.", [
                        'user' => $user,
                        'file' => $file
                    ]);
                    $status = compress_files($file, [$file], ['affix' => 'zip']);
                    if (true === $status) {
                        unlink($file);
                    }
                }

                $backend['fp']->close();
            }
        }

        $this->logger->notice("SYSTEM: Backup operation for '{user}: {backends}' backends finished in '{duration}s'.", [
            'user' => $user,
            'backends' => implode(', ', array_keys($list)),
            'duration' => round(microtime(true) - $start, 2),
            'memory' => [
                'now' => getMemoryUsage(),
                'peak' => getPeakMemoryUsage(),
            ],
        ]);
    }

    private function in_array(array $haystack, string $needle): bool
    {
        return array_any($haystack, fn($item) => str_starts_with($item, $needle));
    }
}
