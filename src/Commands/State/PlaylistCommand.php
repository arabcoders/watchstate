<?php

declare(strict_types=1);

namespace App\Commands\State;

use App\Backends\Common\ClientInterface as iClient;
use App\Command;
use App\Libs\Attributes\DI\Inject;
use App\Libs\Attributes\Route\Cli;
use App\Libs\Config;
use App\Libs\Database\DatabaseInterface;
use App\Libs\Mappers\Import\DirectMapper;
use App\Libs\Mappers\ImportInterface as iImport;
use App\Libs\Options;
use App\Libs\Playlists\PlaylistSyncService;
use App\Libs\UserContext;
use Monolog\Level;
use Psr\Log\LoggerInterface as iLogger;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

#[Cli(command: self::ROUTE)]
class PlaylistCommand extends Command
{
    public const string ROUTE = 'state:playlist';

    public const string TASK_NAME = 'playlist';

    public function __construct(
        protected PlaylistSyncService $service,
        #[Inject(DirectMapper::class)]
        protected iImport $mapper,
        protected iLogger $logger,
    ) {
        set_time_limit(0);
        ini_set('memory_limit', '-1');

        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName(self::ROUTE)
            ->setDescription('Sync playlists cross backends.')
            ->addOption('user', 'u', InputOption::VALUE_REQUIRED, 'Select sub user. Default all users.')
            ->addOption(
                'select-backend',
                's',
                InputOption::VALUE_IS_ARRAY | InputOption::VALUE_OPTIONAL,
                'Select backend.',
            )
            ->addOption(
                'exclude',
                'e',
                InputOption::VALUE_NONE,
                'Inverse --select-backend logic. Exclude selected backends.',
            )
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Do not commit any playlist changes.')
            ->addOption('force-full', 'f', InputOption::VALUE_NONE, 'Ignore playlist last sync dates and fetch all selected backends.');
    }

    protected function runCommand(InputInterface $input, OutputInterface $output): int
    {
        return $this->single(fn(): int => $this->process($input, $output), $output, [
            iLogger::class => $this->logger,
            Level::class => Level::Error,
        ]);
    }

    protected function process(InputInterface $input, OutputInterface $output): int
    {
        $dbOpts = [];
        if (true === (bool) $input->getOption('trace')) {
            $dbOpts[Options::DEBUG_TRACE] = true;
        }

        $users = $this->getUsers($dbOpts);

        if (null !== ($user = $input->getOption('user'))) {
            $users = array_filter($users, static fn($key) => $key === $user, ARRAY_FILTER_USE_KEY);

            if ([] === $users) {
                $output->writeln(r("<error>User '{user}' not found.</error>", ['user' => $user]));
                return self::FAILURE;
            }
        }

        $rows = [];

        foreach ($users as $userContext) {
            $clients = $this->getClients(
                userContext: $userContext,
                selected: array_values($input->getOption('select-backend')),
                exclude: true === (bool) $input->getOption('exclude'),
                trace: true === (bool) $input->getOption('trace'),
            );

            $results = $this->service->sync($userContext, $clients, [
                Options::DRY_RUN => true === (bool) $input->getOption('dry-run'),
                Options::FORCE_FULL => true === (bool) $input->getOption('force-full'),
                'source_backends' => $this->getSourceBackends($userContext, array_keys($clients)),
                'target_backends' => $this->getTargetBackends($userContext, array_keys($clients)),
            ]);

            foreach ($results as $backend => $stats) {
                $rows[] = [
                    'User' => $userContext->name,
                    'Backend' => $backend,
                    'Playlists' => $stats['playlists'],
                    'Items' => $stats['items'],
                    'Added' => true === (bool) $input->getOption('dry-run') ? '-' : $stats['added'],
                    'Updated' => true === (bool) $input->getOption('dry-run') ? '-' : $stats['updated'],
                    'Removed' => true === (bool) $input->getOption('dry-run') ? '-' : $stats['removed'],
                ];
            }
        }

        if ([] === $rows) {
            $output->writeln('<comment>No matching backends produced syncable playlists.</comment>');
            return self::SUCCESS;
        }

        new Table($output)
            ->setStyle('box')
            ->setHeaders(array_keys($rows[0]))
            ->setRows($rows)
            ->render();

        return self::SUCCESS;
    }

    /**
     * @return array<string,\App\Libs\UserContext>
     */
    protected function getUsers(array $dbOpts = []): array
    {
        return get_users_context(mapper: $this->mapper, logger: $this->logger, opts: [
            DatabaseInterface::class => $dbOpts,
        ]);
    }

    /**
     * @param UserContext $userContext
     * @param array<int,string> $selected
     *
     * @return array<string,iClient>
     */
    protected function getClients(UserContext $userContext, array $selected = [], bool $exclude = false, bool $trace = false): array
    {
        $clients = [];
        $selected = array_values(array_filter(array_map(trim(...), $selected), static fn($item) => '' !== $item));

        foreach ($userContext->config->getAll() as $backendName => $backend) {
            if ($selected !== [] && $exclude === $this->matchesSelection($selected, $backendName)) {
                $this->logger->info("PLAYLIST: Ignoring '{user}@{backend}'. As requested.", [
                    'user' => $userContext->name,
                    'backend' => $backendName,
                ]);
                continue;
            }

            $importEnabled = true === (bool) ag($backend, 'import.enabled', false);
            $exportEnabled = true === (bool) ag($backend, 'export.enabled', false);

            if (false === $importEnabled && false === $exportEnabled) {
                if ($selected !== []) {
                    $this->logger->warning(
                        "PLAYLIST: Syncing disabled '{user}@{backend}' as requested.",
                        [
                            'user' => $userContext->name,
                            'backend' => $backendName,
                        ],
                    );
                } else {
                    $this->logger->info(
                        "PLAYLIST: Ignoring '{user}@{backend}'. Playlist sync disabled.",
                        [
                            'user' => $userContext->name,
                            'backend' => $backendName,
                        ],
                    );
                    continue;
                }
            }

            $backendType = strtolower((string) ag($backend, 'type', ''));
            if (null === Config::get("supported.{$backendType}")) {
                $this->logger->warning(
                    "PLAYLIST: Ignoring '{user}@{backend}'. Unsupported backend type '{type}'.",
                    [
                        'user' => $userContext->name,
                        'backend' => $backendName,
                        'type' => $backendType,
                    ],
                );
                continue;
            }

            $url = (string) ag($backend, 'url', '');
            if (false === filter_var($url, FILTER_VALIDATE_URL)) {
                $this->logger->warning(
                    "PLAYLIST: Ignoring '{user}@{backend}'. Invalid URL '{url}'.",
                    [
                        'user' => $userContext->name,
                        'backend' => $backendName,
                        'url' => $url,
                    ],
                );
                continue;
            }

            $opts = ag($backend, 'options', []);
            if (true === $trace) {
                $opts[Options::DEBUG_TRACE] = true;
            }

            $backend['options'] = $opts;
            $backend['name'] = $backendName;

            try {
                $clients[$backendName] = make_backend($backend, $backendName, [
                    UserContext::class => $userContext,
                    iLogger::class => $this->logger,
                ]);
            } catch (Throwable $e) {
                $this->logger->error(
                    "PLAYLIST: Failed to initialize '{user}@{backend}' client. '{error.message}' at '{error.file}:{error.line}'.",
                    [
                        'user' => $userContext->name,
                        'backend' => $backendName,
                        'error' => [
                            'message' => $e->getMessage(),
                            'file' => after($e->getFile(), ROOT_PATH),
                            'line' => $e->getLine(),
                            'kind' => $e::class,
                        ],
                    ],
                );
            }
        }

        return $clients;
    }

    /**
     * @param UserContext $userContext
     * @param array<int,string> $backends
     *
     * @return array<int,string>
     */
    protected function getSourceBackends(UserContext $userContext, array $backends): array
    {
        return array_values(array_filter(
            $backends,
            static fn(string $backendName): bool => true === (bool) $userContext->config->get("{$backendName}.import.enabled", false),
        ));
    }

    /**
     * @param UserContext $userContext
     * @param array<int,string> $backends
     *
     * @return array<int,string>
     */
    protected function getTargetBackends(UserContext $userContext, array $backends): array
    {
        return array_values(array_filter(
            $backends,
            static fn(string $backendName): bool => true === (bool) $userContext->config->get("{$backendName}.export.enabled", false),
        ));
    }

    /**
     * @param array<int,string> $selected
     */
    private function matchesSelection(array $selected, string $backendName): bool
    {
        foreach ($selected as $value) {
            if (true === str_starts_with($backendName, $value)) {
                return true;
            }
        }

        return false;
    }
}
