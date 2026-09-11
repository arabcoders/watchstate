<?php

declare(strict_types=1);

namespace App\Commands\State;

use App\Command;
use App\Libs\Attributes\Route\Cli;
use App\Libs\BackendReportGenerator;
use App\Libs\Mappers\ImportInterface as iImport;
use Psr\Log\LoggerInterface as iLogger;
use Symfony\Component\Console\Input\InputInterface as iInput;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface as iOutput;
use Throwable;

#[Cli(command: BackendReportCommand::ROUTE)]
final class BackendReportCommand extends Command
{
    public const string ROUTE = 'state:backend-report';
    public const string TASK_NAME = 'backend_report';

    public function __construct(
        private readonly BackendReportGenerator $generator,
        private readonly iImport $mapper,
        private readonly iLogger $logger,
    ) {
        set_time_limit(0);
        parent::__construct();
    }

    /**
     * Configure the command.
     */
    protected function configure(): void
    {
        $this
            ->setName(self::ROUTE)
            ->setDescription('Generate backend media statistics for configured identities.')
            ->addOption('user', 'u', InputOption::VALUE_REQUIRED, 'Select user. Default is all users.');
    }

    /**
     * Generate cached backend statistics.
     *
     * @param iInput $input Input instance.
     * @param iOutput $output Output instance.
     *
     * @return int Command status code.
     */
    protected function runCommand(iInput $input, iOutput $output): int
    {
        return $this->single(fn(): int => $this->generate($input), $output, [
            iLogger::class => $this->logger,
        ]);
    }

    private function generate(iInput $input): int
    {
        try {
            $users = select_users($input->getOption('user'));
        } catch (Throwable $e) {
            $this->logger->error('Failed to resolve backend report identities. {exception.message}', [
                'operation' => 'backend_report.resolve_users',
                ...exception_log($e),
            ]);

            return self::FAILURE;
        }

        $startedAt = microtime(true);
        $completed = 0;
        $failed = 0;

        $this->logger->notice('Using WatchState {full_version}', [
            'full_version' => get_full_version(),
        ]);
        $this->logger->notice("Starting backend report generation for '{total}' identities.", [
            'operation' => 'backend_report.start',
            'total' => count($users),
            'stats' => ['identities' => count($users)],
        ]);

        foreach ($users as $user) {
            try {
                $context = get_user_context($user, $this->mapper, $this->logger);
                $backendCount = count($context->config->getAll());
                $this->logger->notice(
                    "Generating backend report for '{identity.user}' with '{backend_count}' configured backends.",
                    [
                        'operation' => 'backend_report.identity',
                        'identity' => ['user' => $context->name],
                        'backend_count' => $backendCount,
                        'stats' => ['backends' => $backendCount],
                    ],
                );
                $this->generator->generate($context);
                $completed++;
            } catch (Throwable $e) {
                $failed++;
                $this->logger->error(
                    "Backend report generation failed for '{identity.user}'. {exception.message}",
                    [
                        'operation' => 'backend_report.identity',
                        'identity' => ['user' => $user],
                        ...exception_log($e),
                    ],
                );
            }
        }

        $duration = round(microtime(true) - $startedAt, 4);
        $this->logger->notice(
            "Backend report generation completed in '{duration}'s for '{total}' identities: '{completed}' completed and '{failed}' failed.",
            [
                'operation' => 'backend_report.complete',
                'duration' => $duration,
                'total' => count($users),
                'completed' => $completed,
                'failed' => $failed,
                'stats' => [
                    'identities' => ['total' => count($users), 'completed' => $completed, 'failed' => $failed],
                ],
            ],
        );

        return 0 < $failed ? self::FAILURE : self::SUCCESS;
    }
}
