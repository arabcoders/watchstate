<?php

declare(strict_types=1);

namespace App\API\State;

use App\Commands\State\MediaHealthCommand;
use App\Commands\System\TasksCommand;
use App\Libs\Attributes\DI\ForModel;
use App\Libs\Attributes\Route\Get;
use App\Libs\Attributes\Route\Post;
use App\Libs\Config;
use App\Libs\Database\DBLayer;
use App\Libs\DataUtil;
use App\Libs\Enums\Http\Status;
use App\Libs\Exceptions\RuntimeException;
use App\Libs\MediaHealthReportGenerator;
use App\Libs\Stream;
use App\Libs\Traits\APITraits;
use App\Model\Events\Event;
use App\Model\Events\EventsRepository;
use App\Model\Events\EventsTable;
use App\Model\Events\EventStatus;
use App\Models\MediaHealthReport as ReportModel;
use App\Models\MediaHealthReportItem as ReportItemModel;
use arabcoders\database\Orm\EntityRepository;
use arabcoders\database\Query\Condition;
use arabcoders\database\Query\SelectQuery;
use Psr\Http\Message\ResponseInterface as iResponse;
use Psr\Http\Message\ServerRequestInterface as iRequest;
use Psr\Http\Message\StreamInterface as iStream;
use Throwable;

final class MediaHealth
{
    use APITraits;

    public const string URL = '%{api.prefix}/state/media-health';

    /**
     * @var EntityRepository<ReportModel> $reports
     * @var EntityRepository<ReportItemModel> $items
     */
    public function __construct(
        private readonly EventsRepository $eventsRepo,
        #[ForModel(ReportModel::class)]
        private readonly EntityRepository $reports,
        #[ForModel(ReportItemModel::class)]
        private readonly EntityRepository $items,
    ) {}

    #[Get(self::URL . '[/]', name: 'state.media_health')]
    public function index(): iResponse
    {
        $report = $this->latestReport($this->reports);
        $queued = $this->queuedTask();

        if (null === $report) {
            return api_response(Status::OK, [
                'report' => null,
                'queued' => null !== $queued,
                'queued_event' => null !== $queued ? $queued->id : null,
                'stale' => false,
            ]);
        }

        return api_response(Status::OK, [
            'report' => $this->formatReport($report),
            'queued' => null !== $queued,
            'queued_event' => null !== $queued ? $queued->id : null,
            'stale' => $this->isStale($report),
        ]);
    }

    #[Get(self::URL . '/items[/]', name: 'state.media_health.items')]
    public function items(iRequest $request): iResponse
    {
        if (null === ($report = $this->latestReport($this->reports)) || null === $report->id) {
            return api_error('No completed media health audit found.', Status::NOT_FOUND);
        }

        $params = DataUtil::fromArray($request->getQueryParams());
        $page = max(1, (int) $params->get('page', 1));
        $perpage = min(500, max(1, (int) $params->get('perpage', 50)));
        $conditions = [Condition::equals('report_id', $report->id)];

        if (null !== ($status = $params->get('status')) && '' !== (string) $status) {
            $conditions[] = Condition::equals('status', (string) $status);
        }

        if ('1' === (string) $params->get('unhealthy', '0')) {
            $conditions[] = Condition::notEquals('status', MediaHealthReportGenerator::ITEM_HEALTHY);
        }

        if (null !== ($type = $params->get('type')) && '' !== (string) $type) {
            $conditions[] = Condition::equals('type', (string) $type);
        }

        $items = $this->items->findPageWhere(
            Condition::and(...$conditions),
            page: $page,
            perPage: $perpage,
            orderBy: ['severity' => 'DESC', 'state_id' => 'ASC'],
        );

        $total = (int) $items['total'];
        $lastPage = $total > 0 ? (int) ceil($total / $perpage) : 1;

        return api_response(Status::OK, [
            'report' => $this->formatReport($report),
            'items' => array_map($this->formatItem(...), $items['items']),
            'paging' => [
                'total' => $total,
                'perpage' => $perpage,
                'current_page' => (int) $items['page'],
                'first_page' => 1,
                'next_page' => $page < $lastPage ? $page + 1 : null,
                'prev_page' => $page > 1 ? $page - 1 : null,
                'last_page' => $lastPage,
                'params' => [
                    'status' => $params->get('status'),
                    'unhealthy' => $params->get('unhealthy'),
                    'type' => $params->get('type'),
                ],
            ],
        ]);
    }

    #[Post(self::URL . '/run[/]', name: 'state.media_health.run')]
    public function run(): iResponse
    {
        if (null !== ($queued = $this->queuedTask())) {
            return api_response(Status::ACCEPTED, [
                'queued' => false,
                'running' => EventStatus::RUNNING === $queued->status,
                'event_id' => $queued->id,
                'message' => 'Media health audit is already queued or running.',
            ]);
        }

        $event = queue_event(
            TasksCommand::NAME,
            ['name' => MediaHealthCommand::TASK_NAME],
            [
                EventsTable::COLUMN_REFERENCE => r('task://{name}', ['name' => MediaHealthCommand::TASK_NAME]),
            ],
        );

        return api_response(Status::ACCEPTED, [
            'queued' => true,
            'running' => false,
            'event_id' => $event->id,
            'message' => 'Media health audit was queued.',
        ]);
    }

    /**
     * Download the latest completed media health report.
     *
     * @param string $format Export format.
     */
    #[Get(self::URL . '/export/{format:json|markdown|csv}[/]', name: 'state.media_health.export')]
    public function export(string $format): iResponse
    {
        $report = $this->latestReport($this->reports);
        if (null === $report || null === $report->id) {
            return api_error('No completed media health audit found.', Status::NOT_FOUND);
        }

        $isMarkdown = 'markdown' === $format;
        $isCsv = 'csv' === $format;
        $extension = match ($format) {
            'markdown' => '.md',
            'csv' => '.csv',
            'json' => '.json',
            default => '.json',
        };
        $reportName = 'media-health-' . (int) $report->id . '-' . $format . $extension;
        $exportDir = (string) Config::get('media_health.path');

        $archivePath = $exportDir . '/' . $reportName . '.zip';
        if (file_exists($archivePath) && is_file($archivePath)) {
            return $this->exportResponse(Stream::make($archivePath, 'rb'), $reportName);
        }

        $buildDir = $exportDir . '/build-' . bin2hex(random_bytes(8));
        if (false === mkdir($buildDir, 0o700)) {
            $error = error_get_last();
            throw new RuntimeException(r("Failed to create media health export build directory '{path}'. {error}", [
                'path' => $buildDir,
                'error' => (string) ($error['message'] ?? 'Unknown filesystem error.'),
            ]));
        }

        $reportPath = $buildDir . '/' . $reportName;
        $buildArchivePath = $reportPath . '.zip';
        $reportStream = Stream::make($reportPath, 'w+b');

        try {
            $this->writeExport($reportStream, $report, $isMarkdown, $isCsv);
            $reportStream->close();
            compress_files($buildArchivePath, [$reportPath]);
            if (file_exists($reportPath) && is_file($reportPath)) {
                @unlink($reportPath);
            }
            if (false === rename($buildArchivePath, $archivePath)) {
                $error = error_get_last();
                throw new RuntimeException(r("Failed to move media health archive from '{source}' to '{target}'. {error}", [
                    'source' => $buildArchivePath,
                    'target' => $archivePath,
                    'error' => (string) ($error['message'] ?? 'Unknown filesystem error.'),
                ]));
            }
            if (is_dir($buildDir)) {
                @rmdir($buildDir);
            }
        } catch (Throwable $e) {
            $reportStream->close();
            if (file_exists($reportPath) && is_file($reportPath)) {
                @unlink($reportPath);
            }
            if (file_exists($buildArchivePath) && is_file($buildArchivePath)) {
                @unlink($buildArchivePath);
            }
            if (is_dir($buildDir)) {
                @rmdir($buildDir);
            }
            throw $e;
        }

        return $this->exportResponse(Stream::make($archivePath, 'rb'), $reportName);
    }

    private function exportResponse(iStream $stream, string $reportName): iResponse
    {
        return api_response(Status::OK, body: $stream, headers: [
            'Content-Type' => 'application/zip',
            'Content-Disposition' => r('attachment; filename="{filename}"', [
                'filename' => $reportName . '.zip',
            ]),
        ]);
    }

    /**
     * @param EntityRepository<ReportModel> $reports
     */
    private function latestReport(EntityRepository $reports): ?ReportModel
    {
        $report = $reports->findOneBy(
            [
                'status' => MediaHealthReportGenerator::STATUS_COMPLETED,
            ],
            orderBy: ['completed_at' => 'DESC', 'id' => 'DESC'],
        );

        return $report ?? null;
    }

    private function queuedTask(): ?Event
    {
        $reference = r('task://{name}', ['name' => MediaHealthCommand::TASK_NAME]);
        $items = (clone $this->eventsRepo)
            ->setPerpage(1)
            ->setStart(0)
            ->setDescendingOrder()
            ->setSort(EventsTable::COLUMN_CREATED_AT)
            ->findAll([
                EventsTable::COLUMN_REFERENCE => $reference,
                EventsTable::COLUMN_STATUS => [
                    DBLayer::IS_IN,
                    [EventStatus::PENDING->value, EventStatus::RUNNING->value],
                ],
            ]);

        return $items[0] ?? null;
    }

    private function isStale(ReportModel $report): bool
    {
        $row = $this->reports
            ->connection()
            ->fetchOne(
                new SelectQuery('state')->select([])->selectRaw('MAX(updated_at) AS updated_at'),
            );

        return (int) ag($row, 'updated_at', 0) > (int) ($report->completed_at ?? 0);
    }

    /**
     * @return array<string,mixed>
     */
    private function formatReport(ReportModel $report): array
    {
        return [
            'id' => (int) $report->id,
            'status' => $report->status,
            'generated_at' => $report->generated_at,
            'completed_at' => $report->completed_at,
            'version' => $report->version,
            'state_count' => $report->state_count,
            'backend_count' => $report->backend_count,
            'summary' => $report->summary,
            'error' => $report->error,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function formatItem(ReportItemModel $item): array
    {
        $signals = $this->formatSignals($item->signals);

        return [
            'id' => (int) $item->id,
            'report_id' => $item->report_id,
            'state_id' => $item->state_id,
            'type' => $item->type,
            'title' => $item->title,
            'year' => $item->year,
            'season' => $item->season,
            'episode' => $item->episode,
            'status' => $item->status,
            'severity' => $item->severity,
            'confidence' => $item->confidence,
            'backend_count' => $item->backend_count,
            'expected_backend_count' => $item->expected_backend_count,
            'reasons' => $item->reasons,
            'signals' => $signals,
        ];
    }

    /**
     * @param array<string,mixed> $row
     *
     * @return array<string,mixed>
     */
    private function formatItemRow(array $row): array
    {
        $signals = $this->formatSignals($this->decode($row['signals'] ?? null));

        return [
            'id' => (int) ($row['id'] ?? 0),
            'report_id' => (int) ($row['report_id'] ?? 0),
            'state_id' => (int) ($row['state_id'] ?? 0),
            'type' => (string) ($row['type'] ?? ''),
            'title' => (string) ($row['title'] ?? ''),
            'year' => null !== ($row['year'] ?? null) ? (int) $row['year'] : null,
            'season' => null !== ($row['season'] ?? null) ? (int) $row['season'] : null,
            'episode' => null !== ($row['episode'] ?? null) ? (int) $row['episode'] : null,
            'status' => (string) ($row['status'] ?? ''),
            'severity' => (int) ($row['severity'] ?? 0),
            'confidence' => (int) ($row['confidence'] ?? 0),
            'backend_count' => (int) ($row['backend_count'] ?? 0),
            'expected_backend_count' => (int) ($row['expected_backend_count'] ?? 0),
            'reasons' => $this->decode($row['reasons'] ?? null),
            'signals' => $signals,
        ];
    }

    private function writeExport(iStream $stream, ReportModel $report, bool $isMarkdown, bool $isCsv): void
    {
        $reportData = $this->formatReport($report);
        $query = $this->items
            ->select()
            ->where(Condition::equals('report_id', $report->id))
            ->orderBy('state_id', 'ASC');
        $rows = $this->items->connection()->cursor($query);

        if ($isMarkdown) {
            $this->writeMarkdownHeader($stream, $reportData);
        } elseif ($isCsv) {
            $stream->write($this->csvRow([
                'id',
                'report_id',
                'state_id',
                'type',
                'title',
                'year',
                'season',
                'episode',
                'status',
                'severity',
                'confidence',
                'backend_count',
                'expected_backend_count',
                'reasons',
                'signals',
            ]));
        } else {
            $stream->write('{"report":');
            $stream->write((string) json_encode($reportData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            $stream->write(',"items":[');
        }

        $first = true;
        foreach ($rows as $row) {
            $item = $this->formatItemRow($row);
            if ($isMarkdown) {
                $this->writeMarkdownItem($stream, $item);
                continue;
            }

            if ($isCsv) {
                $stream->write($this->csvRow([
                    $item['id'],
                    $item['report_id'],
                    $item['state_id'],
                    $item['type'],
                    $item['title'],
                    $item['year'],
                    $item['season'],
                    $item['episode'],
                    $item['status'],
                    $item['severity'],
                    $item['confidence'],
                    $item['backend_count'],
                    $item['expected_backend_count'],
                    json_encode($item['reasons'], JSON_UNESCAPED_SLASHES),
                    json_encode($item['signals'], JSON_UNESCAPED_SLASHES),
                ]));
                continue;
            }

            if (false === $first) {
                $stream->write(',');
            }
            $stream->write((string) json_encode($item, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            $first = false;
        }

        $stream->write($isMarkdown || $isCsv ? "\n" : ']}');
    }

    /**
     * @param array<mixed> $values
     */
    private function csvRow(array $values): string
    {
        return (
            implode(',', array_map(
                static fn(mixed $value): string => '"' . str_replace('"', '""', (string) ($value ?? '')) . '"',
                $values,
            )) . "\r\n"
        );
    }

    /**
     * @param array<string,mixed> $report
     */
    private function writeMarkdownHeader(iStream $stream, array $report): void
    {
        $summary = (array) ($report['summary'] ?? []);
        $stream->write("# Media Health Report\n\n");
        $stream->write('- Report: `' . ($report['id'] ?? '') . "`\n");
        $stream->write('- Generated: `' . date(DATE_ATOM, (int) ($report['completed_at'] ?? $report['generated_at'] ?? 0)) . "`\n");
        $stream->write('- Records: `' . ($report['state_count'] ?? 0) . "`\n");
        $stream->write('- Actionable: `' . ($summary['actionable_count'] ?? 0) . "`\n\n");
        $stream->write("## Status Summary\n\n");
        foreach ((array) ($summary['statuses'] ?? []) as $status => $count) {
            $stream->write('- `' . $status . '`: ' . $count . "\n");
        }
        $stream->write("\n## Items\n\n");
    }

    /**
     * @param array<string,mixed> $item
     */
    private function writeMarkdownItem(iStream $stream, array $item): void
    {
        $stream->write('### #' . $item['state_id'] . ' ' . $item['title'] . "\n\n");
        $stream->write('- Status: `' . $item['status'] . "`\n");
        $stream->write('- Severity: `' . $item['severity'] . "`\n");
        foreach ((array) $item['reasons'] as $reason) {
            $stream->write('- ' . $reason . "\n");
        }
        $stream->write("\n```json\n");
        $stream->write((string) json_encode($item['signals'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $stream->write("\n```\n\n");
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
     * @param array<string,mixed> $signals Report item signals.
     *
     * @return array<string,mixed>
     */
    private function formatSignals(array $signals): array
    {
        foreach ((array) ag($signals, 'backend_items', []) as $backend => $metadata) {
            if (!is_array($metadata)) {
                continue;
            }

            $signals['backend_items'][$backend] = [
                ...$metadata,
                'webUrl' => $this->backendItemWebUrl((string) $backend, $metadata),
            ];
        }

        return $signals;
    }

    /**
     * @param array<string,mixed> $metadata Backend item metadata.
     */
    private function backendItemWebUrl(string $backend, array $metadata): ?string
    {
        $id = ag($metadata, 'id');
        if (null === $id || '' === (string) $id) {
            return null;
        }

        try {
            return (string) $this->getBackendItemWebUrl(
                backend: $backend,
                type: (string) ag($metadata, 'type', ''),
                id: (string) $id,
            );
        } catch (Throwable) {
            return null;
        }
    }
}
