<?php

declare(strict_types=1);

namespace App\Commands\State;

use App\Command;
use App\Libs\Attributes\Route\Cli;
use App\Libs\Config;
use App\Libs\Database\DatabaseInterface as iDB;
use App\Libs\MediaHealthReportGenerator;
use Psr\Log\LoggerInterface as iLogger;
use Symfony\Component\Console\Input\InputInterface as iInput;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface as iOutput;
use Throwable;

#[Cli(command: self::ROUTE)]
final class MediaHealthCommand extends Command
{
    public const string ROUTE = 'state:media-health';
    public const string TASK_NAME = 'media_health';

    public function __construct(
        private readonly MediaHealthReportGenerator $generator,
        private readonly iDB $db,
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
            ->setDescription('Generate media health audit for the main user database.')
            ->addOption(
                'check-files',
                null,
                InputOption::VALUE_NONE,
                'Validate local media paths. Only use when WatchState can access the media filesystem.',
            );
    }

    /**
     * Generate cached media health audit.
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
            $this->generator->generate(
                database: $this->db,
                expectedBackends: array_keys((array) Config::get('servers', [])),
                checkFiles: true === (bool) $input->getOption('check-files')
                || true === (bool) Config::get('media_health.check_files', false),
            );
        } catch (Throwable $e) {
            $this->logger->error('Failed to generate media health audit. {exception.message}', [
                'operation' => 'media_health.generate',
                ...exception_log($e),
            ]);

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
