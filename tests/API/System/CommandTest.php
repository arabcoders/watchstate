<?php

declare(strict_types=1);

namespace Tests\API\System;

use App\API\System\Command;
use App\Libs\Attributes\Route\Post;
use App\Libs\Config;
use App\Libs\Enums\Http\Status;
use App\Libs\TestCase;
use Tests\Support\RequestResponseTrait;

final class CommandTest extends TestCase
{
    use RequestResponseTrait;

    private string $tmpDir;

    private mixed $previousTmpDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initTempDir();

        $this->previousTmpDir = Config::get('tmpDir', null);
        $this->tmpDir = self::$tmpPath;

        mkdir($this->tmpDir . '/console', 0o755, true);
        Config::save('tmpDir', $this->tmpDir);
    }

    protected function tearDown(): void
    {
        if (null === $this->previousTmpDir) {
            Config::remove('tmpDir');
        } else {
            Config::save('tmpDir', $this->previousTmpDir);
        }

        parent::tearDown();
    }

    public function test_queue(): void
    {
        $handler = new Command();
        $response = $handler->queue($this->getRequest(post: ['command' => 'system:tasks']));

        $this->assertSame(Status::CREATED->value, $response->getStatusCode());

        $payload = json_decode((string) $response->getBody(), true);
        $token = ag($payload, 'token');
        $sessionPath = $this->tmpDir . '/console/' . $token;

        $this->assertIsString($token);
        $this->assertNotSame('', $token);
        $this->assertFileExists($sessionPath . '/request.json');
        $this->assertFileExists($sessionPath . '/state.json');
        $this->assertFileExists($sessionPath . '/stream.log');

        $state = json_decode((string) file_get_contents($sessionPath . '/state.json'), true);

        $this->assertSame('queued', ag($state, 'status'));
        $this->assertSame('system:tasks', ag($state, 'command'));
        $this->assertSame(0, ag($state, 'connections'));
    }

    public function test_stream_done_old(): void
    {
        $handler = new Command();
        $response = $handler->queue($this->getRequest(post: ['command' => 'system:tasks']));

        $payload = json_decode((string) $response->getBody(), true);
        $token = (string) ag($payload, 'token');
        $sessionPath = $this->tmpDir . '/console/' . $token;
        $statePath = $sessionPath . '/state.json';

        $state = json_decode((string) file_get_contents($statePath), true);
        $state['status'] = 'completed';
        $state['connections'] = 0;
        $state['finished_at'] = make_date(strtotime('-2 days'))->format(DATE_ATOM);

        file_put_contents($statePath, json_encode($state, JSON_PRETTY_PRINT | JSON_INVALID_UTF8_IGNORE));

        $streamResponse = $handler->stream($this->getRequest(), $token);

        $this->assertSame(Status::NOT_FOUND->value, $streamResponse->getStatusCode());
        $this->assertTrue(is_dir($sessionPath));
    }

    public function test_stream_queue_old(): void
    {
        $handler = new Command();
        $response = $handler->queue($this->getRequest(post: ['command' => 'system:tasks']));

        $payload = json_decode((string) $response->getBody(), true);
        $token = (string) ag($payload, 'token');
        $sessionPath = $this->tmpDir . '/console/' . $token;
        $statePath = $sessionPath . '/state.json';

        $state = json_decode((string) file_get_contents($statePath), true);
        $state['expires_at'] = make_date(strtotime('-10 minutes'))->format(DATE_ATOM);

        file_put_contents($statePath, json_encode($state, JSON_PRETTY_PRINT | JSON_INVALID_UTF8_IGNORE));

        $streamResponse = $handler->stream($this->getRequest(), $token);

        $this->assertSame(Status::NOT_FOUND->value, $streamResponse->getStatusCode());
        $this->assertTrue(is_dir($sessionPath));
    }

    public function test_stream_done_live(): void
    {
        $handler = new Command();
        $response = $handler->queue($this->getRequest(post: ['command' => 'system:tasks']));

        $payload = json_decode((string) $response->getBody(), true);
        $token = (string) ag($payload, 'token');
        $sessionPath = $this->tmpDir . '/console/' . $token;
        $statePath = $sessionPath . '/state.json';

        $state = json_decode((string) file_get_contents($statePath), true);
        $state['status'] = 'completed';
        $state['connections'] = 1;
        $state['last_sequence'] = 2;

        file_put_contents($statePath, json_encode($state, JSON_PRETTY_PRINT | JSON_INVALID_UTF8_IGNORE));

        $streamResponse = $handler->stream($this->getRequest(), $token);

        $this->assertSame(Status::OK->value, $streamResponse->getStatusCode());
        $this->assertTrue(is_dir($sessionPath));
    }

    public function test_list(): void
    {
        $handler = new Command();
        $first = $handler->queue($this->getRequest(post: ['command' => 'system:tasks']));
        $second = $handler->queue($this->getRequest(post: ['command' => 'db:index']));

        $firstToken = (string) ag(json_decode((string) $first->getBody(), true), 'token');
        $secondToken = (string) ag(json_decode((string) $second->getBody(), true), 'token');

        $firstStatePath = $this->tmpDir . '/console/' . $firstToken . '/state.json';
        $firstState = json_decode((string) file_get_contents($firstStatePath), true);
        $firstState['status'] = 'completed';
        $firstState['connections'] = 0;
        $firstState['exit_code'] = 0;
        $firstState['finished_at'] = make_date(strtotime('-2 hours'))->format(DATE_ATOM);
        $firstState['updated_at'] = make_date(strtotime('-2 hours'))->format(DATE_ATOM);
        file_put_contents($firstStatePath, json_encode($firstState, JSON_PRETTY_PRINT | JSON_INVALID_UTF8_IGNORE));

        $response = $handler->list();
        $payload = json_decode((string) $response->getBody(), true);
        $items = ag($payload, 'items', []);

        $this->assertSame(Status::OK->value, $response->getStatusCode());
        $this->assertCount(2, $items);
        $this->assertSame($secondToken, ag($items[0], 'token'));
        $this->assertSame($firstToken, ag($items[1], 'token'));
        $this->assertSame('system:tasks', ag($items[1], 'command'));
        $this->assertSame('completed', ag($items[1], 'status'));
        $this->assertSame(0, ag($items[1], 'exit_code'));
        $this->assertNotNull(ag($items[1], 'available_until'));
    }

    public function test_stream_done_gap(): void
    {
        $handler = new Command();
        $response = $handler->queue($this->getRequest(post: ['command' => 'system:tasks']));

        $payload = json_decode((string) $response->getBody(), true);
        $token = (string) ag($payload, 'token');
        $sessionPath = $this->tmpDir . '/console/' . $token;
        $statePath = $sessionPath . '/state.json';

        $state = json_decode((string) file_get_contents($statePath), true);
        $state['status'] = 'completed';
        $state['connections'] = 0;
        $state['finished_at'] = make_date()->format(DATE_ATOM);

        file_put_contents($statePath, json_encode($state, JSON_PRETTY_PRINT | JSON_INVALID_UTF8_IGNORE));

        $streamResponse = $handler->stream($this->getRequest(), $token);

        $this->assertSame(Status::OK->value, $streamResponse->getStatusCode());
        $this->assertTrue(is_dir($sessionPath));
    }

    public function test_cancel_done_old(): void
    {
        $handler = new Command();
        $response = $handler->queue($this->getRequest(post: ['command' => 'system:tasks']));

        $payload = json_decode((string) $response->getBody(), true);
        $token = (string) ag($payload, 'token');
        $sessionPath = $this->tmpDir . '/console/' . $token;
        $statePath = $sessionPath . '/state.json';

        $state = json_decode((string) file_get_contents($statePath), true);
        $state['status'] = 'completed';
        $state['connections'] = 0;
        $state['finished_at'] = make_date(strtotime('-2 days'))->format(DATE_ATOM);

        file_put_contents($statePath, json_encode($state, JSON_PRETTY_PRINT | JSON_INVALID_UTF8_IGNORE));

        $cancelResponse = $handler->cancel($token);

        $this->assertSame(Status::NOT_FOUND->value, $cancelResponse->getStatusCode());
        $this->assertTrue(is_dir($sessionPath));
    }

    public function test_cancel_queue_old(): void
    {
        $handler = new Command();
        $response = $handler->queue($this->getRequest(post: ['command' => 'system:tasks']));

        $payload = json_decode((string) $response->getBody(), true);
        $token = (string) ag($payload, 'token');
        $sessionPath = $this->tmpDir . '/console/' . $token;
        $statePath = $sessionPath . '/state.json';

        $state = json_decode((string) file_get_contents($statePath), true);
        $state['expires_at'] = make_date(strtotime('-10 minutes'))->format(DATE_ATOM);

        file_put_contents($statePath, json_encode($state, JSON_PRETTY_PRINT | JSON_INVALID_UTF8_IGNORE));

        $cancelResponse = $handler->cancel($token);

        $this->assertSame(Status::NOT_FOUND->value, $cancelResponse->getStatusCode());
        $this->assertTrue(is_dir($sessionPath));
    }

    public function test_cancel_queue(): void
    {
        $handler = new Command();
        $response = $handler->queue($this->getRequest(post: ['command' => 'system:tasks']));

        $payload = json_decode((string) $response->getBody(), true);
        $token = (string) ag($payload, 'token');
        $sessionPath = $this->tmpDir . '/console/' . $token;

        $cancelResponse = $handler->cancel($token);
        $cancelPayload = json_decode((string) $cancelResponse->getBody(), true);

        $this->assertSame(Status::ACCEPTED->value, $cancelResponse->getStatusCode());
        $this->assertSame('Command cancellation requested.', ag($cancelPayload, 'message'));
        $this->assertFalse(is_dir($sessionPath));
    }

    public function test_cancel_run(): void
    {
        $handler = new Command();
        $response = $handler->queue($this->getRequest(post: ['command' => 'system:tasks']));

        $payload = json_decode((string) $response->getBody(), true);
        $token = (string) ag($payload, 'token');
        $sessionPath = $this->tmpDir . '/console/' . $token;
        $statePath = $sessionPath . '/state.json';

        $state = json_decode((string) file_get_contents($statePath), true);
        $state['status'] = 'running';

        file_put_contents($statePath, json_encode($state, JSON_PRETTY_PRINT | JSON_INVALID_UTF8_IGNORE));

        $cancelResponse = $handler->cancel($token);
        $cancelPayload = json_decode((string) $cancelResponse->getBody(), true);

        $this->assertSame(Status::ACCEPTED->value, $cancelResponse->getStatusCode());
        $this->assertSame('Command cancellation requested.', ag($cancelPayload, 'message'));
        $this->assertFileExists($sessionPath . '/cancel.flag');
    }
}
