<?php

declare(strict_types=1);

namespace Tests\API\Logs;

use App\API\Logs\Index;
use App\Libs\Container;
use App\Libs\TestCase;
use App\Libs\Mappers\ImportInterface;
use Monolog\Logger;
use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Uri;
use Psr\Log\LoggerInterface as iLogger;

final class IndexTest extends TestCase
{
    public function test_formatLog_no_whitelist(): void
    {
        $line = "[2026-04-27T10:17:56+03:00] NOTICE: Processing 'main@emby_main' - '#123: IppSec' item.";

        $parsed = Index::formatLog($line);

        $this->assertNull($parsed['state_id'], 'Legacy log formatter should not infer local history ids from plain text.');
        $this->assertNull($parsed['remote_id'], 'Legacy log formatter should not infer backend ids from plain text.');
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
        $this->assertNull($parsed['state_id'], 'Stringified payloads should not invent local history ids.');
        $this->assertNull($parsed['remote_id'], 'Stringified payloads should not invent backend ids.');
        $this->assertNull($parsed['user'], 'Stringified payloads should not invent users.');
        $this->assertNull($parsed['backend'], 'Stringified payloads should not invent backends.');
    }

    public function test_event_marker(): void
    {
        $eventId = '550e8400-e29b-41d4-a716-446655440000';
        $line = "[2026-04-27T10:17:56+03:00] NOTICE: [event:{$eventId}] Dispatching queued event 'on_push' from 2026-05-17T08:25:02+03:00.";

        $parsed = Index::formatLog($line);

        $this->assertSame($eventId, $parsed['event_id']);
        $this->assertSame('notice', $parsed['level']);
        $this->assertSame("NOTICE: Dispatching queued event 'on_push' from 2026-05-17T08:25:02+03:00.", $parsed['text']);
    }

    public function test_jsonl(): void
    {
        $line = json_encode([
            'id' => 'log-id',
            'datetime' => '2026-05-20T12:00:00.123+00:00',
            'level' => 'notice',
            'levelno' => LOG_NOTICE,
            'logger' => 'app',
            'message' => "Processing 'main@emby_main' - '#123: IppSec' item.",
            'source' => ['module' => 'app'],
            'process' => ['id' => 1, 'name' => 'cli'],
            'thread' => ['id' => 0, 'name' => 'main'],
            'fields' => [
                'event_id' => '550e8400-e29b-41d4-a716-446655440000',
                'user' => 'main',
                'backend' => 'emby_main',
                'state_id' => 123,
                'remote_id' => 'abc-123',
            ],
        ], JSON_THROW_ON_ERROR);

        $parsed = Index::formatLog($line);

        $this->assertSame('log-id', $parsed['id']);
        $this->assertSame('2026-05-20T12:00:00.123+00:00', $parsed['date']);
        $this->assertSame('notice', $parsed['level']);
        $this->assertSame('app', $parsed['logger']);
        $this->assertSame('123', $parsed['state_id']);
        $this->assertSame('abc-123', $parsed['remote_id']);
        $this->assertSame('main', $parsed['user']);
        $this->assertSame('emby_main', $parsed['backend']);
    }

    public function test_jsonl_nested_ids(): void
    {
        $line = json_encode([
            'id' => 'nested-jsonl',
            'datetime' => '2026-05-20T12:00:00.123+00:00',
            'level' => 'notice',
            'logger' => 'app',
            'message' => 'Processing webhook',
            'fields' => [
                'event' => ['id' => '550e8400-e29b-41d4-a716-446655440000'],
                'user' => ['name' => 'main'],
                'backend' => ['name' => 'plex'],
                'attributes' => [
                    'item' => [
                        'state_id' => 123,
                        'remote_id' => 'abc-123',
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $parsed = Index::formatLog($line);

        $this->assertSame('123', $parsed['state_id']);
        $this->assertSame('abc-123', $parsed['remote_id']);
        $this->assertSame('main', $parsed['user']);
        $this->assertSame('plex', $parsed['backend']);
        $this->assertSame('550e8400-e29b-41d4-a716-446655440000', $parsed['event_id']);
    }

    public function test_schema_nested_ids(): void
    {
        $line = json_encode([
            'schema' => 1,
            'id' => 'schema-id',
            'datetime' => '2026-05-20T12:00:00.123+00:00',
            'level' => 'notice',
            'logger' => 'app',
            'message' => 'Processing export',
            'context' => [
                'attributes' => [
                    'item' => [
                        'state_id' => 321,
                    ],
                    'user' => ['name' => 'alice'],
                    'backend' => ['name' => 'emby'],
                    'event' => ['id' => '550e8400-e29b-41d4-a716-446655440001'],
                ],
            ],
            'extras' => [
                'item' => [
                    'remote_id' => 'remote-321',
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $parsed = Index::formatLog($line);

        $this->assertSame('321', $parsed['state_id']);
        $this->assertSame('remote-321', $parsed['remote_id']);
        $this->assertSame('alice', $parsed['user']);
        $this->assertSame('emby', $parsed['backend']);
        $this->assertSame('550e8400-e29b-41d4-a716-446655440001', $parsed['event_id']);
    }

    public function test_logView_returns_raw_lines(): void
    {
        $this->initTempApp();

        $date = make_date()->format('Ymd');
        $logPath = self::$tmpPath . '/logs/task.' . $date . '.jsonl';
        mkdir(dirname($logPath), 0o755, true);

        $lines = [
            '{"id":"one","datetime":"2026-05-20T12:00:00.123+00:00","level":"info","logger":"task","message":"first"}',
            '{"id":"two","datetime":"2026-05-20T12:00:01.123+00:00","level":"warning","logger":"task","message":"second"}',
        ];

        file_put_contents($logPath, implode(PHP_EOL, $lines) . PHP_EOL);

        $handler = $this->makeHandler();
        $request = (new ServerRequest('GET', new Uri('http://localhost/v1/api/log/task.' . $date . '.jsonl')))
            ->withQueryParams(['offset' => 100]);

        $response = $handler->logView($request, ['filename' => basename($logPath)]);
        $payload = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('log', ag($payload, 'type'));
        $this->assertSame($lines, ag($payload, 'lines'));
        $this->assertIsString(ag($payload, 'lines.0'));
    }

    public function test_recent_returns_raw_lines(): void
    {
        $this->initTempApp();

        $date = make_date()->format('Ymd');
        $logPath = self::$tmpPath . '/logs/app.' . $date . '.jsonl';
        mkdir(dirname($logPath), 0o755, true);

        $lines = [
            '{"id":"one","datetime":"2026-05-20T12:00:00.123+00:00","level":"info","logger":"app","message":"first"}',
            '{"id":"two","datetime":"2026-05-20T12:00:01.123+00:00","level":"error","logger":"app","message":"second"}',
        ];

        file_put_contents($logPath, implode(PHP_EOL, $lines) . PHP_EOL);

        $handler = $this->makeHandler();
        $request = (new ServerRequest('GET', new Uri('http://localhost/v1/api/logs/recent')))
            ->withQueryParams(['limit' => 2]);

        $response = $handler->recent($request);
        $payload = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertCount(1, $payload);
        $this->assertSame(basename($logPath), ag($payload, '0.filename'));
        $this->assertSame($lines, ag($payload, '0.lines'));
        $this->assertIsString(ag($payload, '0.lines.1'));
    }

    private function makeHandler(): Index
    {
        $logger = Container::get(iLogger::class);
        assert($logger instanceof Logger || $logger instanceof iLogger);

        return new Index(Container::get(ImportInterface::class), $logger);
    }
}
