<?php

declare(strict_types=1);

namespace App\Commands\System;

use App\API\Backends\Index;
use App\Command;
use App\Libs\Attributes\Route\Cli;
use App\Libs\Config;
use App\Libs\Database\DatabaseInterface as iDB;
use App\Libs\Entity\StateEntity;
use App\Libs\Extends\ConsoleOutput;
use App\Libs\Extends\Date;
use App\Libs\Mappers\ImportInterface as iImport;
use App\Libs\Options;
use App\Libs\UserContext;
use Cron\CronExpression;
use LimitIterator;
use Psr\Log\LoggerInterface as iLogger;
use RuntimeException;
use SplFileObject;
use Symfony\Component\Console\Input\InputInterface as iInput;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface as iOutput;
use Symfony\Component\Yaml\Yaml;
use Throwable;

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
     * @var array<string> $sensitive strip sensitive information from the report.
     */
    private array $sensitive = [];

    /**
     * @var iOutput|null $output The output instance.
     */
    private iOutput|null $output = null;

    /**
     * Class Constructor.
     *
     * @param iDB $db An instance of the iDB class used for database operations.
     *
     * @return void
     */
    public function __construct(
        private readonly iDB $db,
        private readonly iImport $mapper,
        private readonly iLogger $logger
    ) {
        parent::__construct();
    }

    /**
     * Configure the command.
     */
    protected function configure(): void
    {
        $this->setName(self::ROUTE)
            ->setDescription('Show basic information for diagnostics.')
            ->addOption(
                'limit',
                'l',
                InputOption::VALUE_OPTIONAL,
                'Show last X number of log lines.',
                self::DEFAULT_LIMIT
            )
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

        $this->filter('<info>[ Basic Report ]</info>' . PHP_EOL);
        $this->filter(r('WatchState version: <flag>{answer}</flag>', ['answer' => getAppVersion()]));
        $this->filter(r('PHP version: <flag>{answer}</flag>', ['answer' => PHP_SAPI . '/' . PHP_VERSION]));
        $this->filter(r('Timezone: <flag>{answer}</flag>', ['answer' => Config::get('tz', 'UTC')]));
        $this->filter(r('Data path: <flag>{answer}</flag>', ['answer' => Config::get('path')]));
        $this->filter(r('Temp path: <flag>{answer}</flag>', ['answer' => Config::get('tmpDir')]));
        $this->filter(
            r('Database migrated?: <flag>{answer}</flag>', ['answer' => $this->db->isMigrated() ? 'Yes' : 'No'])
        );
        $this->filter(
            r("Does the '.env' file exists? <flag>{answer}</flag>", [
                'answer' => file_exists(Config::get('path') . '/config/.env') ? 'Yes' : 'No',
            ])
        );

        $this->filter(
            r('Is the tasks runner working? <flag>{answer}</flag>', [
                'answer' => (function () {
                    $info = isTaskWorkerRunning(ignoreContainer: true);
                    return r("{status} '{container}' - {message}", [
                        'status' => $info['status'] ? 'Yes' : 'No',
                        'message' => $info['message'],
                        'container' => inContainer() ? 'Container' : 'Unknown',
                    ]);
                })(),
            ])
        );

        $this->filter(r('Running in container? <flag>{answer}</flag>', ['answer' => inContainer() ? 'Yes' : 'No']));

        $this->filter(r('Report generated at: <flag>{answer}</flag>', ['answer' => gmdate(Date::ATOM)]));

        $this->filter(PHP_EOL . '<info>[ Backends ]</info>' . PHP_EOL);
        $this->getBackends($input);

        $this->filter(PHP_EOL . '<info>[ Log suppression ]</info>' . PHP_EOL);
        $this->getSuppressor();

        $this->filter('<info>[ Tasks ]</info>' . PHP_EOL);
        $this->getTasks();
        $this->filter('<info>[ Logs ]</info>' . PHP_EOL);
        $this->getLogs($input);
        $this->printFooter();

        return self::SUCCESS;
    }

    /**
     * Get backends and display information about each backend.
     *
     * @param iInput $input An instance of the iInput class used for input operations.
     *
     * @return void
     */
    private function getBackends(iInput $input): void
    {
        $includeSample = (bool)$input->getOption('include-db-sample');

        $usersContext = getUsersContext($this->mapper, $this->logger);
        $this->extractSensitive($usersContext);

        if (count($usersContext) > 1) {
            $this->filter(
                r('Users? {users}' . PHP_EOL, [
                    'users' => implode(', ', array_keys($usersContext)),
                ])
            );
        }

        foreach ($usersContext as $username => $userContext) {
            foreach ($userContext->config->getAll() as $name => $backend) {
                try {
                    $version = makeBackend(backend: $backend, name: $name, options: [
                        UserContext::class => $userContext,
                    ])->setLogger($this->logger)->getVersion();
                } catch (Throwable) {
                    $version = 'Unknown';
                }

                foreach (Index::BLACK_LIST as $hideValue) {
                    if (true === ag_exists($backend, $hideValue)) {
                        $backend = ag_set($backend, $hideValue, '**HIDDEN**');
                    }
                }

                $this->filter(
                    r('[ <value>{type} ({version}) ==> {username}@{name}</value> ]' . PHP_EOL, [
                        'name' => $name,
                        'username' => $username,
                        'type' => ucfirst(ag($backend, 'type')),
                        'version' => $version,
                    ])
                );

                $this->filter(
                    r('Is backend URL HTTPS? <flag>{answer}</flag>', [
                        'answer' => str_starts_with(ag($backend, 'url'), 'https:') ? 'Yes' : 'No',
                    ])
                );

                $this->filter(
                    r('Has Unique Identifier? <flag>{answer}</flag>', [
                        'answer' => null !== ag($backend, 'uuid') ? 'Yes' : 'No',
                    ])
                );

                $this->filter(
                    r('Has User? <flag>{answer}</flag>', [
                        'answer' => null !== ag($backend, 'user') ? 'Yes' : 'No',
                    ])
                );

                $this->filter(
                    r('Export Enabled? <flag>{answer}</flag>', [
                        'answer' => null !== ag($backend, 'export.enabled') ? 'Yes' : 'No',
                    ])
                );

                if (null !== ag($backend, 'export.enabled')) {
                    $this->filter(
                        r('Time since last export? <flag>{answer}</flag>', [
                            'answer' => null === ag($backend, 'export.lastSync') ? 'Never' : gmdate(
                                Date::ATOM,
                                ag($backend, 'export.lastSync')
                            ),
                        ])
                    );
                }

                $this->filter(
                    r('Play state import enabled? <flag>{answer}</flag>', [
                        'answer' => null !== ag($backend, 'import.enabled') ? 'Yes' : 'No',
                    ])
                );

                $this->filter(
                    r('Metadata only import enabled? <flag>{answer}</flag>', [
                        'answer' => null !== ag($backend, 'options.' . Options::IMPORT_METADATA_ONLY) ? 'Yes' : 'No',
                    ])
                );

                if (null !== ag($backend, 'import.enabled')) {
                    $this->filter(
                        r('Time since last import? <flag>{answer}</flag>', [
                            'answer' => null === ag($backend, 'import.lastSync') ? 'Never' : gmdate(
                                Date::ATOM,
                                ag($backend, 'import.lastSync')
                            ),
                        ])
                    );
                }

                $this->filter(
                    r('Is webhook match user id enabled? <flag>{answer}</flag>', [
                        'answer' => true === (bool)ag($backend, 'webhook.match.user') ? 'Yes' : 'No',
                    ])
                );

                $this->filter(
                    r('Is webhook match backend unique id enabled? <flag>{answer}</flag>', [
                        'answer' => true === (bool)ag($backend, 'webhook.match.uuid') ? 'Yes' : 'No',
                    ])
                );

                $opts = ag($backend, 'options', []);
                $this->filter(
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
                    $stmt = $userContext->db->getDBLayer()->prepare($sql);
                    $stmt->execute([
                        'name' => $name,
                    ]);

                    $entries = [];

                    foreach ($stmt as $row) {
                        $entries[] = StateEntity::fromArray($row);
                    }

                    $this->filter(
                        r('Sample db entries related to backend.' . PHP_EOL . '{json}', [
                            'json' => count($entries) >= 1 ? json_encode(
                                $entries,
                                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
                            ) : '{}',
                        ])
                    );
                }

                $this->filter('');
            }
        }
    }

    /**
     * Retrieves the tasks and displays information about each task.
     *
     *
     * @return void
     */
    private function getTasks(): void
    {
        foreach (Config::get('tasks.list', []) as $task) {
            $this->filter(
                r('[ <value>{name}</value> ]' . PHP_EOL, [
                    'name' => ucfirst(ag($task, 'name')),
                ])
            );
            $enabled = true === (bool)ag($task, 'enabled');
            $this->filter(
                r('Is Task enabled? <flag>{answer}</flag>', [
                    'answer' => $enabled ? 'Yes' : 'No',
                ])
            );

            if (true === $enabled) {
                $this->filter(
                    r('Which flags are used to run the task? <flag>{answer}</flag>', [
                        'answer' => ag($task, 'args', 'None'),
                    ])
                );

                $this->filter(
                    r('When the task scheduled to run at? <flag>{answer}</flag>', [
                        'answer' => ag($task, 'timer', '???'),
                    ])
                );

                try {
                    $timer = new CronExpression(ag($task, 'timer', '5 * * * *'));
                    $this->filter(
                        r('When is the next scheduled run? <flag>{answer}</flag>', [
                            'answer' => gmdate(Date::ATOM, $timer->getNextRunDate()->getTimestamp()),
                        ])
                    );
                } catch (Throwable $e) {
                    $this->filter(
                        r('Next Run scheduled failed. <error>{answer}</error>', [
                            'answer' => $e->getMessage(),
                        ])
                    );
                }
            }

            /** @noinspection DisconnectedForeachInstructionInspection */
            $this->filter('');
        }
    }

    /**
     * Get logs.
     *
     * @param iInput $input An instance of the iInput class used for input operations.
     */
    private function getLogs(iInput $input): void
    {
        $todayAffix = makeDate()->format('Ymd');
        $yesterdayAffix = makeDate('yesterday')->format('Ymd');
        $limit = $input->getOption('limit');

        foreach (LogsCommand::getTypes() as $type) {
            $linesLimit = $limit;
            if (self::DEFAULT_LIMIT === $limit) {
                $linesLimit = $type === 'task' ? 75 : self::DEFAULT_LIMIT;
            }
            $this->handleLog($type, $todayAffix, $linesLimit);
            $this->filter('');
        }

        foreach (LogsCommand::getTypes() as $type) {
            $linesLimit = $limit;
            if (self::DEFAULT_LIMIT === $limit) {
                $linesLimit = $type === 'task' ? 75 : self::DEFAULT_LIMIT;
            }
            $this->handleLog($type, $yesterdayAffix, $linesLimit);
            $this->filter('');
        }
    }

    /**
     * Get last X lines from log file.
     *
     * @param string $type The type of the log.
     * @param string|int $date The date of the log file.
     * @param int|string $limit The maximum number of lines to display.
     *
     * @return void
     */
    private function handleLog(string $type, string|int $date, int|string $limit): void
    {
        $logFile = Config::get('tmpDir') . '/logs/' . r('{type}.{date}.log', ['type' => $type, 'date' => $date]);

        $this->filter(r('[ <value>{logFile}</value> ]' . PHP_EOL, [
            'logFile' => after($logFile, Config::get('tmpDir'))
        ]));

        if (!file_exists($logFile) || filesize($logFile) < 1) {
            $this->filter(r('{type} log file is empty or does not exists.', ['type' => $type]));
            return;
        }

        $file = new SplFileObject($logFile, 'r');

        if ($file->getSize() < 1) {
            $this->filter(r('{type} log file is empty or does not exists.', ['type' => $type]));
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

            $this->filter($line);
        }
    }

    private function printFooter(): void
    {
        $this->filter('<info><!-- Notice</info>');
        $this->filter(
            <<<FOOTER
            <value>
            Beware, while we try to make sure no sensitive information is leaked,
            it's your responsibility to check and review the report before posting it.
            </value>
            -->

            FOOTER
        );
    }

    private function getSuppressor(): void
    {
        $suppressFile = Config::get('path') . '/config/suppress.yaml';

        $this->filter(
            r("Does the 'suppress.yaml' file exists? <flag>{answer}</flag>", [
                'answer' => file_exists($suppressFile) ? 'Yes' : 'No',
            ])
        );

        if (filesize($suppressFile) > 10) {
            $this->filter('');
            $this->filter('User defined rules:');
            $this->filter('');

            try {
                $this->filter(
                    json_encode(
                        Yaml::parseFile($suppressFile),
                        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
                    )
                );
            } catch (Throwable $e) {
                $this->filter(r("Error during parsing of '{file}.' '{kind}' was thrown unhandled with '{message}'", [
                    'kind' => $e::class,
                    'message' => $e->getMessage(),
                ]));
            }
        }

        $this->filter('');
    }

    private function filter(string $text): void
    {
        foreach ($this->sensitive as $sensitive) {
            $text = str_ireplace($sensitive, '**HIDDEN**', $text);
        }

        $this->output?->writeln($text);
    }

    /**
     * Extract tokens from user configs to strip them from final report.
     *
     * @param array<UserContext> $usersContext
     */
    private function extractSensitive(array $usersContext): void
    {
        $keys = [
            'token',
            'options.' . Options::ADMIN_TOKEN,
            'options.' . Options::PLEX_USER_PIN,
            'options.' . Options::ADMIN_PLEX_USER_PIN,
        ];

        foreach ($usersContext as $userContext) {
            foreach ($userContext->config->getAll() as $backend) {
                foreach ($keys as $key) {
                    if (null === ($val = ag($backend, $key))) {
                        continue;
                    }
                    if (true === in_array($val, $this->sensitive, true)) {
                        continue;
                    }
                    $this->sensitive[] = $val;
                }
            }
        }
    }
}
