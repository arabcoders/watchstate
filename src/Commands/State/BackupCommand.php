<?php

declare(strict_types=1);

namespace App\Commands\State;

use App\Command;
use App\Libs\Config;
use App\Libs\Mappers\Import\DirectMapper;
use App\Libs\Options;
use App\Libs\Routable;
use Psr\Log\LoggerInterface;
use SplFileObject;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Yaml;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Throwable;

#[Routable(command: self::ROUTE)]
class BackupCommand extends Command
{
    public const ROUTE = 'state:backup';

    public const TASK_NAME = 'backup';

    public function __construct(
        private DirectMapper $mapper,
        private LoggerInterface $logger
    ) {
        set_time_limit(0);
        ini_set('memory_limit', '-1');

        parent::__construct();
    }

    protected function configure(): void
    {
        $cmdContext = trim(commandContext());
        $backupDir = after(Config::get('path') . '/backup/', ROOT_PATH);

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
            ->addOption('servers-filter', 's', InputOption::VALUE_OPTIONAL, 'Select backends. Comma (,) seperated.', '')
            ->addOption('exclude', null, InputOption::VALUE_NONE, 'Inverse --servers-filter logic.')
            ->addOption('config', 'c', InputOption::VALUE_REQUIRED, 'Use Alternative config file.')
            ->setHelp(
                <<<HELP
Generate <info>portable</info> backup of your backends play state that can be used to restore any supported backend type.

<comment>[IMPORTANT INFO]</comment>

The command will only work on backends that has import enabled.

Backups generated without <info>[-k, --keep]</info> flag are subject to be <info>REMOVED</info> during system:prune run.
To keep permanent copy of your backups you can use the <info>[-k, --keep]</info> flag. For example:

{$cmdContext} state:backup <info>--keep</info> [--servers-filter <info>my_home</info>]

Backups generated with --keep flag will not contain a date and will be named [<info>{backend}.json</info>] where automated backups
will be named [<info>{backend}.{date}.json</info>]

<comment>If a backup already exists using the same filename, it will be overwritten.</comment>

------------------
<comment>Backups Directory:</comment>
------------------

{$backupDir}

HELP
            );
    }

    protected function runCommand(InputInterface $input, OutputInterface $output): int
    {
        return $this->single(fn(): int => $this->process($input, $output), $output);
    }

    protected function process(InputInterface $input, OutputInterface $output): int
    {
        // -- Use Custom servers.yaml file.
        if (($config = $input->getOption('config'))) {
            Config::save('servers', Yaml::parseFile($this->checkCustomServersFile($config)));
        }

        $list = [];
        $serversFilter = (string)$input->getOption('servers-filter');
        $selected = explode(',', $serversFilter);
        $isCustom = !empty($serversFilter) && count($selected) >= 1;
        $supported = Config::get('supported', []);

        $mapperOpts = [];

        if ($input->getOption('dry-run')) {
            $output->writeln('<info>Dry run mode. No changes will be committed.</info>');

            $mapperOpts[Options::DRY_RUN] = true;
        }

        if ($input->getOption('trace')) {
            $mapperOpts[Options::DEBUG_TRACE] = true;
        }

        if (!empty($mapperOpts)) {
            $this->mapper->setOptions(options: $mapperOpts);
        }

        foreach (Config::get('servers', []) as $serverName => $server) {
            $type = strtolower(ag($server, 'type', 'unknown'));

            if ($isCustom && $input->getOption('exclude') === in_array($serverName, $selected)) {
                $this->logger->info('SYSTEM: Ignoring [%(backend)] as requested by servers filter flag.', [
                    'backend' => $serverName,
                ]);
                continue;
            }

            if (true !== (bool)ag($server, 'import.enabled')) {
                $this->logger->info('SYSTEM: Ignoring [%(backend)] imports are disabled for this backend.', [
                    'backend' => $serverName,
                ]);
                continue;
            }

            if (!isset($supported[$type])) {
                $this->logger->error('SYSTEM: Ignoring [%(backend)] because of the unexpected type [%(type)].', [
                    'type' => $type,
                    'backend' => $serverName,
                ]);
                continue;
            }

            if (null === ($url = ag($server, 'url')) || false === filter_var($url, FILTER_VALIDATE_URL)) {
                $this->logger->error('SYSTEM: Ignoring [%(backend)] because of invalid URL.', [
                    'backend' => $serverName,
                    'url' => $url ?? 'None',
                ]);
                continue;
            }

            $server['name'] = $serverName;
            $list[$serverName] = $server;
        }

        if (empty($list)) {
            // -- @RELEASE - expand this message to account for filtering, import status etc.
            $this->logger->warning('No backends were found.');
            return self::FAILURE;
        }

        /** @var array<array-key,ResponseInterface> $queue */
        $queue = [];

        $this->logger->info(sprintf('Using WatchState Version - \'%s\'.', getAppVersion()));

        foreach ($list as $name => &$server) {
            $opts = ag($server, 'options', []);

            if ($input->getOption('trace')) {
                $opts[Options::DEBUG_TRACE] = true;
            }

            if ($input->getOption('dry-run')) {
                $opts[Options::DRY_RUN] = true;
            }

            if ($input->getOption('timeout')) {
                $opts['client']['timeout'] = (float)$input->getOption('timeout');
            }

            $server['options'] = $opts;
            $server['class'] = makeServer($server, $name);

            $this->logger->notice('SYSTEM: Backing up [%(backend)] play state.', [
                'backend' => $name,
            ]);

            $fileName = Config::get('path') . '/backup/{backend}.{date}.json';

            if ($input->getOption('keep')) {
                $fileName = Config::get('path') . '/backup/{backend}.json';
            }

            $fileName = replacer($fileName, [
                'backend' => ag($server, 'name'),
                'date' => makeDate()->format('Ymd'),
            ]);

            if (!file_exists($fileName)) {
                touch($fileName);
            }

            $server['fp'] = new SplFileObject($fileName, 'wb+');

            $server['fp']->fwrite('[');

            array_push($queue, ...$server['class']->backup($this->mapper, $server['fp'], []));
        }

        unset($server);

        $start = makeDate();
        $this->logger->notice('SYSTEM: Waiting on [%(total)] requests.', [
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

        foreach ($list as $server) {
            if (null === ($server['fp'] ?? null)) {
                continue;
            }

            if (true === ($server['fp'] instanceof SplFileObject)) {
                $server['fp']->fseek(-1, SEEK_END);
                $server['fp']->fwrite(PHP_EOL . ']');
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
