<?php

declare(strict_types=1);

namespace Tests\API;

use App\API\Tasks;
use App\Commands\System\TasksCommand;
use App\Libs\Database\PDO\PDOAdapter;
use App\Libs\Enums\Http\Status;
use App\Libs\TestCase;
use App\Model\Events\EventsRepository;
use App\Model\Events\EventStatus;
use Monolog\Handler\NullHandler;
use Monolog\Logger;

final class TasksTest extends TestCase
{
    private EventsRepository $repo;
    private PDOAdapter $db;

    protected function setUp(): void
    {
        parent::setUp();

        $this->initTempApp();
        $this->db = $this->createDb(new Logger('test', [new NullHandler()]));
        $this->repo = new EventsRepository($this->db->getDBLayer());
    }

    public function test_prev_run_event(): void
    {
        $older = $this->repo->getObject([]);
        $older->event = TasksCommand::NAME . '.backup';
        $older->status = EventStatus::SUCCESS;
        $older->created_at = make_date('2024-01-01T00:00:00+00:00');
        $older->updated_at = make_date('2024-01-01T00:10:00+00:00');
        $this->repo->save($older);

        $queuedRun = $this->repo->getObject([]);
        $queuedRun->event = TasksCommand::NAME;
        $queuedRun->reference = 'task://backup';
        $queuedRun->status = EventStatus::FAILED;
        $queuedRun->created_at = make_date('2024-01-02T00:00:00+00:00');
        $queuedRun->updated_at = make_date('2024-01-02T00:10:00+00:00');
        $queuedId = $this->repo->save($queuedRun);

        $queued = $this->repo->getObject([]);
        $queued->event = TasksCommand::NAME;
        $queued->reference = 'task://backup';
        $queued->status = EventStatus::PENDING;
        $queued->created_at = make_date('2024-01-03T00:00:00+00:00');
        $this->repo->save($queued);

        $handler = new Tasks($this->repo);
        $response = $handler->taskView('backup');
        $index = $handler->tasksIndex();

        self::assertSame(Status::OK->value, $response->getStatusCode());
        self::assertSame(Status::OK->value, $index->getStatusCode());

        $payload = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        $indexPayload = json_decode((string) $index->getBody(), true, flags: JSON_THROW_ON_ERROR);
        $items = array_values(array_filter(
            (array) ag($indexPayload, 'tasks', []),
            static fn(array $item): bool => 'backup' === ($item['name'] ?? null),
        ));

        $expectedPrevRun = (string) make_date('2024-01-02T00:10:00+00:00');

        self::assertSame($expectedPrevRun, ag($payload, 'prev_run'));
        self::assertSame($queuedId, ag($payload, 'prev_run_event_id'));
        self::assertNotSame(ag($payload, 'prev_run'), ag($payload, 'next_run'));
        self::assertCount(1, $items);
        self::assertSame($expectedPrevRun, ag($items[0], 'prev_run'));
        self::assertSame($queuedId, ag($items[0], 'prev_run_event_id'));
    }

    public function test_prev_run_estimate(): void
    {
        $handler = new Tasks($this->repo);

        $response = $handler->taskView('backup');

        self::assertSame(Status::OK->value, $response->getStatusCode());

        $payload = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        self::assertNull(ag($payload, 'prev_run_event_id'));
        self::assertNotNull(ag($payload, 'next_run'));
        self::assertNotSame(ag($payload, 'prev_run'), ag($payload, 'next_run'));
    }
}
