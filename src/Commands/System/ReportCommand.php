<?php

declare(strict_types=1);

namespace App\Commands\System;

use App\Command;
use App\Libs\Config;
use App\Libs\Database\DatabaseInterface as iDB;
use App\Libs\Entity\StateEntity;
use App\Libs\Extends\Date;
use App\Libs\Options;
use App\Libs\Routable;
use Cron\CronExpression;
use Exception;
use LimitIterator;
use SplFileObject;
use Symfony\Component\Console\Input\InputInterface as iInput;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface as iOutput;
use Throwable;

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
            ->addOption(
                'include-db-sample',
                's',
                InputOption::VALUE_NONE,
                'Include Some synced entries for backends.'
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

    protected function runCommand(iInput $input, iOutput $output): int
    {
        $output->writeln('<info>[ Basic Report ]</info>' . PHP_EOL);
        $output->writeln(r('WatchState Version: <flag>{answer}</flag>', ['answer' => getAppVersion()]));
        $output->writeln(r('PHP Version: <flag>{answer}</flag>', ['answer' => PHP_VERSION]));
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

        if (inContainer()) {
            $output->writeln(
                r('Is Tasks Runner working? <flag>{answer}</flag>', [
                    'answer' => (function () {
                        $pidFile = '/tmp/job-runner.pid';
                        if (!file_exists($pidFile)) {
                            return 'No PID file was found - Likely means job-runner failed to run.';
                        }

                        try {
                            $pid = trim(file_get_contents($pidFile));
                        } catch (Throwable $e) {
                            return $e->getMessage();
                        }

                        if (file_exists(r('/proc/{pid}/status', ['pid' => $pid]))) {
                            return 'Yes';
                        }

                        return r('No. Found PID ({pid}) in file, but it seems the process crashed.', [
                            'pid' => $pid
                        ]);
                    })(),
                ])
            );
        }

        $output->writeln(r('Report Generated At: <flag>{answer}</flag>', ['answer' => gmdate(Date::ATOM)]));

        $output->writeln(PHP_EOL . '<info>[ Backends ]</info>' . PHP_EOL);
        $this->getBackends($input, $output);
        $output->writeln('<info>[ Tasks ]</info>' . PHP_EOL);
        $this->getTasks($output);
        $output->writeln('<info>[ Logs ]</info>' . PHP_EOL);
        $this->getLogs($input, $output);

        return self::SUCCESS;
    }

    private function getBackends(iInput $input, iOutput $output): void
    {
        $includeSample = (bool)$input->getOption('include-db-sample');

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
                r('Has custom options? <flag>{answer}</flag>' . PHP_EOL . '{opts}', [
                    'answer' => count($opts) >= 1 ? 'Yes' : 'No',
                    'opts' => count($opts) >= 1 ? json_encode(
                        $opts,
                        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
                    ) : '{}',
                ])
            );

            if (true === $includeSample) {
                $sql = "SELECT * FROM state WHERE via = :name ORDER BY updated DESC LIMIT 3";
                $stmt = $this->db->getPdo()->prepare($sql);
                $stmt->execute([
                    'name' => $name,
                ]);

                $entries = [];

                foreach ($stmt as $row) {
                    $entries[] = StateEntity::fromArray($row);
                }

                $output->writeln(
                    r('Sample db entries related to backend.' . PHP_EOL . '{json}', [
                        'json' => count($entries) >= 1 ? json_encode(
                            $entries,
                            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
                        ) : '{}',
                    ])
                );

                $output->writeln('');
            }
        }
    }

    private function getTasks(iOutput $output): void
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

    private function getLogs(iInput $input, iOutput $output): void
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

    private function handleLog(iOutput $output, string $type, string|int $date, int|string $limit): void
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
