<?php

declare(strict_types=1);

namespace Tests\API\Logs;

use App\API\Logs\Index;
use App\Libs\TestCase;

final class IndexTest extends TestCase
{
    public function test_formatLog_extracts_context_without_user_whitelist(): void
    {
        $line = "[2026-04-27T10:17:56+03:00] NOTICE: Processing 'main@office_emby' - '#123: IppSec' item.";

        $parsed = Index::formatLog($line);

        $this->assertSame('123', $parsed['item_id'], 'Log formatter should extract history item ids from structured messages.');
        $this->assertSame('main', $parsed['user'], 'Log formatter should expose the user even when no whitelist is provided.');
        $this->assertSame('office_emby', $parsed['backend'], 'Log formatter should expose the backend even when no whitelist is provided.');
        $this->assertSame('2026-04-27T10:17:56+03:00', $parsed['date'], 'Log formatter should preserve the bracketed timestamp.');
        $this->assertSame(
            "NOTICE: Processing 'main@office_emby' - '#123: IppSec' item.",
            $parsed['text'],
            'Log formatter should strip the timestamp prefix from the display text.'
        );
    }

    public function test_formatLog_stringifies_non_string_payloads(): void
    {
        $parsed = Index::formatLog(['message' => 'boom', 'code' => 1]);

        $this->assertSame('{"message":"boom","code":1}', $parsed['text'], 'Non-string log payloads should be stringified for API consumers.');
        $this->assertNull($parsed['date'], 'Stringified payloads should not invent timestamps.');
        $this->assertNull($parsed['item_id'], 'Stringified payloads should not invent item ids.');
        $this->assertNull($parsed['user'], 'Stringified payloads should not invent users.');
        $this->assertNull($parsed['backend'], 'Stringified payloads should not invent backends.');
    }
}
