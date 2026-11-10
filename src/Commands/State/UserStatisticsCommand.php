<?php

declare(strict_types=1);

namespace App\Commands\State;

use App\Command;
use App\Libs\Attributes\Route\Cli;
use App\Libs\Mappers\ImportInterface as iImport;
use App\Libs\UserStatisticsGenerator;
use Psr\Log\LoggerInterface as iLogger;
use Symfony\Component\Console\Input\InputInterface as iInput;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface as iOutput;
use Throwable;

#[Cli(command: UserStatisticsCommand::ROUTE)]
final class UserStatisticsCommand extends Command
{
    public const string ROUTE = 'state:user-statistics';
    public const string TASK_NAME = 'user_statistics';

    /**
     * @param UserStatisticsGenerator $generator Local statistics generator.
     * @param iImport $mapper Import mapper.
     * @param iLogger $logger Logger instance.
     */
    public function __construct(
        private readonly UserStatisticsGenerator $generator,
        private readonly iImport $mapper,
        private readonly iLogger $logger,
    ) {
        parent::__construct();
    }

    /**
     * Configure the local statistics task.
     */
    protected function configure(): void
    {
        $this
            ->setName(self::ROUTE)
            ->setDescription('Generate user statistics from local movie and episode records.')
            ->addOption('user', 'u', InputOption::VALUE_REQUIRED, 'Select user. Default is all users.');
    }

    /**
     * Generate per-user snapshots without contacting backends.
     */
    protected function runCommand(iInput $input, iOutput $output): int
    {
        return $this->single(
            function () use ($input): int {
                try {
                    $users = select_users($input->getOption('user'));
                } catch (Throwable $e) {
                    $this->logger->error('Failed to resolve user statistics identities. {exception.message}', [
                        'operation' => 'user_statistics.resolve_users',
                        ...exception_log($e),
                    ]);

                    return self::FAILURE;
                }

                $failed = false;
                foreach ($users as $user) {
                    try {
                        $report = $this->generator->generate(get_user_context($user, $this->mapper, $this->logger));
                        $this->logger->notice("Generated user statistics for '{identity.user}': '{stats.movies}' movies, '{stats.episodes}' episodes and '{stats.shows}' shows.", [
                            'operation' => 'user_statistics.generate',
                            'identity' => ['user' => $user],
                            'stats' => [
                                'movies' => $report['summary']['movies']['total'],
                                'episodes' => $report['summary']['episodes']['total'],
                                'shows' => $report['summary']['shows']['total'],
                            ],
                        ]);
                    } catch (Throwable $e) {
                        $failed = true;
                        $this->logger->error("User statistics generation failed for '{identity.user}'; the last successful snapshot was retained. {exception.message}", [
                            'operation' => 'user_statistics.generate',
                            'identity' => ['user' => $user],
                            ...exception_log($e),
                        ]);
                    }
                }

                return true === $failed ? self::FAILURE : self::SUCCESS;
            },
            $output,
            [iLogger::class => $this->logger],
        );
    }
}
