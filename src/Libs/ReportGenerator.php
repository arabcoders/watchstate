<?php

declare(strict_types=1);

namespace App\Libs;

use App\API\Backends\Index;
use App\Commands\System\LogsCommand;
use App\Libs\Database\PackageMigrationFactory;
use App\Libs\Database\PdoFactory;
use App\Libs\Entity\StateEntity;
use App\Libs\Extends\Date;
use App\Libs\Mappers\ImportInterface as iImport;
use App\Libs\UserContext;
use Cron\CronExpression;
use LimitIterator;
use Psr\Log\LoggerInterface as iLogger;
use SplFileObject;
use Symfony\Component\Yaml\Yaml;
use Throwable;

/**
 * Class ReportGenerator
 *
 * Collects structured diagnostic data about the system, backends,
 * tasks, suppression rules, and recent logs. Used by both the API
 * and CLI to ensure a single source of truth.
 */
final class ReportGenerator
{
    private const int DEFAULT_LIMIT = 10;

    /**
     * Class constructor.
     *
     * @param iImport $mapper The import mapper.
     * @param iLogger $logger The logger instance.
     * @param PdoFactory $pdoFactory The PDO factory.
     * @param PackageMigrationFactory $migrations The package migration factory.
     */
    public function __construct(
        private readonly iImport $mapper,
        private readonly iLogger $logger,
        private readonly PdoFactory $pdoFactory,
        private readonly PackageMigrationFactory $migrations,
    ) {}

    /**
     * Generate a structured diagnostic report.
     *
     * @param int $logLimit Number of log lines to include per log type.
     * @param bool $includeDbSample Include sample database entries per backend.
     *
     * @return array<string,mixed> The structured report data.
     */
    public function generate(int $logLimit = self::DEFAULT_LIMIT, bool $includeDbSample = false): array
    {
        $usersContext = get_users_context($this->mapper, $this->logger);
        $sensitive = $this->extractSensitive($usersContext);

        $data = [
            'generated_at' => gmdate(Date::ATOM),
            'system' => $this->getSystemInfo(),
            'users' => array_keys($usersContext),
            'backends' => $this->getBackends($usersContext, $includeDbSample),
            'suppression' => $this->getSuppression(),
            'tasks' => $this->getTasks(),
            'logs' => $this->getLogs($logLimit),
        ];

        return $this->redactSensitive($data, $sensitive);
    }

    /**
     * Collect basic system information.
     *
     * @return array<string,mixed>
     */
    private function getSystemInfo(): array
    {
        $schedulerInfo = is_scheduler_running(ignoreContainer: true);

        return [
            'version' => get_app_version(),
            'php_version' => PHP_VERSION,
            'sapi' => PHP_SAPI,
            'timezone' => Config::get('tz', 'UTC'),
            'data_path' => Config::get('path'),
            'temp_path' => Config::get('tmpDir'),
            'database_migrated' => $this->migrations->isMigrated(
                $this->pdoFactory->createForFile((string) Config::get('database.file')),
            ),
            'env_file_exists' => file_exists(Config::get('path') . '/config/.env'),
            'scheduler_running' => (bool) ag($schedulerInfo, 'status', false),
            'scheduler_message' => (string) ag($schedulerInfo, 'message', ''),
            'in_container' => in_container(),
        ];
    }

    /**
     * Collect information about all configured backends across all users.
     *
     * @param array<UserContext> $usersContext The user contexts.
     * @param bool $includeDbSample Whether to include sample DB entries.
     *
     * @return array<int,array<string,mixed>>
     */
    private function getBackends(array $usersContext, bool $includeDbSample): array
    {
        $backends = [];

        foreach ($usersContext as $username => $userContext) {
            foreach ($userContext->config->getAll() as $name => $backend) {
                try {
                    $version = make_backend(
                        backend: $backend,
                        name: $name,
                        options: [UserContext::class => $userContext],
                    )
                        ->setLogger($this->logger)
                        ->getVersion();
                } catch (Throwable) {
                    $version = null;
                }

                foreach (Index::BLACK_LIST as $hideValue) {
                    if (true !== ag_exists($backend, $hideValue)) {
                        continue;
                    }
                    $backend = ag_set($backend, $hideValue, '**HIDDEN**');
                }

                $entry = [
                    'name' => $name,
                    'user' => $username,
                    'type' => ag($backend, 'type'),
                    'version' => $version,
                    'https' => str_starts_with((string) ag($backend, 'url'), 'https:'),
                    'has_uuid' => null !== ag($backend, 'uuid'),
                    'has_user' => null !== ag($backend, 'user'),
                    'export' => [
                        'enabled' => null !== ag($backend, 'export.enabled'),
                        'last_sync' => ag($backend, 'export.lastSync'),
                        'playlist_last_sync' => ag($backend, 'export.playlist.lastSync'),
                    ],
                    'import' => [
                        'enabled' => true === (bool) ag($backend, 'import.enabled'),
                        'metadata_refresh' => null !== ag($backend, 'import.enabled'),
                        'last_sync' => ag($backend, 'import.lastSync'),
                        'playlist_last_sync' => ag($backend, 'import.playlist.lastSync'),
                    ],
                    'options' => ag($backend, 'options', []),
                ];

                if (true === $includeDbSample) {
                    $entry['sample_entries'] = $this->getSampleEntries($userContext, $name);
                }

                $backends[] = $entry;
            }
        }

        return $backends;
    }

    /**
     * Get sample database entries for a backend.
     *
     * @param UserContext $userContext The user context.
     * @param string $name The backend name.
     *
     * @return array<int,array<string,mixed>>
     */
    private function getSampleEntries(UserContext $userContext, string $name): array
    {
        try {
            $sql = 'SELECT * FROM state WHERE via = :name ORDER BY updated DESC LIMIT 3';
            $stmt = $userContext->db->getDBLayer()->prepare($sql);
            $stmt->execute(['name' => $name]);

            $entries = [];
            foreach ($stmt as $row) {
                $entries[] = StateEntity::fromArray($row)->getAll();
            }

            return $entries;
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Get log suppression rules.
     *
     * @return array<string,mixed>
     */
    private function getSuppression(): array
    {
        $suppressFile = Config::get('path') . '/config/suppress.yaml';
        $exists = file_exists($suppressFile);

        if (false === $exists || filesize($suppressFile) <= 10) {
            return [
                'file_exists' => $exists,
                'rules' => null,
                'error' => null,
            ];
        }

        try {
            return [
                'file_exists' => true,
                'rules' => Yaml::parseFile($suppressFile),
                'error' => null,
            ];
        } catch (Throwable $e) {
            return [
                'file_exists' => true,
                'rules' => null,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Get scheduled tasks information.
     *
     * @return array<int,array<string,mixed>>
     */
    private function getTasks(): array
    {
        $tasks = [];

        foreach (Config::get('tasks.list', []) as $task) {
            $enabled = true === (bool) ag($task, 'enabled');
            $entry = [
                'name' => ag($task, 'name'),
                'enabled' => $enabled,
                'args' => ag($task, 'args'),
                'timer' => ag($task, 'timer'),
                'next_run' => null,
                'error' => null,
            ];

            if (true === $enabled) {
                try {
                    $timer = new CronExpression((string) ag($task, 'timer', '5 * * * *'));
                    $entry['next_run'] = gmdate(Date::ATOM, $timer->getNextRunDate()->getTimestamp());
                } catch (Throwable $e) {
                    $entry['error'] = $e->getMessage();
                }
            }

            $tasks[] = $entry;
        }

        return $tasks;
    }

    /**
     * Get recent log entries grouped by type, merging today and yesterday.
     *
     * @param int $limit Maximum number of lines per log type per day.
     *
     * @return array<int,array<string,mixed>>
     */
    private function getLogs(int $limit): array
    {
        $todayAffix = make_date()->format('Ymd');
        $yesterdayAffix = make_date('yesterday')->format('Ymd');

        $logs = [];

        foreach (LogsCommand::getTypes() as $type) {
            $typeLimit = $limit;
            if (self::DEFAULT_LIMIT === $limit) {
                $typeLimit = 'task' === $type ? 75 : self::DEFAULT_LIMIT;
            }

            $todayEntries = array_reverse($this->readLogEntries($type, $todayAffix, $typeLimit));
            $yesterdayEntries = array_reverse($this->readLogEntries($type, $yesterdayAffix, $typeLimit));

            $entries = $todayEntries;

            if (count($todayEntries) > 0 && count($yesterdayEntries) > 0) {
                $entries[] = [
                    'datetime' => '',
                    'level' => '',
                    'logger' => '',
                    'message' => '',
                    'separator' => true,
                ];
            }

            $entries = array_merge($entries, $yesterdayEntries);

            $logs[] = [
                'type' => $type,
                'entries' => $entries,
            ];
        }

        return $logs;
    }

    /**
     * Read last X lines from a log file.
     *
     * @param string $type The log type (app, access, task).
     * @param string $date The date affix.
     * @param int $limit Maximum number of lines.
     *
     * @return array<int,array<string,mixed>>
     */
    private function readLogEntries(string $type, string $date, int $limit): array
    {
        $logFile = LogsCommand::getLogFile($type, $date);

        if (!file_exists($logFile) || filesize($logFile) < 1) {
            return [];
        }

        $file = new SplFileObject($logFile, 'r');
        $file->seek(PHP_INT_MAX);
        $lastLine = $file->key();

        $it = new LimitIterator($file, max(0, $lastLine - $limit), $lastLine);

        $entries = [];
        foreach ($it as $line) {
            $line = trim((string) $line);
            if (empty($line)) {
                continue;
            }

            $entry = LogsCommand::parseJsonlLine($line);

            if (null !== $entry) {
                $entries[] = [
                    'datetime' => ag($entry, 'datetime', ''),
                    'level' => strtoupper((string) ag($entry, 'level', '')),
                    'logger' => ag($entry, 'logger', ''),
                    'message' => ag($entry, 'message', ''),
                ];
            }
        }

        return $entries;
    }

    /**
     * Extract tokens from user configs to redact from the report.
     *
     * @param array<UserContext> $usersContext The user contexts.
     *
     * @return array<int,string>
     */
    private function extractSensitive(array $usersContext): array
    {
        $keys = [
            'token',
            'options.' . Options::ADMIN_TOKEN,
            'options.' . Options::PLEX_USER_PIN,
            'options.' . Options::ADMIN_PLEX_USER_PIN,
        ];

        $sensitive = [];

        foreach ($usersContext as $userContext) {
            foreach ($userContext->config->getAll() as $backend) {
                foreach ($keys as $key) {
                    $val = ag($backend, $key);
                    if (null === $val || true === in_array($val, $sensitive, true)) {
                        continue;
                    }
                    $sensitive[] = $val;
                }
            }
        }

        return $sensitive;
    }

    /**
     * Recursively redact sensitive tokens from all string values in the data.
     *
     * @param array<string,mixed> $data The report data.
     * @param array<int,string> $sensitive The tokens to redact.
     *
     * @return array<string,mixed>
     */
    private function redactSensitive(array $data, array $sensitive): array
    {
        if (count($sensitive) < 1) {
            return $data;
        }

        array_walk_recursive($data, static function (mixed &$value) use ($sensitive): void {
            if (true !== is_string($value)) {
                return;
            }

            foreach ($sensitive as $token) {
                $value = str_ireplace($token, '**HIDDEN**', $value);
            }
        });

        return $data;
    }
}
