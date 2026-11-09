<?php

declare(strict_types=1);

namespace App\API\State;

use App\Commands\State\UserStatisticsCommand;
use App\Commands\System\TasksCommand;
use App\Libs\Attributes\Route\Get;
use App\Libs\Attributes\Route\Post;
use App\Libs\Database\DBLayer;
use App\Libs\Enums\Http\Status;
use App\Libs\Mappers\ImportInterface as iImport;
use App\Libs\Stream;
use App\Libs\Traits\APITraits;
use App\Libs\UserStatisticsGenerator;
use App\Model\Events\Event;
use App\Model\Events\EventsRepository;
use App\Model\Events\EventsTable;
use App\Model\Events\EventStatus;
use Psr\Http\Message\ResponseInterface as iResponse;
use Psr\Http\Message\ServerRequestInterface as iRequest;
use Psr\Log\LoggerInterface as iLogger;

final class UserStatistics
{
    use APITraits;

    public const string URL = '%{api.prefix}/state/user-statistics';

    /**
     * @param EventsRepository $events Events repository.
     * @param UserStatisticsGenerator $generator Local statistics generator.
     */
    public function __construct(
        private readonly EventsRepository $events,
        private readonly UserStatisticsGenerator $generator,
    ) {}

    #[Get(self::URL . '[/]', name: 'state.user_statistics')]
    public function index(iRequest $request, iImport $mapper, iLogger $logger): iResponse
    {
        $context = $this->getUserContext($request, $mapper, $logger);
        $queued = $this->queuedTask($context->name);

        return api_response(Status::OK, [
            'report' => $this->generator->latest($context),
            'queued' => null !== $queued,
            'queued_event' => $queued?->id,
            'stale' => $this->generator->isStale($context),
        ]);
    }

    #[Post(self::URL . '/run[/]', name: 'state.user_statistics.run')]
    public function run(iRequest $request, iImport $mapper, iLogger $logger): iResponse
    {
        $identity = $this->getUserContext($request, $mapper, $logger)->name;
        if (null !== ($queued = $this->queuedTask($identity))) {
            return api_response(Status::ACCEPTED, [
                'queued' => false,
                'running' => EventStatus::RUNNING === $queued->status,
                'event_id' => $queued->id,
                'message' => 'User statistics are already queued or running.',
            ]);
        }

        $event = queue_event(
            TasksCommand::CNAME,
            [
                'command' => UserStatisticsCommand::ROUTE,
                'args' => ['--user', $identity, '-v'],
            ],
            [EventsTable::COLUMN_REFERENCE => $this->taskReference($identity)],
        );

        return api_response(Status::ACCEPTED, [
            'queued' => true,
            'running' => false,
            'event_id' => $event->id,
            'message' => 'User statistics were queued.',
        ]);
    }

    #[Get(self::URL . '/export/{format:json|markdown}[/]', name: 'state.user_statistics.export')]
    public function export(iRequest $request, string $format, iImport $mapper, iLogger $logger): iResponse
    {
        $context = $this->getUserContext($request, $mapper, $logger);
        $report = $this->generator->latest($context);
        if (null === $report) {
            return api_error('No completed user statistics found.', Status::NOT_FOUND);
        }

        $public = $this->generator->export($report);
        $body = match ($format) {
            'json' => json_encode($public, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'markdown' => $this->generator->markdown($public),
        };
        $extension = 'markdown' === $format ? 'md' : 'json';
        $stream = Stream::make('php://temp', 'w+b');
        $stream->write($body);
        $stream->rewind();

        return api_response(Status::OK, $stream, headers: [
            'Content-Type' => 'markdown' === $format ? 'text/markdown; charset=utf-8' : 'application/json',
            'Content-Disposition' => 'attachment; filename="user-statistics-' . (int) $report['generated_at'] . '.' . $extension . '"',
        ]);
    }

    private function queuedTask(string $identity): ?Event
    {
        $events = (clone $this->events)
            ->setPerpage(1)
            ->setStart(0)
            ->setDescendingOrder()
            ->setSort(EventsTable::COLUMN_CREATED_AT)
            ->findAll([
                EventsTable::COLUMN_REFERENCE => [
                    DBLayer::IS_IN,
                    [$this->taskReference($identity), 'task://' . UserStatisticsCommand::TASK_NAME],
                ],
                EventsTable::COLUMN_STATUS => [
                    DBLayer::IS_IN,
                    [EventStatus::PENDING->value, EventStatus::RUNNING->value],
                ],
            ]);

        return $events[0] ?? null;
    }

    private function taskReference(string $identity): string
    {
        return 'task://' . UserStatisticsCommand::TASK_NAME . '/' . $identity;
    }
}
