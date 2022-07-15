<?php

declare(strict_types=1);

namespace App\Commands\State;

use App\Command;
use App\Commands\Backend\Library\UnmatchedCommand;
use App\Commands\Config\EditCommand;
use App\Libs\Config;
use App\Libs\Database\DatabaseInterface as iDB;
use App\Libs\Entity\StateInterface as iState;
use App\Libs\Mappers\Import\DirectMapper;
use App\Libs\Mappers\ImportInterface as iImport;
use App\Libs\Message;
use App\Libs\Options;
use App\Libs\Routable;
use Psr\Log\LoggerInterface as iLogger;
use RuntimeException;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Helper\TableSeparator;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Yaml;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Throwable;

#[Routable(command: self::ROUTE)]
class ImportCommand extends Command
{
    public const ROUTE = 'state:import';

    public const TASK_NAME = 'import';

    public function __construct(private iDB $db, private iImport $mapper, private iLogger $logger)
    {
        set_time_limit(0);
        ini_set('memory_limit', '-1');

        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName(self::ROUTE)
            ->setDescription('Import play state and metadata from backends.')
            ->addOption('force-full', 'f', InputOption::VALUE_NONE, 'Force full import. Ignore last sync date.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Do not commit any changes.')
            ->addOption('timeout', null, InputOption::VALUE_REQUIRED, 'Set request timeout in seconds.')
            ->addOption('select-backends', 's', InputOption::VALUE_OPTIONAL, 'Select backends. comma , seperated.', '')
            ->addOption('exclude', null, InputOption::VALUE_NONE, 'Inverse --select-backends logic.')
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
            ->addOption('config', 'c', InputOption::VALUE_REQUIRED, 'Use Alternative config file.')
            ->addOption('servers-filter', null, InputOption::VALUE_OPTIONAL, '[DEPRECATED] Select backends.', '')
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

                    {cmd} <cmd>{route}</cmd> <flag>--select-backends</flag> <value>backend_name</value>

                    <question># How to Import the metadata only?</question>

                    {cmd} <cmd>{route}</cmd> <flag>--metadata-only</flag>

                    <question># Import is failing due to timeout?</question>

                    If you want to permanently increase the <notice>timeout</notice> for specific backend, you can do the following

                    {cmd} <cmd>{config_edit}</cmd> <flag>--key</flag> <value>options.client.timeout</value> <flag>--set</flag> <value>600.0</value> -- <value>backend_name</value>

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
                    even more info by using [<flag>--trace</flag>] and [<flag>--context</flag>] flags. Be warned the output using all those flags
                    is quite excessive and shouldn't be used unless told by the team.

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

                    {cmd} <cmd>{unmatched_route}</cmd> -- <value>backend_name</value>

                    If you don't have any unmatched items, this likely means you are using unsupported external db ids.

                    <question># I removed the database and the import command is not importing the metadata again?</question>

                    This is caused by the key [<value>import.lastSync</value>] found in [<value>servers.yaml</value>]. You have to bypass it
                    by using [<flag>-f</flag>, <flag>--force-full</flag>] flag. This flag will cause the importer to not consider the last
                    import date and instead import all the items.

                    {cmd} <cmd>{route}</cmd> <flag>-force-full</flag>

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

    protected function runCommand(InputInterface $input, OutputInterface $output): int
    {
        return $this->single(fn(): int => $this->process($input, $output), $output);
    }

    protected function process(InputInterface $input, OutputInterface $output): int
    {
        // -- Use Custom servers.yaml file.
        if (($config = $input->getOption('config'))) {
            try {
                $custom = true;
                Config::save('servers', Yaml::parseFile($this->checkCustomBackendsFile($config)));
            } catch (RuntimeException $e) {
                $output->writeln(sprintf('<error>%s</error>', $e->getMessage()));
                return self::FAILURE;
            }
        } else {
            $custom = false;
            $config = Config::get('path') . '/config/servers.yaml';
        }

        $list = [];

        $selectBackends = (string)$input->getOption('select-backends');
        $serversFilter = (string)$input->getOption('servers-filter');

        if (!empty($serversFilter)) {
            $this->logger->warning(
                'The [--servers-filter] flag is deprecated and will be removed in v1.0, please use [--select-backends].'
            );
            if (empty($selectBackends)) {
                $selectBackends = $serversFilter;
            }
        }

        $selected = explode(',', $selectBackends);
        $isCustom = !empty($selectBackends) && count($selected) >= 1;
        $supported = Config::get('supported', []);

        $mapperOpts = [];

        if ($input->getOption('dry-run')) {
            $output->writeln('<info>Dry run mode. No changes will be committed.</info>');

            $mapperOpts[Options::DRY_RUN] = true;
        }

        if ($input->getOption('trace')) {
            $mapperOpts[Options::DEBUG_TRACE] = true;
            $this->db->setOptions(options: [Options::DEBUG_TRACE => true]);
        }

        if ($input->getOption('always-update-metadata')) {
            $mapperOpts[Options::MAPPER_ALWAYS_UPDATE_META] = true;
        }

        if ($input->getOption('direct-mapper')) {
            $this->mapper = new DirectMapper(logger: $this->logger, db: $this->db);
        }

        if (!empty($mapperOpts)) {
            $this->mapper->setOptions(options: $mapperOpts);
        }

        foreach (Config::get('servers', []) as $backendName => $backend) {
            $type = strtolower(ag($backend, 'type', 'unknown'));
            $metadata = false;

            if ($isCustom && $input->getOption('exclude') === in_array($backendName, $selected)) {
                $this->logger->info('SYSTEM: Ignoring [%(backend)] as requested by [-s, --select-backends] flag.', [
                    'backend' => $backendName,
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
                $this->logger->info('SYSTEM: Ignoring [%(backend)] imports are disabled for this backend.', [
                    'backend' => $backendName,
                ]);
                continue;
            }

            if (!isset($supported[$type])) {
                $this->logger->error('SYSTEM: Ignoring [%(backend)] because of the unexpected type [%(type)].', [
                    'type' => $type,
                    'backend' => $backendName,
                ]);
                continue;
            }

            if (null === ($url = ag($backend, 'url')) || false === filter_var($url, FILTER_VALIDATE_URL)) {
                $this->logger->error('SYSTEM: Ignoring [%(backend)] because of invalid URL.', [
                    'backend' => $backendName,
                    'url' => $url ?? 'None',
                ]);
                continue;
            }

            $backend['name'] = $backendName;
            $list[$backendName] = $backend;
        }

        if (empty($list)) {
            // -- @RELEASE - expand this message to account for filtering, import status etc.
            $this->logger->warning('No backends were found');
            return self::FAILURE;
        }

        /** @var array<array-key,ResponseInterface> $queue */
        $queue = [];

        $this->logger->info(sprintf('Using WatchState Version - \'%s\'.', getAppVersion()));

        $this->logger->notice('SYSTEM: Preloading %(mapper) data.', [
            'mapper' => afterLast($this->mapper::class, '\\'),
            'memory' => [
                'now' => getMemoryUsage(),
                'peak' => getPeakMemoryUsage(),
            ],
        ]);

        $this->mapper->loadData();

        $this->logger->notice('SYSTEM: Preloading %(mapper) data is complete.', [
            'mapper' => afterLast($this->mapper::class, '\\'),
            'memory' => [
                'now' => getMemoryUsage(),
                'peak' => getPeakMemoryUsage(),
            ],
        ]);

        $this->db->singleTransaction();

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
            $backend['class'] = $this->getBackend($name, $backend);

            $after = ag($backend, 'import.lastSync', null);

            if (true === (bool)ag($opts, Options::FORCE_FULL, false) || true === $input->getOption('force-full')) {
                $after = null;
            }

            if (null !== $after) {
                $after = makeDate($after);
            }

            $this->logger->notice('SYSTEM: Importing [%(backend)] %(import_type) changes.', [
                'backend' => $name,
                'import_type' => true === $metadata ? 'METADATA ONLY' : 'METADATA & PLAY STATE',
                'since' => null === $after ? 'Beginning' : $after->format('Y-m-d H:i:s T'),
            ]);

            array_push($queue, ...$backend['class']->pull($this->mapper, $after));

            $inDryMode = $this->mapper->inDryRunMode() || ag($backend, 'options.' . Options::DRY_RUN);

            if (false === $inDryMode) {
                if (true === (bool)Message::get("{$name}.has_errors")) {
                    $this->logger->warning('SYSTEM: Not updating last import date. [%(backend)] reported an error.', [
                        'backend' => $name,
                    ]);
                } else {
                    Config::save("servers.{$name}.import.lastSync", time());
                }
            }
        }

        unset($backend);

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

        $end = makeDate();

        $this->logger->notice('SYSTEM: Finished waiting on [%(total)] requests.', [
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
        ]);

        $queue = $requestData = null;

        $total = count($this->mapper);

        if ($total >= 1) {
            $this->logger->notice('SYSTEM: Found [%(total)] updated objects.', [
                'total' => $total,
                'memory' => [
                    'now' => getMemoryUsage(),
                    'peak' => getPeakMemoryUsage(),
                ],
            ]);
        }

        $operations = $this->mapper->commit();

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

        (new Table($output))->setHeaders(array_keys($a[0]))->setStyle('box')->setRows(array_values($a))->render();

        if (false === $input->getOption('dry-run')) {
            if (false === $custom && is_writable(dirname($config))) {
                copy($config, $config . '.bak');
            }

            file_put_contents($config, Yaml::dump(Config::get('servers', []), 8, 2));
        }

        if ($input->getOption('show-messages')) {
            $this->displayContent(Message::getAll(), $output, $input->getOption('output') === 'json' ? 'json' : 'yaml');
        }

        return self::SUCCESS;
    }
}
