<?php

declare(strict_types=1);

namespace Tests\API\History;

use App\API\History\Index;
use App\Libs\Config;
use App\Libs\Mappers\ImportInterface;
use App\Libs\Options;
use App\Libs\TestCase;
use App\Model\Events\EventsRepository;
use App\Model\Events\EventStatus;
use Monolog\Handler\NullHandler;
use Monolog\Logger;

final class IndexTest extends TestCase
{
    public function test_related_filters(): void
    {
        $this->initTempApp();
        $logger = new Logger('test', [new NullHandler()]);
        $eventsRepo = new EventsRepository($this->createDb($logger)->getDBLayer());

        $push = $eventsRepo->getObject([]);
        $push->event = 'on_push';
        $push->status = EventStatus::SUCCESS;
        $push->event_data = ['id' => 7];
        $push->options = [Options::CONTEXT_USER => 'alice'];
        $eventsRepo->save($push);

        $bob = $eventsRepo->getObject([]);
        $bob->event = 'on_push';
        $bob->event_data = ['id' => 7];
        $bob->options = [Options::CONTEXT_USER => 'bob'];
        $eventsRepo->save($bob);

        $missingUser = $eventsRepo->getObject([]);
        $missingUser->event = 'on_push';
        $missingUser->event_data = ['id' => 7];
        $eventsRepo->save($missingUser);

        $unrelated = $eventsRepo->getObject([]);
        $unrelated->event = 'unrelated';
        $unrelated->event_data = ['id' => 7];
        $unrelated->options = [Options::CONTEXT_USER => 'alice'];
        $eventsRepo->save($unrelated);

        $logged = $eventsRepo->getObject([]);
        $logged->event = 'unrelated';
        $logged->logs = [json_encode([
            'fields' => [
                'history.id' => 7,
                'identity.user' => 'alice',
            ],
        ], JSON_THROW_ON_ERROR)];
        $eventsRepo->save($logged);

        $logPath = (string) Config::get('tmpDir') . '/logs';
        mkdir($logPath, 0o755, true);
        $date = make_date()->format('Ymd');
        $lines = [];
        foreach (range(1, 12) as $number) {
            $lines[] = json_encode([
                'id' => 'line-' . $number,
                'datetime' => sprintf('2026-01-01T00:00:%02d+00:00', $number),
                'level' => 'info',
                'logger' => 'test',
                'message' => 'matched ' . $number,
                'fields' => ['history.id' => 7, 'identity.user' => 'alice'],
            ], JSON_THROW_ON_ERROR);
        }
        $line = $lines[0];
        file_put_contents($logPath . '/app.' . $date . '.jsonl', implode(PHP_EOL, $lines) . PHP_EOL);
        file_put_contents(
            $logPath . '/other.' . $date . '.jsonl',
            str_replace(
                'alice',
                'bob',
                $line,
            )
                . PHP_EOL,
        );
        file_put_contents(
            $logPath . '/missing.' . $date . '.jsonl',
            str_replace(
                ',"identity.user":"alice"',
                '',
                $line,
            )
                . PHP_EOL,
        );
        file_put_contents($logPath . '/old.20000101.jsonl', $line . PHP_EOL);

        $handler = new Index($this->createStub(ImportInterface::class), $logger, $eventsRepo);
        $method = new \ReflectionMethod($handler, 'findRelated');
        $path = getenv('PATH');
        putenv('PATH=');
        try {
            $result = $method->invoke($handler, '7', 'alice');
        } finally {
            putenv(false === $path ? 'PATH' : 'PATH=' . $path);
        }

        self::assertCount(10, $result['logs']);
        self::assertSame('app.' . $date . '.jsonl', $result['logs'][0]['filename']);
        self::assertSame('line-12', $result['logs'][0]['entry']['id']);
        self::assertSame('line-3', $result['logs'][9]['entry']['id']);
        self::assertCount(2, $result['events']);
        $eventIds = array_column($result['events'], 'id');
        self::assertContains((string) $push->id, $eventIds);
        self::assertContains((string) $logged->id, $eventIds);
        $eventsById = array_column($result['events'], null, 'id');
        $pushResult = $eventsById[(string) $push->id];
        self::assertSame(EventStatus::SUCCESS->value, $pushResult['status']);
        self::assertSame('Success', $pushResult['status_name']);
    }

    public function test_related_rg(): void
    {
        $this->initTempDir();
        $logger = new Logger('test', [new NullHandler()]);
        $eventsRepo = new EventsRepository($this->createDb($logger)->getDBLayer());
        $handler = new Index($this->createStub(ImportInterface::class), $logger, $eventsRepo);
        $matches = [];

        foreach (range(1, 12) as $number) {
            $entry = json_encode([
                'id' => 'line-' . $number,
                'datetime' => sprintf('2026-01-01T00:00:%02d+00:00', $number),
                'level' => 'info',
                'logger' => 'test',
                'message' => 'matched ' . $number,
                'fields' => ['history.id' => 7, 'identity.user' => 'alice'],
            ], JSON_THROW_ON_ERROR);
            $matches[] = json_encode([
                'type' => 'match',
                'data' => [
                    'path' => ['text' => '/tmp/task.20260907.jsonl'],
                    'lines' => ['text' => $entry . PHP_EOL],
                ],
            ], JSON_THROW_ON_ERROR);
        }

        $fakeRipgrep = self::$tmpPath . '/rg';
        file_put_contents(
            $fakeRipgrep,
            '#!/bin/sh' . PHP_EOL . 'printf %s ' . escapeshellarg(implode(PHP_EOL, $matches) . PHP_EOL),
        );
        chmod($fakeRipgrep, 0o755);

        $method = new \ReflectionMethod($handler, 'findRelatedLogsWithRipgrep');
        $result = $method->invoke($handler, $fakeRipgrep, ['/tmp/unused.jsonl'], '7', 'alice');

        self::assertCount(12, $result);
        self::assertSame('task.20260907.jsonl', $result[0]['filename']);
        self::assertSame('line-12', $result[11]['entry']['id']);
    }

    public function test_php_chunks(): void
    {
        $this->initTempDir();
        $logger = new Logger('test', [new NullHandler()]);
        $handler = new Index(
            $this->createStub(ImportInterface::class),
            $logger,
            new EventsRepository($this->createDb($logger)->getDBLayer()),
        );
        $lines = [];
        foreach (range(1, 3) as $number) {
            $lines[] = json_encode([
                'id' => 'line-' . $number,
                'datetime' => '2026-09-07T00:00:00+00:00',
                'level' => 'info',
                'logger' => 'test',
                'message' => str_repeat('x', 20000),
                'fields' => ['history.id' => 7, 'identity.user' => 'alice'],
            ], JSON_THROW_ON_ERROR);
        }
        $file = self::$tmpPath . '/task.jsonl';
        $method = new \ReflectionMethod($handler, 'findRelatedLogsWithPhp');

        foreach (['', "\n", "\r\n"] as $ending) {
            file_put_contents($file, implode("\r\n\ninvalid\n", $lines) . $ending);
            clearstatcache(true, $file);
            $result = $method->invoke($handler, [$file], '7', 'alice');
            self::assertSame(['line-3', 'line-2', 'line-1'], array_column(array_column($result, 'entry'), 'id'));
            self::assertSame(str_repeat('x', 20000), $result[0]['entry']['message']);
        }

        file_put_contents($file, '');
        clearstatcache(true, $file);
        self::assertSame([], $method->invoke($handler, [$file], '7', 'alice'));
    }
}
