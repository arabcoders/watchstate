<?php

declare(strict_types=1);

namespace App\Commands\System;

use App\Command;
use App\Libs\Attributes\Route\Cli;
use App\Libs\Extends\ConsoleOutput;
use App\Libs\Extends\Date;
use App\Libs\ReportGenerator;
use RuntimeException;
use Symfony\Component\Console\Input\InputInterface as iInput;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface as iOutput;

/**
 * Class ReportCommand
 *
 * Show basic information for diagnostics.
 */
#[Cli(command: self::ROUTE)]
final class ReportCommand extends Command
{
    public const string ROUTE = 'system:report';

    private const int DEFAULT_LIMIT = 10;

    /**
     * @var iOutput|null $output The output instance.
     */
    private ?iOutput $output = null;

    /**
     * Class Constructor.
     *
     * @param ReportGenerator $generator The report data generator.
     */
    public function __construct(
        private readonly ReportGenerator $generator,
    ) {
        parent::__construct();
    }

    /**
     * Configure the command.
     */
    protected function configure(): void
    {
        $this
            ->setName(self::ROUTE)
            ->setDescription('Show basic information for diagnostics.')
            ->addOption(
                'limit',
                'l',
                InputOption::VALUE_OPTIONAL,
                'Show last X number of log lines.',
                self::DEFAULT_LIMIT,
            )
            ->addOption(
                'include-db-sample',
                's',
                InputOption::VALUE_NONE,
                'Include Some synced entries for backends.',
            )
            ->setHelp(
                <<<HELP
                    This command generate basic report to diagnose problems. it should be included in any
                    support requests. as it's reduces steps necessary to diagnose problems.
                    <notice>
                    Beware, while we try to make sure no sensitive information is leaked, it's possible
                    that some private information might be leaked via the logs section.
                    Please review the report before posting it.
                    </notice>
                    HELP,
            );
    }

    /**
     * Display basic information for diagnostics.
     *
     * @param iInput $input An instance of the iInput class used for command input.
     * @param iOutput $output An instance of the iOutput class used for command output.
     *
     * @return int Returns the command execution status code.
     */
    protected function runCommand(iInput $input, iOutput $output): int
    {
        assert($output instanceof ConsoleOutput, new RuntimeException('Expecting ConsoleOutput instance.'));
        $this->output = $output->withNoSuppressor();

        $limit = (int) $input->getOption('limit');
        $includeSample = (bool) $input->getOption('include-db-sample');

        $report = $this->generator->generate($limit, $includeSample);

        $this->formatSystem(ag($report, 'system', []), (string) ag($report, 'generated_at', ''));
        $this->formatBackends(ag($report, 'users', []), ag($report, 'backends', []));
        $this->formatSuppression(ag($report, 'suppression', []));
        $this->formatTasks(ag($report, 'tasks', []));
        $this->formatLogs(ag($report, 'logs', []));
        $this->printFooter();

        return self::SUCCESS;
    }

    /**
     * Format and display system information.
     *
     * @param array<string,mixed> $system The system data.
     * @param string $generatedAt The report generation timestamp.
     */
    private function formatSystem(array $system, string $generatedAt): void
    {
        $this->line('<info>[ Basic Report ]</info>' . PHP_EOL);
        $this->line(r('WatchState version: <flag>{answer}</flag>', ['answer' => ag($system, 'version', '')]));
        $this->line(r('PHP version: <flag>{answer}</flag>', [
            'answer' => ag($system, 'sapi', '') . '/' . ag($system, 'php_version', ''),
        ]));
        $this->line(r('Timezone: <flag>{answer}</flag>', ['answer' => ag($system, 'timezone', '')]));
        $this->line(r('Data path: <flag>{answer}</flag>', ['answer' => ag($system, 'data_path', '')]));
        $this->line(r('Temp path: <flag>{answer}</flag>', ['answer' => ag($system, 'temp_path', '')]));
        $this->line(r('Database migrated?: <flag>{answer}</flag>', [
            'answer' => true === ag($system, 'database_migrated') ? 'Yes' : 'No',
        ]));
        $this->line(r("Does the '.env' file exists? <flag>{answer}</flag>", [
            'answer' => true === ag($system, 'env_file_exists') ? 'Yes' : 'No',
        ]));

        $container = true === ag($system, 'in_container') ? 'Container' : 'Unknown';
        $this->line(r('Is the tasks scheduler working? <flag>{answer}</flag>', [
            'answer' => r('{status} {container} - {message}', [
                'status' => true === ag($system, 'scheduler_running') ? 'Yes' : 'No',
                'container' => "'{$container}'",
                'message' => ag($system, 'scheduler_message', ''),
            ]),
        ]));
        $this->line(r('Running in container? <flag>{answer}</flag>', [
            'answer' => true === ag($system, 'in_container') ? 'Yes' : 'No',
        ]));
        $this->line(r('Report generated at: <flag>{answer}</flag>', ['answer' => $generatedAt]));
    }

    /**
     * Format and display backend information.
     *
     * @param array<int,string> $users The list of users.
     * @param array<int,array<string,mixed>> $backends The backend data.
     */
    private function formatBackends(array $users, array $backends): void
    {
        $this->line(PHP_EOL . '<info>[ Backends ]</info>' . PHP_EOL);

        if (count($users) > 1) {
            $this->line(r('Users? {users}' . PHP_EOL, [
                'users' => implode(', ', $users),
            ]));
        }

        foreach ($backends as $backend) {
            $version = ag($backend, 'version', 'Unknown') ?? 'Unknown';
            $this->line(r('[ <value>{type} ({version}) ==> {user}@{name}</value> ]' . PHP_EOL, [
                'name' => ag($backend, 'name', ''),
                'username' => ag($backend, 'user', ''),
                'type' => ucfirst((string) ag($backend, 'type', '')),
                'version' => $version,
            ]));

            $this->line(r('Is backend URL HTTPS? <flag>{answer}</flag>', [
                'answer' => true === ag($backend, 'https') ? 'Yes' : 'No',
            ]));
            $this->line(r('Has Unique Identifier? <flag>{answer}</flag>', [
                'answer' => true === ag($backend, 'has_uuid') ? 'Yes' : 'No',
            ]));
            $this->line(r('Has User? <flag>{answer}</flag>', [
                'answer' => true === ag($backend, 'has_user') ? 'Yes' : 'No',
            ]));

            $export = ag($backend, 'export', []);
            $this->line(r('Export Enabled? <flag>{answer}</flag>', [
                'answer' => true === ag($export, 'enabled') ? 'Yes' : 'No',
            ]));

            if (true === ag($export, 'enabled')) {
                $this->line(r('Time since last export? <flag>{answer}</flag>', [
                    'answer' => $this->formatDate(ag($export, 'last_sync')),
                ]));
                $this->line(r('Time since last playlist export? <flag>{answer}</flag>', [
                    'answer' => $this->formatDate(ag($export, 'playlist_last_sync')),
                ]));
            }

            $import = ag($backend, 'import', []);
            $this->line(r('Play state import enabled? <flag>{answer}</flag>', [
                'answer' => true === ag($import, 'enabled') ? 'Yes' : 'No',
            ]));
            $this->line(r('Metadata refresh enabled? <flag>{answer}</flag>', [
                'answer' => true === ag($import, 'metadata_refresh') ? 'Yes' : 'No',
            ]));

            if (true === ag($import, 'metadata_refresh')) {
                $this->line(r('Time since last import? <flag>{answer}</flag>', [
                    'answer' => $this->formatDate(ag($import, 'last_sync')),
                ]));
                $this->line(r('Time since last playlist import? <flag>{answer}</flag>', [
                    'answer' => $this->formatDate(ag($import, 'playlist_last_sync')),
                ]));
            }

            $opts = ag($backend, 'options', []);
            $this->line(r('Has custom options? <flag>{answer}</flag>' . PHP_EOL . '{opts}', [
                'answer' => count($opts) >= 1 ? 'Yes' : 'No',
                'opts' => count($opts) >= 1
                    ? json_encode($opts, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                    : '{}',
            ]));

            $sampleEntries = ag($backend, 'sample_entries');
            if (null !== $sampleEntries && count($sampleEntries) >= 1) {
                $this->line(r('Sample db entries related to backend.' . PHP_EOL . '{json}', [
                    'json' => json_encode($sampleEntries, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                ]));
            }

            $this->line('');
        }
    }

    /**
     * Format and display log suppression rules.
     *
     * @param array<string,mixed> $suppression The suppression data.
     */
    private function formatSuppression(array $suppression): void
    {
        $this->line(PHP_EOL . '<info>[ Log suppression ]</info>' . PHP_EOL);

        $this->line(r("Does the 'suppress.yaml' file exists? <flag>{answer}</flag>", [
            'answer' => true === ag($suppression, 'file_exists') ? 'Yes' : 'No',
        ]));

        $rules = ag($suppression, 'rules');
        $error = ag($suppression, 'error');

        if (null !== $error) {
            $this->line(r('Error during parsing of suppress rules. {exception.message}', [
                'exception.message' => $error,
            ]));
        } elseif (null !== $rules) {
            $this->line('');
            $this->line('User defined rules:');
            $this->line('');
            $this->line(
                json_encode($rules, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
            );
        }

        $this->line('');
    }

    /**
     * Format and display scheduled tasks.
     *
     * @param array<int,array<string,mixed>> $tasks The task data.
     */
    private function formatTasks(array $tasks): void
    {
        $this->line('<info>[ Tasks ]</info>' . PHP_EOL);

        foreach ($tasks as $task) {
            $this->line(r('[ <value>{name}</value> ]' . PHP_EOL, [
                'name' => ucfirst((string) ag($task, 'name', '')),
            ]));

            $enabled = true === ag($task, 'enabled');
            $this->line(r('Is Task enabled? <flag>{answer}</flag>', [
                'answer' => $enabled ? 'Yes' : 'No',
            ]));

            if (true === $enabled) {
                $this->line(r('Which flags are used to run the task? <flag>{answer}</flag>', [
                    'answer' => ag($task, 'args', 'None'),
                ]));
                $this->line(r('When the task scheduled to run at? <flag>{answer}</flag>', [
                    'answer' => ag($task, 'timer', '???'),
                ]));

                $error = ag($task, 'error');
                if (null !== $error) {
                    $this->line(r('Next Run scheduled failed. <error>{answer}</error>', [
                        'answer' => $error,
                    ]));
                } else {
                    $this->line(r('When is the next scheduled run? <flag>{answer}</flag>', [
                        'answer' => ag($task, 'next_run', '???'),
                    ]));
                }
            }

            $this->line('');
        }
    }

    /**
     * Format and display recent logs.
     *
     * @param array<int,array<string,mixed>> $logs The log data.
     */
    private function formatLogs(array $logs): void
    {
        $this->line('<info>[ Logs ]</info>' . PHP_EOL);

        foreach ($logs as $log) {
            $entries = ag($log, 'entries', []);

            if (count($entries) < 1) {
                continue;
            }

            $this->line(r('---  {type} logs ---', [
                'type' => ag($log, 'type', ''),
            ]));

            foreach ($entries as $entry) {
                if (true === ag($entry, 'separator')) {
                    $this->line('<value>.....</value>');
                    continue;
                }

                $this->line(r('{date} {level} [{logger}] {message}', [
                    'date' => ag($entry, 'datetime', ''),
                    'level' => ag($entry, 'level', ''),
                    'logger' => ag($entry, 'logger', ''),
                    'message' => ag($entry, 'message', ''),
                ]));
            }

            $this->line('');
        }
    }

    private function printFooter(): void
    {
        $this->line('<info><!-- Notice</info>');
        $this->line(
            <<<FOOTER
                <value>
                Beware, while we try to make sure no sensitive information is leaked,
                it's your responsibility to check and review the report before posting it.
                </value>
                -->

                FOOTER,
        );
    }

    /**
     * Format a timestamp as ISO date or 'Never' if null.
     *
     * @param mixed $value The timestamp value.
     *
     * @return string
     */
    private function formatDate(mixed $value): string
    {
        if (null === $value) {
            return 'Never';
        }

        return gmdate(Date::ATOM, (int) $value);
    }

    /**
     * Write a line to the output.
     *
     * @param string $text The text to write.
     */
    private function line(string $text): void
    {
        $this->output?->writeln($text);
    }
}
