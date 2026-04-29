<?php

declare(strict_types=1);

namespace Tests\API\System;

use App\API\System\Command;
use App\Libs\Config;
use App\Libs\Enums\Http\Status;
use App\Libs\TestCase;
use DirectoryIterator;
use Tests\Support\RequestResponseTrait;

final class CommandTest extends TestCase
{
    use RequestResponseTrait;

    private string $tmpDir;

    private mixed $previousTmpDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->previousTmpDir = Config::get('tmpDir', null);
        $this->tmpDir = sys_get_temp_dir() . '/watchstate_command_test_' . uniqid('', true);

        mkdir($this->tmpDir, 0o755, true);
        mkdir($this->tmpDir . '/console', 0o755, true);
        Config::save('tmpDir', $this->tmpDir);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tmpDir);

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

    public function test_cleanup_lock(): void
    {
        $handler = new Command();
        $response = $handler->queue($this->getRequest(post: ['command' => 'system:tasks']));

        $payload = json_decode((string) $response->getBody(), true);
        $token = (string) ag($payload, 'token');
        $sessionPath = $this->tmpDir . '/console/' . $token;
        $writerLockPath = $sessionPath . '/writer.lock';

        $lockHandle = fopen($writerLockPath, 'c+');
        $this->assertIsResource($lockHandle);
        $this->assertTrue(flock($lockHandle, LOCK_EX | LOCK_NB));

        $cleanup = new \ReflectionMethod($handler, 'cleanupSession');
        $cleanup->invoke($handler, $sessionPath);

        $this->assertTrue(is_dir($sessionPath));

        flock($lockHandle, LOCK_UN);
        fclose($lockHandle);

        $cleanup->invoke($handler, $sessionPath);

        $this->assertFalse(is_dir($sessionPath));
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
        $second = $handler->queue($this->getRequest(post: ['command' => 'system:index']));

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

    public function test_attach_latest(): void
    {
        $handler = new Command();
        $response = $handler->queue($this->getRequest(post: ['command' => 'system:tasks']));

        $payload = json_decode((string) $response->getBody(), true);
        $token = (string) ag($payload, 'token');
        $sessionPath = $this->tmpDir . '/console/' . $token;
        $statePath = $sessionPath . '/state.json';
        $method = new \ReflectionMethod($handler, 'attachSession');

        $first = $method->invoke($handler, $sessionPath);
        $second = $method->invoke($handler, $sessionPath);
        $state = json_decode((string) file_get_contents($statePath), true);

        $this->assertSame(1, ag($first, 'active_connection'));
        $this->assertSame(2, ag($second, 'active_connection'));
        $this->assertSame(2, ag($state, 'active_connection'));
        $this->assertSame(2, ag($state, 'connection_seq'));
        $this->assertSame(2, ag($state, 'connections'));
    }

    public function test_active_stale(): void
    {
        $handler = new Command();
        $response = $handler->queue($this->getRequest(post: ['command' => 'system:tasks']));

        $payload = json_decode((string) $response->getBody(), true);
        $token = (string) ag($payload, 'token');
        $sessionPath = $this->tmpDir . '/console/' . $token;
        $attach = new \ReflectionMethod($handler, 'attachSession');
        $isActive = new \ReflectionMethod($handler, 'isActiveConnection');

        $attach->invoke($handler, $sessionPath);
        $attach->invoke($handler, $sessionPath);

        $this->assertFalse($isActive->invoke($handler, $sessionPath, 1));
        $this->assertTrue($isActive->invoke($handler, $sessionPath, 2));
    }

    private function removeDirectory(string $path): void
    {
        if (false === is_dir($path)) {
            return;
        }

        foreach (new DirectoryIterator($path) as $item) {
            if ($item->isDot()) {
                continue;
            }

            $itemPath = $item->getRealPath();
            if (false === $itemPath) {
                continue;
            }

            if ($item->isDir()) {
                $this->removeDirectory($itemPath);
                continue;
            }

            unlink($itemPath);
        }

        rmdir($path);
    }
}
