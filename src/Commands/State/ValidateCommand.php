<?php

declare(strict_types=1);

namespace App\Commands\State;

use App\Command;
use App\Libs\Attributes\DI\Inject;
use App\Libs\Attributes\Route\Cli;
use App\Libs\Entity\StateInterface as iState;
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
     * @var array<array-key,array<string,bool>> Store the status of item from backend in-case we have multiple sub-users.
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
            ->addOption('user', 'u', InputOption::VALUE_REQUIRED, 'Select user. Default all users.')
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

        $users = get_users_context(mapper: $this->mapper, logger: $this->logger);

        if (null !== ($user = $input->getOption('user'))) {
            $users = array_filter($users, static fn($k) => $k === $user, mode: ARRAY_FILTER_USE_KEY);
            if (empty($users)) {
                $output->writeln(r("<error>User '{user}' not found.</error>", ['user' => $user]));
                return self::FAILURE;
            }
        }

        $start_time = microtime(true);

        foreach ($users as $userContext) {
            $userStart = microtime(true);

            $this->output(
                Level::Notice,
                "SYSTEM: Validating '{user}' local database metadata reference ids.",
                [
                    'user' => $userContext->name,
                ],
                $logIO,
            );

            $this->validate($userContext, $io, $logIO);

            $this->output(
                Level::Notice,
                "SYSTEM: Completed '{user}' local database validation in '{duration}'s.",
                [
                    'user' => $userContext->name,
                    'duration' => round(microtime(true) - $userStart, 4),
                ],
                $logIO,
            );
        }

        $this->output(
            Level::Notice,
            "SYSTEM: Completed local databases validation in '{duration}'s.",
            [
                'duration' => round(microtime(true) - $start_time, 4),
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
                        "SYSTEM: No metadata found for item '{user}: #{id}' Removing record.",
                        [
                            'id' => $item->id,
                            'user' => $userContext->name,
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
                    "SYSTEM: Validating '{user}: #{id}' - '{title}' reference ID for '{backends}'.",
                    [
                        'id' => $item->id,
                        'user' => $userContext->name,
                        'title' => $item->getName(),
                        'backends' => implode(', ', array_keys($meta)),
                    ],
                    $logIO,
                );

                foreach ($meta as $backend => $metadata) {
                    $id = ag($metadata, iState::COLUMN_ID);
                    $this->output(
                        Level::Debug,
                        "SYSTEM: Validating '{user}@{backend}: #{id} - {item_id}' '{title}' reference ID.",
                        [
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
                            "SYSTEM: No reference ID found for item '{user}@{backend}: #{id}' Removing reference ID.",
                            [
                                'id' => $item->id,
                                'backend' => $backend,
                                'user' => $userContext->name,
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
                            "SYSTEM: '{user}: #{id}' has reference to '{backend}' which doesn't exists. Removing reference ID.",
                            [
                                'id' => $item->id,
                                'user' => $userContext->name,
                                'backend' => $backend,
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
                                "SYSTEM: Request for '{user}@{backend}: #{id} - {item_id}' didnt return any data. Removing reference ID.",
                                [
                                    'id' => $item->id,
                                    'item_id' => $id,
                                    'user' => $userContext->name,
                                    'backend' => $backend,
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
                            Level::Notice,
                            "SYSTEM: Request for '{user}@{backend}: #{id} - {item_id}'. returned with error. {error}. Removing reference ID.",
                            [
                                'id' => $item->id,
                                'item_id' => $id,
                                'user' => $userContext->name,
                                'backend' => $backend,
                                'error' => $e->getMessage(),
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
                        "SYSTEM: Item '{user}: #{id}' no longer have any reference ID. Removing record.",
                        [
                            'id' => $item->id,
                            'user' => $userContext->name,
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
                            "SYSTEM: Processed '{progress}/{total}' %{percent}.",
                            [
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
            $this->logger->notice("User '{user}' local database, had {u} updated, {r} removed, {n} no change.", [
                'user' => $user,
                'u' => $data['updated'],
                'r' => $data['removed'],
                'n' => $data['no_change'],
            ]);

            $tbl = [];

            $total = count($data['backends']);
            $i = 0;
            foreach ($data['backends'] as $backend => $backendData) {
                $i++;
                $tbl[] = [
                    'Backend' => $backend,
                    'Reference Found' => $backendData['found'],
                    'Reference Removed' => $backendData['removed'],
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
