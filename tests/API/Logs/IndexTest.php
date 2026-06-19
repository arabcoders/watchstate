<?php

declare(strict_types=1);

namespace Tests\API\Logs;

use App\API\Logs\Index;
use App\API\System\Events;
use App\Libs\TestCase;

final class IndexTest extends TestCase
{
    public function test_formatLog_no_whitelist(): void
    {
        $line = "[2026-04-27T10:17:56+03:00] NOTICE: Processing 'main@emby_main' - '#123: IppSec' item.";

        $parsed = Events::formatEventLog($line);

        $this->assertSame($line, $parsed['text']);
        $this->assertNull($parsed['date']);
    }

    public function test_formatLog_stringifies(): void
    {
        $parsed = Events::formatEventLog(['message' => 'boom', 'code' => 1]);

        $this->assertSame('{"message":"boom","code":1}', $parsed['text']);
        $this->assertNull($parsed['date']);
    }

    public function test_event_marker(): void
    {
        $eventId = '550e8400-e29b-41d4-a716-446655440000';
        $line = "[2026-04-27T10:17:56+03:00] NOTICE: [event:{$eventId}] Dispatching Event: 'on_push' queued at '2026-05-17T08:25:02+03:00'.";

        $parsed = Events::formatEventLog($line);

        $this->assertSame($line, $parsed['text']);
        $this->assertNull($parsed['level']);
    }

    public function test_jsonl(): void
    {
        $eventId = '550e8400-e29b-41d4-a716-446655440000';
        $line = json_encode(
            [
                'id' => 'log-1',
                'datetime' => '2026-04-27T10:17:56.123+03:00',
                'level' => 'notice',
                'levelno' => LOG_NOTICE,
                'logger' => 'logger',
                'message' => "[event:{$eventId}] Processing '#123: IppSec' item.",
                'event_name' => 'state.test.completed',
                'source' => ['module' => 'logger'],
                'process' => ['id' => 1, 'name' => 'cli'],
                'fields' => [
                    'user' => 'main',
                    'backend' => 'emby_main',
                    'item.id' => '123',
                ],
            ],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );

        $parsed = Index::decodeJsonlLine($line);

        $this->assertSame('log-1', $parsed['id']);
        $this->assertSame('123', $parsed['fields']['item.id']);
        $this->assertSame('main', $parsed['fields']['user']);
        $this->assertSame('emby_main', $parsed['fields']['backend']);
        $this->assertSame('2026-04-27T10:17:56.123+03:00', $parsed['datetime']);
        $this->assertSame('notice', $parsed['level']);
        $this->assertSame("[event:{$eventId}] Processing '#123: IppSec' item.", $parsed['message']);
        $this->assertSame('state.test.completed', $parsed['event_name']);
        $this->assertSame('logger', $parsed['logger']);
    }
}
