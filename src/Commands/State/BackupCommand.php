<?php

declare(strict_types=1);

namespace App\Commands\State;

use App\Command;
use App\Libs\Attributes\Route\Cli;
use App\Libs\Config;
use App\Libs\Mappers\Import\DirectMapper;
use App\Libs\Options;
use App\Libs\Stream;
use Psr\Http\Message\StreamInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
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
    public const ROUTE = 'state:backup';

    public const TASK_NAME = 'backup';

    /**
     * Constructs a new instance of the class.
     *
     * @param DirectMapper $mapper The direct mapper instance.
     * @param LoggerInterface $logger The logger instance.
     */
    public function __construct(private DirectMapper $mapper, private LoggerInterface $logger)
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
     * @param InputInterface $input The input interface instance.
     * @param OutputInterface $output The output interface instance.
     *
     * @return int The exit code of the command.
     */
    protected function runCommand(InputInterface $input, OutputInterface $output): int
    {
        return $this->single(fn(): int => $this->process($input), $output);
    }

    /**
     * Execute the command.
     *
     * @param InputInterface $input The input interface.
     *
     * @return int The integer result.
     */
    protected function process(InputInterface $input): int
    {
        $list = [];
        $selected = $input->getOption('select-backend');
        $isCustom = !empty($selected) && count($selected) > 0;
        $supported = Config::get('supported', []);

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

        foreach (Config::get('servers', []) as $backendName => $backend) {
            $type = strtolower(ag($backend, 'type', 'unknown'));

            if ($isCustom && $input->getOption('exclude') === in_array($backendName, $selected, true)) {
                $this->logger->info('SYSTEM: Ignoring [{backend}] as requested by select backends flag.', [
                    'backend' => $backendName,
                ]);
                continue;
            }

            if (true !== (bool)ag($backend, 'import.enabled')) {
                $this->logger->info('SYSTEM: Ignoring [{backend}] imports are disabled for this backend.', [
                    'backend' => $backendName,
                ]);
                continue;
            }

            if (!isset($supported[$type])) {
                $this->logger->error('SYSTEM: Ignoring [{backend}] because of the unexpected type [{type}].', [
                    'type' => $type,
                    'backend' => $backendName,
                ]);
                continue;
            }

            if (null === ($url = ag($backend, 'url')) || false === isValidURL($url)) {
                $this->logger->error('SYSTEM: Ignoring [{backend}] because of invalid URL.', [
                    'backend' => $backendName,
                    'url' => $url ?? 'None',
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
            return self::FAILURE;
        }

        if (true !== $input->getOption('no-enhance')) {
            $this->logger->notice('SYSTEM: Preloading {mapper} data.', [
                'mapper' => afterLast($this->mapper::class, '\\'),
                'memory' => [
                    'now' => getMemoryUsage(),
                    'peak' => getPeakMemoryUsage(),
                ],
            ]);

            $this->mapper->loadData();

            $this->logger->notice('SYSTEM: Preloading {mapper} data is complete.', [
                'mapper' => afterLast($this->mapper::class, '\\'),
                'memory' => [
                    'now' => getMemoryUsage(),
                    'peak' => getPeakMemoryUsage(),
                ],
            ]);
        }

        /** @var array<array-key,ResponseInterface> $queue */
        $queue = [];

        $this->logger->notice('Using WatchState Version - \'{version}\'.', ['version' => getAppVersion()]);

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
            $backend['class'] = $this->getBackend($name, $backend);

            $this->logger->notice('SYSTEM: Backing up [{backend}] play state.', [
                'backend' => $name,
            ]);

            if (null === ($fileName = $input->getOption('file')) || empty($fileName)) {
                $fileName = Config::get('path') . '/backup/{backend}.{date}.json';
            }

            if ($input->getOption('keep')) {
                $fileName = Config::get('path') . '/backup/{backend}.json';
            }

            if (count($list) <= 1 && null === ($file = $input->getOption('file'))) {
                $fileName = $file;
            }

            if (false === $input->getOption('dry-run')) {
                $fileName = r($fileName ?? Config::get('path') . '/backup/{backend}.{date}.json', [
                    'backend' => ag($backend, 'name', 'Unknown??'),
                    'date' => makeDate()->format('Ymd'),
                ]);

                if (!file_exists($fileName)) {
                    touch($fileName);
                }

                $backend['fp'] = new Stream($fileName, 'wb+');
                $backend['fp']->write('[');
            }

            array_push(
                $queue,
                ...$backend['class']->backup(
                $this->mapper,
                $backend['fp'] ?? null,
                [
                    'no_enhance' => true === $input->getOption('no-enhance'),
                    Options::DRY_RUN => (bool)$input->getOption('dry-run'),
                ]
            )
            );
        }

        unset($backend);

        $start = makeDate();
        $this->logger->notice('SYSTEM: Waiting on [{total}] requests.', [
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

        foreach ($list as $backend) {
            if (null === ($backend['fp'] ?? null)) {
                continue;
            }

            assert($backend['fp'] instanceof StreamInterface);

            if (false === $input->getOption('dry-run')) {
                $backend['fp']->seek(-1, SEEK_END);
                $backend['fp']->write(PHP_EOL . ']');
            }
        }

        $end = makeDate();
        $this->logger->notice('SYSTEM: Operation is finished.', [
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

        return self::SUCCESS;
    }
}
