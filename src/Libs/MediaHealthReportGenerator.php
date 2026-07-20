<?php

declare(strict_types=1);

namespace App\Libs;

use App\Libs\Attributes\DI\ForModel;
use App\Libs\Database\DatabaseInterface as iDB;
use App\Libs\Database\DBLayer;
use App\Libs\Entity\StateInterface as iState;
use App\Libs\Exceptions\RuntimeException;
use App\Models\MediaHealthReport;
use App\Models\MediaHealthReportItem;
use arabcoders\database\Connection;
use arabcoders\database\Orm\EntityRepository;
use arabcoders\database\Query\InsertQuery;
use Psr\Log\LoggerInterface as iLogger;
use Throwable;

final class MediaHealthReportGenerator
{
    public const string TABLE_ITEMS = 'media_health_report_items';

    public const string STATUS_RUNNING = 'running';
    public const string STATUS_COMPLETED = 'completed';
    public const string STATUS_FAILED = 'failed';

    public const string ITEM_HEALTHY = 'healthy';
    public const string ITEM_FILE_MISSING = 'file_missing';
    public const string ITEM_PARTIAL = 'partial';
    public const string ITEM_DUPLICATE_REFERENCE = 'duplicate_reference';
    public const string ITEM_GUID_CONFLICT = 'guid_conflict';
    public const string ITEM_DUPLICATE_GUID = 'duplicate_guid';
    public const string ITEM_METADATA_DISAGREEMENT = 'metadata_disagreement';
    public const string ITEM_PATH_DISAGREEMENT = 'path_disagreement';
    public const string ITEM_WEAK_MATCH = 'weak_match';

    private const int VERSION = 2;
    private const int BATCH_SIZE = 500;

    /**
     * @var array<string,int>
     */
    private const array SEVERITY = [
        self::ITEM_GUID_CONFLICT => 100,
        self::ITEM_FILE_MISSING => 95,
        self::ITEM_DUPLICATE_GUID => 90,
        self::ITEM_DUPLICATE_REFERENCE => 80,
        self::ITEM_METADATA_DISAGREEMENT => 75,
        self::ITEM_PARTIAL => 70,
        self::ITEM_WEAK_MATCH => 50,
        self::ITEM_PATH_DISAGREEMENT => 40,
        self::ITEM_HEALTHY => 0,
    ];

    /**
     * @var array<string,bool>
     */
    private array $dirExists = [];

    /**
     * @var array<string,bool>
     */
    private array $checkedFile = [];

    /**
     * @param iLogger $logger Logger instance.
     * @param EntityRepository<MediaHealthReport> $reports
     * @param EntityRepository<MediaHealthReportItem> $items
     */
    public function __construct(
        private readonly iLogger $logger,
        #[ForModel(MediaHealthReport::class)]
        private readonly EntityRepository $reports,
        #[ForModel(MediaHealthReportItem::class)]
        private readonly EntityRepository $items,
    ) {}

    /**
     * Generate a media health report for the main state database.
     *
     * @param iDB $database Database adapter.
     * @param array<string> $expectedBackends Expected backend names.
     * @param bool $checkFiles Whether to validate local filesystem paths.
     *
     * @return array<string,mixed> Generated report metadata.
     */
    public function generate(iDB $database, array $expectedBackends, bool $checkFiles = false): array
    {
        $db = $database->getDBLayer();
        $startedAt = time();
        $startedAtNs = hrtime(true);
        $report = $this->createReport($this->reports, $startedAt);

        $this->logger->notice('Generating media health report for main state database.', [
            'operation' => 'media_health.generate',
            'report' => ['id' => $report->id],
            'options' => ['check_files' => $checkFiles],
        ]);

        try {
            $this->createReferenceTables($db);
            $this->collectReferences($db, $expectedBackends, $checkFiles);
            $guidStateIds = $this->loadDuplicateReferences($db, 'media_health_guid_refs');
            $pathStateIds = $this->loadDuplicateReferences($db, 'media_health_path_refs');
            $summary = $this->writeItems(
                $this->items->connection(),
                $db,
                (int) $report->id,
                $expectedBackends,
                $checkFiles,
                $guidStateIds,
                $pathStateIds,
            );
            $this->dropReferenceTables($db);

            $completedAt = time();
            $summary['generated_at'] = $startedAt;
            $summary['completed_at'] = $completedAt;
            $summary['duration_seconds'] = round((hrtime(true) - $startedAtNs) / 1_000_000_000, 3);
            $summary['memory_current_bytes'] = memory_get_usage(true);
            $summary['memory_peak_bytes'] = memory_get_peak_usage(true);
            $summary['memory_peak_mb'] = round($summary['memory_peak_bytes'] / 1_048_576, 2);

            $report->status = self::STATUS_COMPLETED;
            $report->completed_at = $completedAt;
            $report->state_count = (int) $summary['total'];
            $report->backend_count = count($expectedBackends);
            $report->summary = $summary;
            $report->error = null;
            $this->reports->save($report);

            $this->logger->notice(
                "Generated media health report. Scanned '{stats.total}' records: '{stats.actionable}' actionable records.",
                [
                    'operation' => 'media_health.generate',
                    'report' => ['id' => $report->id],
                    'stats' => [
                        'total' => (int) $summary['total'],
                        'actionable' => (int) $summary['actionable_count'],
                        'statuses' => $summary['statuses'],
                        'duration_seconds' => $summary['duration_seconds'],
                        'memory_peak_bytes' => $summary['memory_peak_bytes'],
                    ],
                ],
            );

            return [
                'id' => $report->id,
                'status' => self::STATUS_COMPLETED,
                'summary' => $summary,
            ];
        } catch (Throwable $e) {
            $report->status = self::STATUS_FAILED;
            $report->completed_at = time();
            $report->error = $e->getMessage();
            $this->reports->save($report);

            $this->logger->error('Failed to generate media health report. {exception.message}', [
                'operation' => 'media_health.generate',
                'report' => ['id' => $report->id],
                ...exception_log($e),
            ]);

            throw $e;
        } finally {
            $this->dropReferenceTables($db);
            $this->dirExists = [];
            $this->checkedFile = [];
        }
    }

    /**
     * @param EntityRepository<MediaHealthReport> $reports
     */
    private function createReport(EntityRepository $reports, int $startedAt): MediaHealthReport
    {
        $report = new MediaHealthReport();
        $report->status = self::STATUS_RUNNING;
        $report->generated_at = $startedAt;
        $report->completed_at = null;
        $report->version = self::VERSION;
        $report->state_count = 0;
        $report->backend_count = 0;
        $report->summary = [];
        $report->error = null;

        $id = (int) $reports->insert($report);
        if ($id < 1 || null === $report->id) {
            throw new RuntimeException('Failed to create media health report row.');
        }

        return $report;
    }

    /**
     * @param array<string> $expectedBackends
     * @param bool $checkFiles Whether to validate local filesystem paths.
     *
     * @return \Generator<int,array<string,mixed>>
     */
    private function loadFacts(DBLayer $db, array $expectedBackends, bool $checkFiles): \Generator
    {
        $lastId = 0;
        do {
            $stmt = $db->query(
                'SELECT id, type, title, year, season, episode, updated_at, guids, parent, metadata FROM "state" WHERE id > :last_id ORDER BY id ASC LIMIT 500',
                ['last_id' => $lastId],
            );
            $rows = 0;
            foreach ($stmt as $row) {
                $rows++;
                $lastId = (int) $row['id'];
                $metadata = $this->decode($row['metadata'] ?? null);
                $guids = $this->decode($row['guids'] ?? null);
                $parent = $this->decode($row['parent'] ?? null);
                $backendNames = array_values(array_filter(
                    array_keys($metadata),
                    static fn(string $backend): bool => is_array($metadata[$backend] ?? null),
                ));

                $paths = [];
                $suffixes = [];
                $metadataGuids = [];
                $backendItems = [];
                $fileChecks = [];
                foreach ($backendNames as $backend) {
                    $backendMetadata = $metadata[$backend];
                    $backendType = (string) ag($backendMetadata, iState::COLUMN_TYPE, $row['type']);
                    $backendTitle = iState::TYPE_EPISODE === $backendType
                        ? ag(
                            $backendMetadata,
                            iState::COLUMN_META_DATA_EXTRA . '.' . iState::COLUMN_META_DATA_EXTRA_TITLE,
                            ag($backendMetadata, iState::COLUMN_TITLE, $row['title']),
                        )
                        : ag($backendMetadata, iState::COLUMN_TITLE, $row['title']);
                    $backendItems[$backend] = [
                        'id' => (string) ag($backendMetadata, iState::COLUMN_ID, ''),
                        'type' => $backendType,
                        'title' => (string) $backendTitle,
                        'year' => null !== ag($backendMetadata, iState::COLUMN_YEAR)
                            ? (int) ag($backendMetadata, iState::COLUMN_YEAR)
                            : null,
                        'season' => null !== ag($backendMetadata, iState::COLUMN_SEASON)
                            ? (int) ag($backendMetadata, iState::COLUMN_SEASON)
                            : null,
                        'episode' => null !== ag($backendMetadata, iState::COLUMN_EPISODE)
                            ? (int) ag($backendMetadata, iState::COLUMN_EPISODE)
                            : null,
                        'path' => (string) ag($backendMetadata, iState::COLUMN_META_PATH, ''),
                    ];
                    if (null !== ($path = ag($backendMetadata, iState::COLUMN_META_PATH))) {
                        $path = (string) $path;
                        if ($checkFiles) {
                            $fileChecks[$backend] = $this->fileCheck($backend, $path);
                        }
                        if ('' !== $path && false === (bool) ag($backendMetadata, iState::COLUMN_META_MULTI, false)) {
                            $paths[$backend] = $path;
                            if (null !== ($suffix = $this->pathSuffix($path, (string) $row['type']))) {
                                $suffixes[$backend] = $suffix;
                            }
                        }
                    }

                    $metadataGuids[$backend] = $this->extractGuidValues((array) ag($backendMetadata, iState::COLUMN_GUIDS, []));
                }

                $fact = [
                    'id' => (int) $row['id'],
                    'type' => (string) $row['type'],
                    'title' => (string) $row['title'],
                    'year' => null !== $row['year'] ? (int) $row['year'] : null,
                    'season' => null !== $row['season'] ? (int) $row['season'] : null,
                    'episode' => null !== $row['episode'] ? (int) $row['episode'] : null,
                    'updated_at' => (int) ($row['updated_at'] ?? 0),
                    'guids' => $this->extractGuidValues($guids),
                    'parent' => $this->extractGuidValues($parent),
                    'metadata_guids' => $metadataGuids,
                    'backend_items' => $backendItems,
                    'backends' => $backendNames,
                    'missing_backends' => array_values(array_diff($expectedBackends, $backendNames)),
                    'backend_count' => count($backendNames),
                    'expected_backend_count' => count($expectedBackends),
                    'paths' => $paths,
                    'path_suffixes' => $suffixes,
                    'file_checks' => $fileChecks,
                ];
                yield $fact;
            }
            $stmt = null;
        } while ($rows > 0);
    }

    private function createReferenceTables(DBLayer $db): void
    {
        $db->exec(
            'CREATE TEMP TABLE media_health_guid_refs (lookup TEXT NOT NULL, state_id INTEGER NOT NULL, PRIMARY KEY (lookup, state_id))',
        );
        $db->exec(
            'CREATE TEMP TABLE media_health_path_refs (lookup TEXT NOT NULL, state_id INTEGER NOT NULL, PRIMARY KEY (lookup, state_id))',
        );
        $db->exec('CREATE INDEX media_health_guid_refs_lookup ON media_health_guid_refs (lookup)');
        $db->exec('CREATE INDEX media_health_path_refs_lookup ON media_health_path_refs (lookup)');
    }

    private function collectReferences(DBLayer $db, array $expectedBackends, bool $checkFiles): void
    {
        $db->transactional(function () use ($db, $expectedBackends, $checkFiles): void {
            $guidInsert = $db->prepare('INSERT OR IGNORE INTO media_health_guid_refs (lookup, state_id) VALUES (:lookup, :state_id)');
            $pathInsert = $db->prepare('INSERT OR IGNORE INTO media_health_path_refs (lookup, state_id) VALUES (:lookup, :state_id)');

            foreach ($this->loadFacts($db, $expectedBackends, $checkFiles) as $fact) {
                foreach ((array) $fact['guids'] as $key => $value) {
                    if (Guid::GUID_PATH === $key || '' === (string) $value) {
                        continue;
                    }

                    $guidInsert->execute([
                        'lookup' => $fact['type'] . ':' . $key . ':' . $value,
                        'state_id' => $fact['id'],
                    ]);
                }

                foreach (array_unique((array) $fact['paths']) as $path) {
                    $pathInsert->execute([
                        'lookup' => $path,
                        'state_id' => $fact['id'],
                    ]);
                }
            }
        });
    }

    /**
     * @return array<string,array<int>>
     */
    private function loadDuplicateReferences(DBLayer $db, string $table): array
    {
        $stmt = $db->query(
            "SELECT refs.lookup, refs.state_id FROM {$table} refs INNER JOIN (SELECT lookup FROM {$table} GROUP BY lookup HAVING COUNT(*) > 1) duplicates ON duplicates.lookup = refs.lookup ORDER BY refs.lookup, refs.state_id",
        );
        $references = [];
        foreach ($stmt as $row) {
            $references[(string) $row['lookup']][] = (int) $row['state_id'];
        }

        return $references;
    }

    private function dropReferenceTables(DBLayer $db): void
    {
        $db->exec('DROP TABLE IF EXISTS media_health_guid_refs');
        $db->exec('DROP TABLE IF EXISTS media_health_path_refs');
    }

    /**
     * @param Connection $connection Report item database connection.
     * @param array<string> $expectedBackends
     * @param array<string,array<int>> $guidStateIds
     * @param array<string,array<int>> $pathStateIds
     *
     * @return array<string,mixed>
     */
    private function writeItems(
        Connection $connection,
        DBLayer $db,
        int $reportId,
        array $expectedBackends,
        bool $checkFiles,
        array $guidStateIds,
        array $pathStateIds,
    ): array {
        return $connection->transaction(function () use (
            $connection,
            $db,
            $reportId,
            $expectedBackends,
            $checkFiles,
            $guidStateIds,
            $pathStateIds,
        ): array {
            $summary = [
                'total' => 0,
                'actionable_count' => 0,
                'statuses' => [],
            ];

            $batch = [];
            foreach ($this->loadFacts($db, $expectedBackends, $checkFiles) as $fact) {
                $summary['total']++;
                $item = $this->score($fact, $guidStateIds, $pathStateIds);
                $status = $item['status'];
                $summary['statuses'][$status] = ($summary['statuses'][$status] ?? 0) + 1;
                if (self::ITEM_HEALTHY !== $status) {
                    $summary['actionable_count']++;
                }

                $batch[] = [
                    'report_id' => $reportId,
                    'state_id' => (int) $fact['id'],
                    'type' => (string) $fact['type'],
                    'title' => (string) $fact['title'],
                    'year' => null !== $fact['year'] ? (int) $fact['year'] : null,
                    'season' => null !== $fact['season'] ? (int) $fact['season'] : null,
                    'episode' => null !== $fact['episode'] ? (int) $fact['episode'] : null,
                    'status' => $status,
                    'severity' => (int) $item['severity'],
                    'confidence' => (int) $item['confidence'],
                    'backend_count' => (int) $fact['backend_count'],
                    'expected_backend_count' => (int) $fact['expected_backend_count'],
                    'reasons' => json_encode($item['reasons'], JSON_UNESCAPED_SLASHES),
                    'signals' => json_encode($item['signals'], JSON_UNESCAPED_SLASHES),
                ];

                if (count($batch) >= self::BATCH_SIZE) {
                    $connection->execute(new InsertQuery(MediaHealthReportGenerator::TABLE_ITEMS)->rows($batch));
                    $batch = [];
                }
            }

            if ([] !== $batch) {
                $connection->execute(new InsertQuery(MediaHealthReportGenerator::TABLE_ITEMS)->rows($batch));
            }

            ksort($summary['statuses']);

            return $summary;
        });
    }

    /**
     * @param array<string,mixed> $fact
     * @param array<string,array<int>> $guidStateIds
     * @param array<string,array<int>> $pathStateIds
     *
     * @return array{status:string,severity:int,confidence:int,reasons:array<string>,signals:array<string,mixed>}
     */
    private function score(array $fact, array $guidStateIds, array $pathStateIds): array
    {
        $reasons = [];
        $signals = [
            'backends' => $fact['backends'],
            'backend_items' => $fact['backend_items'],
            'missing_backends' => $fact['missing_backends'],
            'paths' => $fact['paths'],
            'path_suffixes' => $fact['path_suffixes'],
            'file_checks' => $fact['file_checks'],
            'guids' => $fact['guids'],
        ];
        $metadataConflicts = $this->metadataConflicts((array) $fact['backend_items']);
        if ([] !== $metadataConflicts) {
            $signals['metadata_conflicts'] = $metadataConflicts;
        }

        $missingFiles = array_values(array_filter(
            (array) $fact['file_checks'],
            static fn(array $check): bool => false === (bool) ($check['status'] ?? true),
        ));
        if ([] !== $missingFiles) {
            foreach ($missingFiles as $check) {
                $reasons[] = r('{backend}: {message} {path}', [
                    'backend' => $check['backend'],
                    'message' => $check['message'],
                    'path' => $check['path'],
                ]);
            }

            return $this->result(self::ITEM_FILE_MISSING, 5, $reasons, [
                ...$signals,
                'missing_files' => $missingFiles,
            ]);
        }

        $guidConflicts = $this->guidConflicts((array) $fact['metadata_guids']);
        if ([] !== $guidConflicts) {
            foreach ($guidConflicts as $key => $values) {
                $reasons[] = r("Backends disagree on '{key}' values '{values}'.", [
                    'key' => $key,
                    'values' => implode(', ', array_keys($values)),
                ]);
            }
            return $this->result(self::ITEM_GUID_CONFLICT, 0, $reasons, [...$signals, 'guid_conflicts' => $guidConflicts]);
        }

        foreach ((array) $fact['guids'] as $key => $value) {
            if (Guid::GUID_PATH === $key || '' === (string) $value) {
                continue;
            }
            $lookup = $fact['type'] . ':' . $key . ':' . $value;
            $matchingIds = $guidStateIds[$lookup] ?? [];
            if (count($matchingIds) > 1) {
                $reasons[] = r("Strong GUID '{key}://{value}' is used by '{count}' state records.", [
                    'key' => $key,
                    'value' => $value,
                    'count' => count($matchingIds),
                ]);
                return $this->result(self::ITEM_DUPLICATE_GUID, 10, $reasons, [
                    ...$signals,
                    'duplicate_guid' => [
                        'key' => $key,
                        'value' => $value,
                        'state_ids' => $matchingIds,
                    ],
                ]);
            }
        }

        foreach ((array) $fact['paths'] as $path) {
            $matchingIds = $pathStateIds[$path] ?? [];
            if (count($matchingIds) <= 1) {
                continue;
            }

            $reasons[] = r("Media path '{path}' is referenced by '{count}' state records.", [
                'path' => $path,
                'count' => count($matchingIds),
            ]);
            return $this->result(self::ITEM_DUPLICATE_REFERENCE, 20, $reasons, [
                ...$signals,
                'duplicate_reference' => [
                    'path' => $path,
                    'state_ids' => $matchingIds,
                ],
            ]);
        }

        if ([] !== $metadataConflicts) {
            foreach ($metadataConflicts as $field => $values) {
                $reasons[] = r("Backends disagree on '{field}' values: {values}.", [
                    'field' => $field,
                    'values' => implode(', ', array_keys($values)),
                ]);
            }

            return $this->result(self::ITEM_METADATA_DISAGREEMENT, 25, $reasons, $signals);
        }

        if (count((array) $fact['missing_backends']) > 0) {
            $reasons[] = r("Missing metadata from '{count}' expected backends: {backends}.", [
                'count' => count((array) $fact['missing_backends']),
                'backends' => implode(', ', (array) $fact['missing_backends']),
            ]);
            return $this->result(self::ITEM_PARTIAL, 30, $reasons, $signals);
        }

        $strongGuids = array_diff_key((array) $fact['guids'], [Guid::GUID_PATH => true]);
        if ([] === $strongGuids) {
            $reasons[] = 'No strong external GUIDs were stored for this record.';
            return $this->result(self::ITEM_WEAK_MATCH, 45, $reasons, $signals);
        }

        $suffixes = array_unique((array) $fact['path_suffixes']);
        if (count($suffixes) > 1) {
            $reasons[] = 'Backend media paths do not share the same normalized suffix.';
            return $this->result(self::ITEM_PATH_DISAGREEMENT, 70, $reasons, $signals);
        }

        $reasons[] = 'No media health issues were detected.';
        return $this->result(self::ITEM_HEALTHY, 100, $reasons, $signals);
    }

    /**
     * @param array<string,array<string,string>> $metadataGuids
     *
     * @return array<string,array<string,array<string>>>
     */
    private function guidConflicts(array $metadataGuids): array
    {
        $values = [];
        foreach ($metadataGuids as $backend => $guids) {
            foreach ($guids as $key => $value) {
                if (Guid::GUID_PATH === $key || '' === (string) $value) {
                    continue;
                }
                $values[$key][(string) $value][] = $backend;
            }
        }

        return array_filter($values, static fn(array $items): bool => count($items) > 1);
    }

    /**
     * @param array<string,array<string,mixed>> $backendItems
     *
     * @return array<string,array<string,array<string>>>
     */
    private function metadataConflicts(array $backendItems): array
    {
        $fields = [
            iState::COLUMN_TYPE,
            iState::COLUMN_YEAR,
            iState::COLUMN_SEASON,
            iState::COLUMN_EPISODE,
        ];
        $values = [];

        foreach ($fields as $field) {
            $normalized = [];
            foreach ($backendItems as $backend => $item) {
                $value = $item[$field] ?? null;
                if (null === $value || '' === trim((string) $value)) {
                    continue;
                }

                $display = trim((string) $value);
                $key = mb_strtolower($display);
                $normalized[$key]['display'] ??= $display;
                $normalized[$key]['backends'][] = $backend;
            }

            if (count($normalized) < 2) {
                continue;
            }

            foreach ($normalized as $value) {
                $values[$field][(string) $value['display']] = $value['backends'];
            }
        }

        return $values;
    }

    /**
     * @param array<string> $reasons
     * @param array<string,mixed> $signals
     *
     * @return array{status:string,severity:int,confidence:int,reasons:array<string>,signals:array<string,mixed>}
     */
    private function result(string $status, int $confidence, array $reasons, array $signals): array
    {
        return [
            'status' => $status,
            'severity' => self::SEVERITY[$status] ?? 0,
            'confidence' => $confidence,
            'reasons' => $reasons,
            'signals' => $signals,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function decode(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (!is_string($value) || '' === $value) {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<mixed> $payload
     *
     * @return array<string,string>
     */
    private function extractGuidValues(array $payload): array
    {
        $guids = [];
        foreach ($payload as $key => $value) {
            if (is_string($key) && str_starts_with($key, 'guid_') && (is_string($value) || is_int($value))) {
                $guids[$key] = (string) $value;
                continue;
            }

            if (!is_string($value) || false === str_contains($value, '://')) {
                continue;
            }

            [$scheme, $id] = explode('://', $value, 2);
            $guidKey = str_starts_with($scheme, 'guid_') ? $scheme : 'guid_' . $scheme;
            if ('' !== $id) {
                $guids[$guidKey] = $id;
            }
        }

        return $guids;
    }

    private function pathSuffix(string $path, string $type): ?string
    {
        $path = trim(str_replace('\\', '/', $path));
        if ('' === $path || null === ($path = preg_replace('#/+#', '/', $path))) {
            return null;
        }

        $parts = array_values(array_filter(explode('/', mb_strtolower(rtrim($path, '/')))));
        if ([] === $parts) {
            return null;
        }

        $length = iState::TYPE_EPISODE === $type ? 3 : 2;

        return '/' . implode('/', array_slice($parts, -$length));
    }

    /**
     * @return array{backend:string,path:string,status:bool,message:string}
     */
    private function fileCheck(string $backend, string $path): array
    {
        $check = [
            'backend' => $backend,
            'path' => $path,
            'status' => true,
            'message' => '',
        ];

        if ('' === $path) {
            $check['status'] = false;
            $check['message'] = 'File path is empty.';

            return $check;
        }

        $dirName = dirname($path);
        if (false === $this->checkPath($dirName)) {
            $check['status'] = false;
            $check['message'] = 'File parent directory does not exist.';

            return $check;
        }

        $check['message'] = 'File parent directory exists.';

        if (false === $this->checkFile($path)) {
            $check['status'] = false;
            $check['message'] = 'File does not exist.';

            return $check;
        }

        $check['message'] = 'File exists.';

        return $check;
    }

    private function checkPath(string $file): bool
    {
        $dirs = explode(DIRECTORY_SEPARATOR, $file);
        foreach ($dirs as $i => $dir) {
            $path = implode(DIRECTORY_SEPARATOR, array_slice($dirs, 0, $i + 1));
            if ('' === $path || '' === $dir) {
                continue;
            }
            if (false === $this->dirExists($path)) {
                return false;
            }
        }

        return true;
    }

    private function dirExists(string $dir): bool
    {
        if (array_key_exists($dir, $this->dirExists)) {
            return $this->dirExists[$dir];
        }

        $this->dirExists[$dir] = is_dir($dir);

        return $this->dirExists[$dir];
    }

    private function checkFile(string $file): bool
    {
        if (array_key_exists($file, $this->checkedFile)) {
            return $this->checkedFile[$file];
        }

        $this->checkedFile[$file] = file_exists($file);

        return $this->checkedFile[$file];
    }
}
