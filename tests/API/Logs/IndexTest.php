<?php

declare(strict_types=1);

namespace Tests\API\Logs;

use App\API\Logs\Index;
use App\Libs\TestCase;

final class IndexTest extends TestCase
{
    public function test_formatLog_no_whitelist(): void
    {
        $line = "[2026-04-27T10:17:56+03:00] NOTICE: Processing 'main@emby_main' - '#123: IppSec' item.";

        $parsed = Index::formatLog($line);

        $this->assertSame('123', $parsed['item_id'], 'Log formatter should extract history item ids from structured messages.');
        $this->assertSame('main', $parsed['user'], 'Log formatter should expose the user even when no whitelist is provided.');
        $this->assertSame('emby_main', $parsed['backend'], 'Log formatter should expose the backend even when no whitelist is provided.');
        $this->assertSame('2026-04-27T10:17:56+03:00', $parsed['date'], 'Log formatter should preserve the bracketed timestamp.');
        $this->assertSame(
            "NOTICE: Processing 'main@emby_main' - '#123: IppSec' item.",
            $parsed['text'],
            'Log formatter should strip the timestamp prefix from the display text.'
        );
    }

    public function test_formatLog_stringifies(): void
    {
        $parsed = Index::formatLog(['message' => 'boom', 'code' => 1]);

        $this->assertSame('{"message":"boom","code":1}', $parsed['text'], 'Non-string log payloads should be stringified for API consumers.');
        $this->assertNull($parsed['date'], 'Stringified payloads should not invent timestamps.');
        $this->assertNull($parsed['item_id'], 'Stringified payloads should not invent item ids.');
        $this->assertNull($parsed['user'], 'Stringified payloads should not invent users.');
        $this->assertNull($parsed['backend'], 'Stringified payloads should not invent backends.');
    }

    public function test_event_marker(): void
    {
        $eventId = '550e8400-e29b-41d4-a716-446655440000';
        $line = "[2026-04-27T10:17:56+03:00] NOTICE: [event:{$eventId}] Dispatching Event: 'on_push' queued at '2026-05-17T08:25:02+03:00'.";

        $parsed = Index::formatLog($line);

        $this->assertSame($eventId, $parsed['event_id']);
        $this->assertSame('notice', $parsed['level']);
        $this->assertSame("NOTICE: Dispatching Event: 'on_push' queued at '2026-05-17T08:25:02+03:00'.", $parsed['text']);
    }
}
