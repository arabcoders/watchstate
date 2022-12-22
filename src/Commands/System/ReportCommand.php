<?php

declare(strict_types=1);

namespace App\Commands\System;

use App\Command;
use App\Libs\Config;
use App\Libs\Database\DatabaseInterface as iDB;
use App\Libs\Extends\Date;
use App\Libs\Options;
use App\Libs\Routable;
use Cron\CronExpression;
use Exception;
use LimitIterator;
use SplFileObject;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[Routable(command: self::ROUTE)]
final class ReportCommand extends Command
{
    public const ROUTE = 'system:report';

    public function __construct(private iDB $db)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName(self::ROUTE)
            ->setDescription('Show basic information for diagnostics.')
            ->addOption('limit', 'l', InputOption::VALUE_OPTIONAL, 'Show last X number of log lines.', 10)
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

    protected function runCommand(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('<info>[ Basic Report ]</info>' . PHP_EOL);
        $output->writeln(r('WatchState Version: <flag>{answer}</flag>', ['answer' => getAppVersion()]));
        $output->writeln(r('Timezone: <flag>{answer}</flag>', ['answer' => Config::get('tz', 'UTC')]));
        $output->writeln(r('Running in Container? <flag>{answer}</flag>', ['answer' => inContainer() ? 'Yes' : 'No']));
        $output->writeln(r('Data Path: <flag>{answer}</flag>', ['answer' => Config::get('path')]));
        $output->writeln(r('Temp Path: <flag>{answer}</flag>', ['answer' => Config::get('tmpDir')]));
        $output->writeln(
            r('Database Migrated?: <flag>{answer}</flag>', ['answer' => $this->db->isMigrated() ? 'Yes' : 'No'])
        );
        $output->writeln(
            r('Has .env file? <flag>{answer}</flag>', [
                'answer' => file_exists(Config::get('path') . '/config/.env') ? 'Yes' : 'No',
            ])
        );
        $output->writeln(r('Report Generated At: <flag>{answer}</flag>', ['answer' => gmdate(Date::ATOM)]));

        $output->writeln(PHP_EOL . '<info>[ Backends ]</info>' . PHP_EOL);
        $this->getBackends($output);
        $output->writeln('<info>[ Tasks ]</info>' . PHP_EOL);
        $this->getTasks($output);
        $output->writeln('<info>[ Logs ]</info>' . PHP_EOL);
        $this->getLogs($input, $output);

        return self::SUCCESS;
    }

    private function getBackends(OutputInterface $output): void
    {
        foreach (Config::get('servers', []) as $name => $backend) {
            $output->writeln(
                r('[ <value>{type} ==> {name}</value> ]' . PHP_EOL, [
                    'name' => $name,
                    'type' => ucfirst(ag($backend, 'type')),
                ])
            );

            $output->writeln(
                r('Is backend URL HTTPS? <flag>{answer}</flag>', [
                    'answer' => str_starts_with(ag($backend, 'url'), 'https:') ? 'Yes' : 'No',
                ])
            );

            $output->writeln(
                r('Has Unique Identifier? <flag>{answer}</flag>', [
                    'answer' => null !== ag($backend, 'uuid') ? 'Yes' : 'No',
                ])
            );

            $output->writeln(
                r('Has User? <flag>{answer}</flag>', [
                    'answer' => null !== ag($backend, 'user') ? 'Yes' : 'No',
                ])
            );

            $output->writeln(
                r('Export Enabled? <flag>{answer}</flag>', [
                    'answer' => null !== ag($backend, 'export.enabled') ? 'Yes' : 'No',
                ])
            );

            if (null !== ag($backend, 'export.enabled')) {
                $output->writeln(
                    r('Time since last export? <flag>{answer}</flag>', [
                        'answer' => null === ag($backend, 'export.lastSync') ? 'Never' : gmdate(
                            Date::ATOM,
                            ag($backend, 'export.lastSync')
                        ),
                    ])
                );
            }

            $output->writeln(
                r('Play state import enabled? <flag>{answer}</flag>', [
                    'answer' => null !== ag($backend, 'import.enabled') ? 'Yes' : 'No',
                ])
            );

            $output->writeln(
                r('Metadata only import enabled? <flag>{answer}</flag>', [
                    'answer' => null !== ag($backend, 'options.' . Options::IMPORT_METADATA_ONLY) ? 'Yes' : 'No',
                ])
            );

            if (null !== ag($backend, 'import.enabled')) {
                $output->writeln(
                    r('Time since last import? <flag>{answer}</flag>', [
                        'answer' => null === ag($backend, 'import.lastSync') ? 'Never' : gmdate(
                            Date::ATOM,
                            ag($backend, 'import.lastSync')
                        ),
                    ])
                );
            }

            $output->writeln(
                r('Has webhook token? <flag>{answer}</flag>', [
                    'answer' => null !== ag($backend, 'webhook.token') ? 'Yes' : 'No',
                ])
            );

            $output->writeln(
                r('Is webhook match user id enabled? <flag>{answer}</flag>', [
                    'answer' => true === (bool)ag($backend, 'webhook.match.user') ? 'Yes' : 'No',
                ])
            );

            $output->writeln(
                r('Is webhook match backend unique id enabled? <flag>{answer}</flag>', [
                    'answer' => true === (bool)ag($backend, 'webhook.match.uuid') ? 'Yes' : 'No',
                ])
            );

            $opts = ag($backend, 'options', []);
            $opts = ag_delete($opts, 'options.' . Options::ADMIN_TOKEN);

            $output->writeln(
                r('Has custom options? <flag>{answer}</flag>' . PHP_EOL . '{opts}' . PHP_EOL, [
                    'answer' => count($opts) >= 1 ? 'Yes' : 'No',
                    'opts' => count($opts) >= 1 ? json_encode($opts) : '{}',
                ])
            );
        }
    }

    private function getTasks(OutputInterface $output): void
    {
        foreach (Config::get('tasks.list', []) as $task) {
            $output->writeln(
                r('[ <value>{name}</value> ]' . PHP_EOL, [
                    'name' => ucfirst(ag($task, 'name')),
                ])
            );
            $enabled = true === (bool)ag($task, 'enabled');
            $output->writeln(
                r('Is Task enabled? <flag>{answer}</flag>', [
                    'answer' => $enabled ? 'Yes' : 'No',
                ])
            );

            if (true === $enabled) {
                $output->writeln(
                    r('Which flags are used to run the task? <flag>{answer}</flag>', [
                        'answer' => ag($task, 'args', 'None'),
                    ])
                );

                $output->writeln(
                    r('When the task scheduled to run at? <flag>{answer}</flag>', [
                        'answer' => ag($task, 'timer', '???'),
                    ])
                );

                try {
                    $timer = new CronExpression(ag($task, 'timer', '5 * * * *'));
                    $output->writeln(
                        r('When is the next scheduled run? <flag>{answer}</flag>', [
                            'answer' => gmdate(Date::ATOM, $timer->getNextRunDate()->getTimestamp()),
                        ])
                    );
                } catch (Exception $e) {
                    $output->writeln(
                        r('Next Run scheduled failed. <error>{answer}</error>', [
                            'answer' => $e->getMessage(),
                        ])
                    );
                }
            }

            /** @noinspection DisconnectedForeachInstructionInspection */
            $output->writeln('');
        }
    }

    private function getLogs(InputInterface $input, OutputInterface $output): void
    {
        $todayAffix = makeDate()->format('Ymd');
        $yesterdayAffix = makeDate('yesterday')->format('Ymd');
        $limit = $input->getOption('limit');

        foreach (LogsCommand::getTypes() as $type) {
            $this->handleLog($output, $type, $todayAffix, $limit);
            /** @noinspection DisconnectedForeachInstructionInspection */
            $output->writeln('');
        }

        foreach (LogsCommand::getTypes() as $type) {
            $this->handleLog($output, $type, $yesterdayAffix, $limit);
            /** @noinspection DisconnectedForeachInstructionInspection */
            $output->writeln('');
        }
    }

    private function handleLog(OutputInterface $output, string $type, string|int $date, int|string $limit): void
    {
        $logFile = Config::get('tmpDir') . '/logs/' . r(
                '{type}.{date}.log',
                [
                    'type' => $type,
                    'date' => $date
                ]
            );

        $output->writeln(r('[ <value>{logFile}</value> ]' . PHP_EOL, ['logFile' => $logFile]));

        if (!file_exists($logFile) || filesize($logFile) < 1) {
            $output->writeln(r('{type} log file is empty or does not exists.', ['type' => $type]));
            return;
        }

        $file = new SplFileObject($logFile, 'r');

        if ($file->getSize() < 1) {
            $output->writeln(r('{type} log file is empty or does not exists.', ['type' => $type]));
            $file = null;
            return;
        }

        $file->seek(PHP_INT_MAX);

        $lastLine = $file->key();

        $it = new LimitIterator($file, max(0, $lastLine - $limit), $lastLine);

        foreach ($it as $line) {
            $line = trim((string)$line);

            if (empty($line)) {
                continue;
            }

            $output->writeln($line);
        }
    }
}
