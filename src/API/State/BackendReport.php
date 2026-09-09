<?php

declare(strict_types=1);

namespace App\API\State;

use App\Commands\State\BackendReportCommand;
use App\Commands\System\TasksCommand;
use App\Libs\Attributes\DI\ForModel;
use App\Libs\Attributes\Route\Get;
use App\Libs\Attributes\Route\Post;
use App\Libs\BackendReportGenerator;
use App\Libs\Database\DBLayer;
use App\Libs\Entity\StateInterface as iState;
use App\Libs\Enums\Http\Status;
use App\Libs\Mappers\ImportInterface as iImport;
use App\Libs\Stream;
use App\Libs\Traits\APITraits;
use App\Model\Events\Event;
use App\Model\Events\EventsRepository;
use App\Model\Events\EventsTable;
use App\Model\Events\EventStatus;
use App\Models\BackendReport as ReportModel;
use arabcoders\database\Orm\EntityRepository;
use Psr\Http\Message\ResponseInterface as iResponse;
use Psr\Http\Message\ServerRequestInterface as iRequest;
use Psr\Log\LoggerInterface as iLogger;

final class BackendReport
{
    use APITraits;

    public const string URL = '%{api.prefix}/state/backend-report';

    /**
     * @param EventsRepository $events Events repository.
     * @param EntityRepository<ReportModel> $reports Backend report repository.
     */
    public function __construct(
        private readonly EventsRepository $events,
        #[ForModel(ReportModel::class)]
        private readonly EntityRepository $reports,
    ) {}

    /**
     * Return the latest report for the active identity.
     *
     * @param iRequest $request Request instance.
     * @param iImport $mapper Import mapper.
     * @param iLogger $logger Logger instance.
     */
    #[Get(self::URL . '[/]', name: 'state.backend_report')]
    public function index(iRequest $request, iImport $mapper, iLogger $logger): iResponse
    {
        $identity = $this->getUserContext($request, $mapper, $logger)->name;
        $report = $this->latestReport($identity);
        $queued = $this->queuedTask($identity);

        return api_response(Status::OK, [
            'report' => null !== $report ? $this->formatReport($report) : null,
            'queued' => null !== $queued,
            'queued_event' => null !== $queued ? $queued->id : null,
        ]);
    }

    /**
     * Queue backend report generation.
     *
     * @param iRequest $request Request instance.
     * @param iImport $mapper Import mapper.
     * @param iLogger $logger Logger instance.
     */
    #[Post(self::URL . '/run[/]', name: 'state.backend_report.run')]
    public function run(iRequest $request, iImport $mapper, iLogger $logger): iResponse
    {
        $identity = $this->getUserContext($request, $mapper, $logger)->name;
        if (null !== ($queued = $this->queuedTask($identity))) {
            return api_response(Status::ACCEPTED, [
                'queued' => false,
                'running' => EventStatus::RUNNING === $queued->status,
                'event_id' => $queued->id,
                'message' => 'Backend report is already queued or running.',
            ]);
        }

        $event = queue_event(
            TasksCommand::CNAME,
            [
                'command' => BackendReportCommand::ROUTE,
                'args' => ['--user', $identity, '-v'],
            ],
            [
                EventsTable::COLUMN_REFERENCE => $this->taskReference($identity),
            ],
        );

        return api_response(Status::ACCEPTED, [
            'queued' => true,
            'running' => false,
            'event_id' => $event->id,
            'message' => 'Backend report was queued.',
        ]);
    }

    /**
     * Download the latest report for the active identity.
     *
     * @param iRequest $request Request instance.
     * @param string $format Export format.
     * @param iImport $mapper Import mapper.
     * @param iLogger $logger Logger instance.
     */
    #[Get(self::URL . '/export/{format:json|markdown|csv}[/]', name: 'state.backend_report.export')]
    public function export(iRequest $request, string $format, iImport $mapper, iLogger $logger): iResponse
    {
        $identity = $this->getUserContext($request, $mapper, $logger)->name;
        $report = $this->latestReport($identity);
        if (null === $report || null === $report->id) {
            return api_error('No completed backend report found.', Status::NOT_FOUND);
        }

        $data = $this->formatReport($report);

        $body = match ($format) {
            'json' => json_encode($data, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'csv' => $this->writeCsv($identity, (array) $data['summary']),
            'markdown' => $this->writeMarkdown($data),
        };
        $contentType = match ($format) {
            'json' => 'application/json',
            'csv' => 'text/csv',
            'markdown' => 'text/markdown',
        };
        $extension = 'markdown' === $format ? 'md' : $format;
        $filename = r('backend-report-{identity}-{id}.{extension}', [
            'identity' => $identity,
            'id' => $report->id,
            'extension' => $extension,
        ]);
        $stream = Stream::make('php://temp', 'w+b');
        $stream->write($body);
        $stream->rewind();

        return api_response(Status::OK, $stream, headers: [
            'Content-Type' => $contentType,
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }

    private function latestReport(string $identity): ?ReportModel
    {
        return $this->reports->findOneBy(
            [
                'identity' => $identity,
                'status' => BackendReportGenerator::STATUS_COMPLETED,
            ],
            orderBy: ['completed_at' => 'DESC', 'id' => 'DESC'],
        ) ?? null;
    }

    private function queuedTask(string $identity): ?Event
    {
        $result = (clone $this->events)
            ->setPerpage(1)
            ->setStart(0)
            ->setDescendingOrder()
            ->setSort(EventsTable::COLUMN_CREATED_AT)
            ->findAll([
                EventsTable::COLUMN_REFERENCE => [
                    DBLayer::IS_IN,
                    [
                        $this->taskReference($identity),
                        r('task://{name}', ['name' => BackendReportCommand::TASK_NAME]),
                    ],
                ],
                EventsTable::COLUMN_STATUS => [
                    DBLayer::IS_IN,
                    [EventStatus::PENDING->value, EventStatus::RUNNING->value],
                ],
            ]);

        return $result[0] ?? null;
    }

    /**
     * @return array<string,mixed>
     */
    private function formatReport(ReportModel $report): array
    {
        $summary = $report->summary;
        $summary['backends'] = (object) ag($summary, 'backends', []);
        $summary['origins'] = (object) ag($summary, 'origins', []);

        return [
            'id' => $report->id,
            'status' => $report->status,
            'generated_at' => $report->generated_at,
            'completed_at' => $report->completed_at,
            'version' => $report->version,
            'backend_count' => $report->backend_count,
            'identity' => $report->identity,
            'summary' => $summary,
            'error' => $report->error,
        ];
    }

    private function taskReference(string $identity): string
    {
        return r('task://{name}/{identity}', [
            'name' => BackendReportCommand::TASK_NAME,
            'identity' => $identity,
        ]);
    }

    /**
     * @param array<string,mixed> $summary
     */
    private function writeCsv(string $identity, array $summary): string
    {
        $stream = fopen('php://temp', 'w+');
        fputcsv(
            $stream,
            [
                'identity',
                'backend',
                'configured',
                'library',
                'type',
                'total',
                'watched',
                'unwatched',
                'in_progress',
            ],
            escape: '',
        );

        foreach ((array) ag($summary, 'backends', []) as $backend => $backendSummary) {
            $libraries = (array) ag($backendSummary, 'libraries', []);
            foreach ($libraries as $librarySummary) {
                $this->writeCsvRow(
                    $stream,
                    $identity,
                    (string) $backend,
                    (string) ag(
                        $librarySummary,
                        'title',
                        ag($librarySummary, 'id', BackendReportGenerator::UNKNOWN_LIBRARY),
                    ),
                    (string) ag($librarySummary, 'type', iState::TYPE_MIXED),
                    (array) $librarySummary,
                    true === (bool) ag($backendSummary, 'configured', false),
                );
            }
        }

        rewind($stream);
        return (string) stream_get_contents($stream);
    }

    /**
     * @param resource $stream
     * @param array<string,mixed> $counts
     */
    private function writeCsvRow(
        mixed $stream,
        string $identity,
        string $backend,
        string $library,
        string $type,
        array $counts,
        bool $configured,
    ): void {
        fputcsv(
            $stream,
            [
                $this->csvText($identity),
                $this->csvText($backend),
                true === $configured ? 'yes' : 'no',
                $this->csvText($library),
                $type,
                (int) ag($counts, 'total', 0),
                (int) ag($counts, 'watched', 0),
                (int) ag($counts, 'unwatched', 0),
                (int) ag($counts, 'in_progress', 0),
            ],
            escape: '',
        );
    }

    private function csvText(string $value): string
    {
        return 1 === preg_match('/^[\x00-\x20]*[=+\-@]/', $value) ? "'" . $value : $value;
    }

    /**
     * @param array<string,mixed> $data
     */
    private function writeMarkdown(array $data): string
    {
        $summary = (array) ag($data, 'summary', []);
        $lines = [
            '# Backend Report',
            '',
            '- Identity: ' . (string) ag($data, 'identity', ''),
            '- Generated: ' . make_date((int) ag($data, 'completed_at'))->format(DATE_ATOM),
            '- Total: ' . (int) ag($summary, 'total', 0),
            '- Watched: ' . (int) ag($summary, 'watched', 0),
            '- Unwatched: ' . (int) ag($summary, 'unwatched', 0),
            '- In progress: ' . (int) ag($summary, 'in_progress', 0),
            '',
            '| Backend | Configured | Total | Watched | Unwatched | In progress |',
            '| --- | --- | ---: | ---: | ---: | ---: |',
        ];

        foreach ((array) ag($summary, 'backends', []) as $backend => $counts) {
            $lines[] = sprintf(
                '| %s | %s | %d | %d | %d | %d |',
                str_replace('|', '\\|', (string) $backend),
                true === (bool) ag($counts, 'configured', false) ? 'yes' : 'no',
                (int) ag($counts, 'total', 0),
                (int) ag($counts, 'watched', 0),
                (int) ag($counts, 'unwatched', 0),
                (int) ag($counts, 'in_progress', 0),
            );
        }

        return implode("\n", $lines) . "\n";
    }
}
