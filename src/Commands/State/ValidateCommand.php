<?php

declare(strict_types=1);

namespace App\Commands\State;

use App\Command;
use App\Libs\Attributes\DI\Inject;
use App\Libs\Attributes\Route\Cli;
use App\Libs\Entity\StateInterface as iState;
use App\Libs\Exceptions\RuntimeException;
use App\Libs\Extends\StreamLogHandler;
use App\Libs\LogSuppressor;
use App\Libs\Mappers\Import\DirectMapper;
use App\Libs\Mappers\ImportInterface as iImport;
use App\Libs\Stream;
use App\Libs\UserContext;
use Monolog\Level;
use Monolog\Logger;
use Psr\Log\LoggerInterface as iLogger;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Helper\TableSeparator;
use Symfony\Component\Console\Input\InputInterface as iInput;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface as iOutput;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * Class ValidateCommand
 *
 * This command Validate local databases against the backends metadata, and validate whether the reference ID
 * is still valid and exists in the backend.
 */
#[Cli(command: self::ROUTE)]
class ValidateCommand extends Command
{
    public const string ROUTE = 'state:validate';

    public const string TASK_NAME = 'validate';

    /**
     * @var array<array-key,array<string,bool>> Store the status of item from backend in-case we have multiple identities.
     */
    private array $cache = [];

    private const array TO_VERBOSITY = [
        Level::Emergency->value => iOutput::VERBOSITY_SILENT,
        Level::Critical->value => iOutput::VERBOSITY_QUIET,
        Level::Alert->value => iOutput::VERBOSITY_NORMAL,
        Level::Error->value => iOutput::VERBOSITY_NORMAL,
        Level::Warning->value => iOutput::VERBOSITY_NORMAL,
        Level::Notice->value => iOutput::VERBOSITY_VERBOSE,
        Level::Info->value => iOutput::VERBOSITY_VERY_VERBOSE,
        Level::Debug->value => iOutput::VERBOSITY_DEBUG,
    ];

    private array $perRun = [];

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
        private iImport $mapper,
        private iLogger $logger,
        private LogSuppressor $suppressor,
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
        $this
            ->setName(self::ROUTE)
            ->setDescription('Validate stored backends reference id against the backends.')
            ->addOption('user', 'u', InputOption::VALUE_REQUIRED, 'Select user. Default is all users.')
            ->addOption(
                'logfile',
                null,
                InputOption::VALUE_REQUIRED,
                'Save console output to file. Will not work with progress bar.',
            )
            ->addOption('progress', null, InputOption::VALUE_NONE, 'Show progress bar.');
    }

    protected function runCommand(iInput $input, iOutput $output): int
    {
        return $this->single(fn(): int => $this->process($input, $output), $output, [
            iLogger::class => $this->logger,
            Level::class => Level::Error,
        ]);
    }

    /**
     * Validate the local databases against the backends reference ID.
     *
     * @param iInput $input The input interface object.
     * @param iOutput $output The output interface object.
     *
     * @return int The return status code.
     */
    protected function process(iInput $input, iOutput $output): int
    {
        if (null !== ($logfile = $input->getOption('logfile')) && true === $this->logger instanceof Logger) {
            $this->logger->setHandlers([
                $this->suppressor->withHandler(new StreamLogHandler(new Stream($logfile, 'w'), $output)),
            ]);
        }

        $logIO = null;
        $io = null;

        if ($input->getOption('progress') && method_exists($output, 'section')) {
            $logIO = new SymfonyStyle($input, $output->section());
            $io = new SymfonyStyle($input, $output->section());
        }

        try {
            $users = array_map(
                fn(string $user): UserContext => get_user_context($user, $this->mapper, $this->logger),
                select_users($input->getOption('user')),
            );
        } catch (RuntimeException $e) {
            $output->writeln(r('<error>{message}</error>', [
                'message' => $e->getMessage(),
            ]));

            return self::FAILURE;
        }

        $start_time = microtime(true);

        $this->output(
            Level::Notice,
            'Validation started for {user_count} users.',
            [
                'event_name' => 'state.validate.started',
                'subsystem' => 'state.validate',
                'operation' => 'validate',
                'outcome' => 'started',
                'command' => self::ROUTE,
                'user_count' => count($users),
            ],
            $logIO,
        );

        foreach ($users as $userContext) {
            $userStart = microtime(true);

            $this->output(
                Level::Notice,
                "Validating local metadata references for '{user}'.",
                [
                    'event_name' => 'state.validate.user.started',
                    'subsystem' => 'state.validate',
                    'operation' => 'validate',
                    'outcome' => 'started',
                    'command' => self::ROUTE,
                    'user' => $userContext->name,
                    'memory' => [
                        'now' => get_memory_usage(),
                        'peak' => get_peak_memory_usage(),
                    ],
                ],
                $logIO,
            );

            $this->validate($userContext, $io, $logIO);

            $this->output(
                Level::Notice,
                "Completed validation for '{user}' in {duration_seconds}s.",
                [
                    'event_name' => 'state.validate.user.completed',
                    'subsystem' => 'state.validate',
                    'operation' => 'validate',
                    'outcome' => 'completed',
                    'command' => self::ROUTE,
                    'user' => $userContext->name,
                    'duration_seconds' => round(microtime(true) - $userStart, 4),
                ],
                $logIO,
            );
        }

        $this->output(
            Level::Notice,
            'Validation completed for {user_count} users in {duration_seconds}s.',
            [
                'event_name' => 'state.validate.completed',
                'subsystem' => 'state.validate',
                'operation' => 'validate',
                'outcome' => 'completed',
                'command' => self::ROUTE,
                'user_count' => count($users),
                'duration_seconds' => round(microtime(true) - $start_time, 4),
            ],
            $logIO,
        );

        $this->renderStatus($output);

        return self::SUCCESS;
    }

    private function validate(
        UserContext $userContext,
        ?SymfonyStyle $progBar = null,
        ?SymfonyStyle $logIO = null,
    ): void {
        $clients = [];

        foreach ($userContext->config->getAll() as $backend => $config) {
            $clients[$backend] = make_backend($config, $backend, [UserContext::class => $userContext]);
        }

        $records = $userContext->db->getTotal();

        $this->output(
            Level::Notice,
            "Loaded {record_count} local records for '{user}'.",
            [
                'event_name' => 'state.validate.records.loaded',
                'subsystem' => 'state.validate',
                'operation' => 'load_records',
                'outcome' => 'completed',
                'command' => self::ROUTE,
                'user' => $userContext->name,
                'record_count' => $records,
                'backend_count' => count($clients),
                'backends' => array_keys($clients),
            ],
            $logIO,
        );

        if (null !== $progBar) {
            $progBar->progressStart($records);
            $progBar->newLine();
        }

        $this->perRun[$userContext->name] = [
            'updated' => 0,
            'removed' => 0,
            'no_change' => 0,
            'backends' => array_map(static fn() => ['found' => 0, 'removed' => 0], $userContext->config->getAll()),
        ];

        $ref = &$this->perRun[$userContext->name];

        $progressUpdate = 0;
        $recordsCount = number_format($records);
        foreach ($userContext->db->fetch() as $item) {
            try {
                if (count($item->getMetadata()) < 1) {
                    $this->output(
                        Level::Warning,
                        "Removing '#{id}' for '{user}': no backend metadata was stored.",
                        [
                            'event_name' => 'state.validate.item.removed',
                            'subsystem' => 'state.validate',
                            'operation' => 'validate_item',
                            'outcome' => 'removed',
                            'command' => self::ROUTE,
                            'id' => $item->id,
                            'user' => $userContext->name,
                            'reason' => 'missing_metadata',
                        ],
                        $logIO,
                    );
                    $userContext->db->remove($item);
                    $ref['removed']++;
                    continue;
                }

                $meta = $item->getMetadata();

                $this->output(
                    Level::Debug,
                    "Checking stored references for '{user}' item '#{id}: {title}'.",
                    [
                        'event_name' => 'state.validate.item.started',
                        'subsystem' => 'state.validate',
                        'operation' => 'validate_item',
                        'outcome' => 'started',
                        'command' => self::ROUTE,
                        'id' => $item->id,
                        'user' => $userContext->name,
                        'title' => $item->getName(),
                        'backends' => array_keys($meta),
                    ],
                    $logIO,
                );

                foreach ($meta as $backend => $metadata) {
                    $id = ag($metadata, iState::COLUMN_ID);
                    $this->output(
                        Level::Debug,
                        "Checking reference '{item_id}' for '{user}@{backend}' item '#{id}: {title}'.",
                        [
                            'event_name' => 'state.validate.reference.started',
                            'subsystem' => 'state.validate',
                            'operation' => 'validate_reference',
                            'outcome' => 'started',
                            'command' => self::ROUTE,
                            'id' => $item->id,
                            'item_id' => $id,
                            'user' => $userContext->name,
                            'title' => $item->getName(),
                            'backend' => $backend,
                        ],
                        $logIO,
                    );

                    if (null === $id) {
                        $this->output(
                            Level::Notice,
                            "Removing stored reference for '{user}@{backend}' item '#{id}': no backend id was saved.",
                            [
                                'event_name' => 'state.validate.reference.removed',
                                'subsystem' => 'state.validate',
                                'operation' => 'validate_reference',
                                'outcome' => 'removed',
                                'command' => self::ROUTE,
                                'id' => $item->id,
                                'backend' => $backend,
                                'user' => $userContext->name,
                                'reason' => 'missing_reference_id',
                            ],
                            $logIO,
                        );
                        $ref['removed']++;
                        $item->removeMetadata($backend);
                        continue;
                    }

                    if (null === ($clients[$backend] ?? null)) {
                        $this->output(
                            Level::Warning,
                            "Removing '{user}' item '#{id}' reference for '{backend}': backend is no longer configured.",
                            [
                                'event_name' => 'state.validate.reference.removed',
                                'subsystem' => 'state.validate',
                                'operation' => 'validate_reference',
                                'outcome' => 'removed',
                                'command' => self::ROUTE,
                                'id' => $item->id,
                                'user' => $userContext->name,
                                'backend' => $backend,
                                'reason' => 'backend_missing',
                            ],
                            $logIO,
                        );
                        $item->removeMetadata($backend);
                        continue;
                    }

                    $sub_ref = &$this->perRun[$userContext->name]['backends'][$backend];

                    $cacheKey = $clients[$backend]->getContext()->backendUrl . $id;

                    try {
                        if (isset($this->cache[$cacheKey])) {
                            $data = $this->cache[$cacheKey];
                        } else {
                            $data = $clients[$backend]->getMetadata($id);
                            $data = !(count($data) < 1);
                            $this->cache[$cacheKey] = $data;
                        }

                        if (false === $data) {
                            $this->output(
                                Level::Notice,
                                "Removing '{user}@{backend}' reference '{item_id}' from item '#{id}': backend returned no metadata.",
                                [
                                    'event_name' => 'state.validate.reference.removed',
                                    'subsystem' => 'state.validate',
                                    'operation' => 'validate_reference',
                                    'outcome' => 'removed',
                                    'command' => self::ROUTE,
                                    'id' => $item->id,
                                    'item_id' => $id,
                                    'user' => $userContext->name,
                                    'backend' => $backend,
                                    'reason' => 'metadata_missing',
                                ],
                                $logIO,
                            );
                            $sub_ref['removed']++;
                            $item->removeMetadata($backend);
                            continue;
                        }

                        $sub_ref['found']++;
                    } catch (Throwable $e) {
                        $this->output(
                            Level::Warning,
                            "Removing '{user}@{backend}' reference '{item_id}' from item '#{id}': metadata lookup failed.",
                            [
                                'event_name' => 'state.validate.reference.removed',
                                'subsystem' => 'state.validate',
                                'operation' => 'validate_reference',
                                'outcome' => 'removed',
                                'command' => self::ROUTE,
                                'id' => $item->id,
                                'item_id' => $id,
                                'user' => $userContext->name,
                                'backend' => $backend,
                                'reason' => 'metadata_lookup_failed',
                                ...exception_log($e),
                            ],
                            $logIO,
                        );
                        $sub_ref['removed']++;
                        $this->cache[$cacheKey] = false;
                        $item->removeMetadata($backend);
                        continue;
                    }
                }

                if (count($item->metadata) < 1) {
                    $this->output(
                        Level::Notice,
                        "Removing '#{id}' for '{user}': no backend references remain.",
                        [
                            'event_name' => 'state.validate.item.removed',
                            'subsystem' => 'state.validate',
                            'operation' => 'validate_item',
                            'outcome' => 'removed',
                            'command' => self::ROUTE,
                            'id' => $item->id,
                            'user' => $userContext->name,
                            'reason' => 'no_references_remaining',
                        ],
                        $logIO,
                    );

                    $ref['removed']++;
                    $userContext->db->remove($item);
                    continue;
                }

                if ($item->diff()) {
                    $ref['updated']++;
                    $userContext->db->update($item);
                } else {
                    $ref['no_change']++;
                }
            } finally {
                if (null === $progBar) {
                    $progressUpdate++;
                    if (0 === ($progressUpdate % 500)) {
                        $this->output(
                            Level::Info,
                            "Validated {progress}/{total} records ({percent}%) for '{user}'.",
                            [
                                'event_name' => 'state.validate.progress',
                                'subsystem' => 'state.validate',
                                'operation' => 'validate',
                                'outcome' => 'running',
                                'command' => self::ROUTE,
                                'user' => $userContext->name,
                                'progress' => number_format($progressUpdate),
                                'total' => $recordsCount,
                                'percent' => round(($progressUpdate / $records) * 100, 3),
                            ],
                            $logIO,
                        );
                    }
                } else {
                    $progBar->progressAdvance();
                }
            }
        }

        if (null !== $progBar) {
            $progBar->progressFinish();
            $progBar->newLine();
        }
    }

    private function output(Level $level, string $message, array $context = [], ?SymfonyStyle $io = null): void
    {
        if (null !== $io) {
            $io->writeln(r($message, $context), self::TO_VERBOSITY[$level->value] ?? iOutput::VERBOSITY_NORMAL);
            return;
        }

        $this->logger->log($level, $message, $context);
    }

    private function renderStatus(iOutput $output): void
    {
        foreach ($this->perRun as $user => $data) {
            $this->logger->notice("Validation summary for '{user}': updated {updated_count}, removed {removed_count}, unchanged {unchanged_count}.", [
                'event_name' => 'state.validate.summary',
                'subsystem' => 'state.validate',
                'operation' => 'summarize',
                'outcome' => 'completed',
                'command' => self::ROUTE,
                'user' => $user,
                'updated_count' => $data['updated'],
                'removed_count' => $data['removed'],
                'unchanged_count' => $data['no_change'],
                'backends' => $data['backends'],
            ]);

            $tbl = [];

            $total = count($data['backends']);
            $i = 0;
            foreach ($data['backends'] as $backend => $backendData) {
                $i++;
                $tbl[] = [
                    'backend' => $backend,
                    'reference_found' => $backendData['found'],
                    'reference_removed' => $backendData['removed'],
                ];
                if ($i < $total) {
                    $tbl[] = new TableSeparator();
                }
            }

            $output->writeln('');
            new Table($output)
                ->setHeaders(array_keys($tbl[0]))
                ->setStyle('box')
                ->setRows(array_values($tbl))
                ->render();
            $output->writeln('');
        }
    }
}
