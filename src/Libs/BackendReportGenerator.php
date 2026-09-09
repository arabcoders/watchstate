<?php

declare(strict_types=1);

namespace App\Libs;

use App\Libs\Attributes\DI\ForModel;
use App\Libs\Entity\StateEntity;
use App\Libs\Entity\StateInterface as iState;
use App\Models\BackendReport;
use arabcoders\database\Orm\EntityRepository;
use Psr\Log\LoggerInterface as iLogger;
use Throwable;

final class BackendReportGenerator
{
    public const string STATUS_RUNNING = 'running';
    public const string STATUS_COMPLETED = 'completed';
    public const string STATUS_FAILED = 'failed';

    public const string UNKNOWN_LIBRARY = 'unknown';

    private const int VERSION = 1;

    /**
     * @param iLogger $logger Logger instance.
     * @param EntityRepository<BackendReport> $reports Backend report repository.
     */
    public function __construct(
        private readonly iLogger $logger,
        #[ForModel(BackendReport::class)]
        private readonly EntityRepository $reports,
    ) {}

    /**
     * Generate a report for one identity.
     *
     * @param UserContext $context User context.
     *
     * @return array<string,mixed> Generated report metadata.
     */
    public function generate(UserContext $context): array
    {
        $started = microtime(true);
        $startedAt = time();
        $report = $this->createReport($context->name, $startedAt);

        try {
            $summary = $this->emptyCounts()
            + [
                'types' => $this->emptyTypes(),
                'backends' => [],
                'origins' => [],
                'duration_seconds' => 0,
            ];
            $backends = $context->config->getAll();
            $configuredBackends = array_fill_keys(array_keys($backends), true);

            foreach (array_keys($configuredBackends) as $backend) {
                $summary['backends'][$backend] = $this->emptyBackend(true);
            }

            $scanStarted = microtime(true);
            $processed = 0;
            $this->logger->notice(
                "Scanning '{identity.user}' local movie and episode records for backend report '#{report.id}'.",
                [
                    'operation' => 'backend_report.scan',
                    'identity' => ['user' => $context->name],
                    'report' => ['id' => $report->id],
                ],
            );
            $stmt = $context
                ->db
                ->getDBLayer()
                ->query(
                    r("SELECT type, watched, via, metadata FROM \"state\" WHERE type IN ('{movie}', '{episode}')", [
                        'movie' => iState::TYPE_MOVIE,
                        'episode' => iState::TYPE_EPISODE,
                    ]),
                );

            foreach ($stmt as $row) {
                $entity = StateEntity::fromArray($row);
                $type = $entity->type;
                $watched = true === $entity->isWatched();
                $inProgress = false === $watched && true === $entity->hasPlayProgress();
                $processed++;

                $summary = $this->increment($summary, $watched, $inProgress);
                $summary['types'][$type] = $this->increment($summary['types'][$type], $watched, $inProgress);

                if ('' !== $entity->via) {
                    $summary['origins'][$entity->via] ??= $this->emptyCounts()
                    + [
                        'types' => $this->emptyTypes(),
                    ];
                    $summary['origins'][$entity->via] = $this->increment(
                        $summary['origins'][$entity->via],
                        $watched,
                        $inProgress,
                    );
                    $summary['origins'][$entity->via]['types'][$type] = $this->increment(
                        $summary['origins'][$entity->via]['types'][$type],
                        $watched,
                        $inProgress,
                    );
                }

                foreach ($entity->getMetadata() as $backend => $metadata) {
                    if (false === is_array($metadata)) {
                        continue;
                    }

                    $backend = (string) $backend;
                    $library = trim((string) ag($metadata, iState::COLUMN_META_LIBRARY, self::UNKNOWN_LIBRARY));
                    $library = '' === $library ? self::UNKNOWN_LIBRARY : $library;
                    $summary['backends'][$backend] ??= $this->emptyBackend(isset($configuredBackends[$backend]));
                    $summary['backends'][$backend] = $this->increment(
                        $summary['backends'][$backend],
                        $watched,
                        $inProgress,
                    );
                    $summary['backends'][$backend]['types'][$type] = $this->increment(
                        $summary['backends'][$backend]['types'][$type],
                        $watched,
                        $inProgress,
                    );
                    $summary['backends'][$backend]['libraries'][$library] ??= $this->emptyCounts()
                    + [
                        'id' => $library,
                        'title' => $library,
                        'type' => iState::TYPE_EPISODE === $type ? iState::TYPE_SHOW : iState::TYPE_MOVIE,
                    ];
                    $summary['backends'][$backend]['libraries'][$library] = $this->increment(
                        $summary['backends'][$backend]['libraries'][$library],
                        $watched,
                        $inProgress,
                    );
                    $libraryType = iState::TYPE_EPISODE === $type ? iState::TYPE_SHOW : iState::TYPE_MOVIE;
                    if ($libraryType !== $summary['backends'][$backend]['libraries'][$library]['type']) {
                        $summary['backends'][$backend]['libraries'][$library]['type'] = iState::TYPE_MIXED;
                    }
                }

                if (0 === ($processed % 500)) {
                    $this->logger->info(
                        "Processed '{processed}' movie and episode records for '{identity.user}' backend report '#{report.id}'.",
                        [
                            'operation' => 'backend_report.scan',
                            'identity' => ['user' => $context->name],
                            'report' => ['id' => $report->id],
                            'processed' => $processed,
                            'stats' => ['processed' => $processed],
                        ],
                    );
                }
            }

            $scanDuration = round(microtime(true) - $scanStarted, 4);
            $this->logger->notice(
                "Scanned '{processed}' movie and episode records for '{identity.user}' backend report '#{report.id}' in '{duration}'s.",
                [
                    'operation' => 'backend_report.scan',
                    'identity' => ['user' => $context->name],
                    'report' => ['id' => $report->id],
                    'processed' => $processed,
                    'duration' => $scanDuration,
                    'stats' => ['processed' => $processed],
                ],
            );

            $libraryDetails = $this->getLibraryDetails($context, $backends, $summary['backends'], $report->id);
            foreach ($summary['backends'] as $backend => &$backendSummary) {
                foreach ($backendSummary['libraries'] as $library => &$librarySummary) {
                    $librarySummary['title'] = $libraryDetails[$backend][$library]['title'] ?? $library;
                    $librarySummary['type'] = $libraryDetails[$backend][$library]['type'] ?? $librarySummary['type'];
                }
                unset($librarySummary);
                uasort(
                    $backendSummary['libraries'],
                    static fn(array $a, array $b): int => strnatcasecmp((string) $a['title'], (string) $b['title']),
                );
                $backendSummary['libraries'] = array_values($backendSummary['libraries']);
            }
            unset($backendSummary);

            $completedAt = time();
            $summary['duration_seconds'] = round(microtime(true) - $started, 4);
            $report->status = self::STATUS_COMPLETED;
            $report->completed_at = $completedAt;
            $report->backend_count = count($summary['backends']);
            $report->summary = $summary;
            $report->error = null;
            $this->reports->save($report);

            $this->logger->notice(
                "Generated backend report '#{report.id}' for '{identity.user}' with '{backend_count}' backends and '{total}' local records in '{duration}'s.",
                [
                    'operation' => 'backend_report.generate',
                    'report' => ['id' => $report->id],
                    'identity' => ['user' => $context->name],
                    'backend_count' => $report->backend_count,
                    'total' => $summary['total'],
                    'duration' => $summary['duration_seconds'],
                    'stats' => [
                        'total' => $summary['total'],
                        'watched' => $summary['watched'],
                        'unwatched' => $summary['unwatched'],
                        'in_progress' => $summary['in_progress'],
                    ],
                ],
            );

            return ['id' => $report->id, 'status' => $report->status, 'summary' => $summary];
        } catch (Throwable $e) {
            $report->status = self::STATUS_FAILED;
            $report->completed_at = time();
            $report->error = $e->getMessage();
            $this->reports->save($report);
            $duration = round(microtime(true) - $started, 4);
            $this->logger->error(
                "Failed to generate backend report '#{report.id}' for '{identity.user}' in '{duration}'s. {exception.message}",
                [
                    'operation' => 'backend_report.generate',
                    'identity' => ['user' => $context->name],
                    'report' => ['id' => $report->id],
                    'duration' => $duration,
                    ...exception_log($e),
                ],
            );

            throw $e;
        }
    }

    private function createReport(string $identity, int $startedAt): BackendReport
    {
        $report = new BackendReport();
        $report->identity = $identity;
        $report->status = self::STATUS_RUNNING;
        $report->generated_at = $startedAt;
        $report->completed_at = null;
        $report->version = self::VERSION;
        $report->backend_count = 0;
        $report->summary = [];
        $report->error = null;
        $report->id = (int) $this->reports->insert($report);

        return $report;
    }

    /**
     * @param array<string,array<string,mixed>> $backends
     * @param array<string,array<string,mixed>> $summaries
     *
     * @return array<string,array<string,array{title:string,type:string}>>
     */
    private function getLibraryDetails(UserContext $context, array $backends, array $summaries, ?int $reportId): array
    {
        $details = [];

        foreach ($backends as $backend => $config) {
            $referenced = array_map('strval', array_keys((array) ($summaries[$backend]['libraries'] ?? [])));
            if ([] === $referenced) {
                continue;
            }

            $started = microtime(true);
            $this->logger->notice(
                "Resolving '{total}' libraries for '{identity.user}@{identity.backend}' backend report '#{report.id}'.",
                [
                    'operation' => 'backend_report.libraries',
                    'identity' => ['user' => $context->name, 'backend' => (string) $backend],
                    'report' => ['id' => $reportId],
                    'total' => count($referenced),
                    'stats' => ['libraries' => ['referenced' => count($referenced)]],
                ],
            );

            try {
                $libraries = make_backend(
                    backend: $config,
                    name: (string) $backend,
                    options: [UserContext::class => $context],
                )
                    ->setLogger($this->logger)
                    ->listLibraries();

                foreach ($libraries as $library) {
                    $id = trim((string) ag($library, 'id', ''));
                    $title = trim((string) ag($library, 'title', ''));
                    $type = (string) ag($library, 'contentType', '');
                    if ('' === $id || '' === $title || false === in_array($id, $referenced, true)) {
                        continue;
                    }

                    if (false === in_array($type, [iState::TYPE_MOVIE, iState::TYPE_SHOW, iState::TYPE_MIXED], true)) {
                        $type = (string) ($summaries[$backend]['libraries'][$id]['type'] ?? iState::TYPE_MIXED);
                    }

                    $details[(string) $backend][$id] = ['title' => $title, 'type' => $type];
                }

                $resolved = count($details[(string) $backend] ?? []);
                $duration = round(microtime(true) - $started, 4);
                $this->logger->notice(
                    "Resolved '{resolved}/{total}' libraries for '{identity.user}@{identity.backend}' backend report '#{report.id}' in '{duration}'s.",
                    [
                        'operation' => 'backend_report.libraries',
                        'identity' => ['user' => $context->name, 'backend' => (string) $backend],
                        'report' => ['id' => $reportId],
                        'resolved' => $resolved,
                        'total' => count($referenced),
                        'duration' => $duration,
                        'stats' => [
                            'libraries' => ['referenced' => count($referenced), 'resolved' => $resolved],
                        ],
                    ],
                );
            } catch (Throwable $e) {
                $duration = round(microtime(true) - $started, 4);
                $this->logger->warning(
                    "Could not resolve '{total}' libraries for '{identity.user}@{identity.backend}' backend report '#{report.id}' in '{duration}'s. {exception.message}",
                    [
                        'operation' => 'backend_report.libraries',
                        'identity' => ['user' => $context->name, 'backend' => (string) $backend],
                        'report' => ['id' => $reportId],
                        'total' => count($referenced),
                        'duration' => $duration,
                        'stats' => ['libraries' => ['referenced' => count($referenced), 'resolved' => 0]],
                        ...exception_log($e),
                    ],
                );
            }
        }

        return $details;
    }

    /**
     * @return array{total:int,watched:int,unwatched:int,in_progress:int}
     */
    private function emptyCounts(): array
    {
        return ['total' => 0, 'watched' => 0, 'unwatched' => 0, 'in_progress' => 0];
    }

    /**
     * @return array{movie:array{total:int,watched:int,unwatched:int,in_progress:int},episode:array{total:int,watched:int,unwatched:int,in_progress:int}}
     */
    private function emptyTypes(): array
    {
        return [
            iState::TYPE_MOVIE => $this->emptyCounts(),
            iState::TYPE_EPISODE => $this->emptyCounts(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function emptyBackend(bool $configured): array
    {
        return $this->emptyCounts()
        + [
            'configured' => $configured,
            'types' => $this->emptyTypes(),
            'libraries' => [],
        ];
    }

    /**
     * @param array<string,mixed> $counts
     *
     * @return array<string,mixed>
     */
    private function increment(array $counts, bool $watched, bool $inProgress): array
    {
        $counts['total'] = (int) ($counts['total'] ?? 0) + 1;
        $key = true === $watched ? 'watched' : 'unwatched';
        $counts[$key] = (int) ($counts[$key] ?? 0) + 1;
        $counts['in_progress'] = (int) ($counts['in_progress'] ?? 0) + (true === $inProgress ? 1 : 0);

        return $counts;
    }
}
