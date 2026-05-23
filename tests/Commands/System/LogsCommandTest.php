<?php

declare(strict_types=1);

namespace Tests\Commands\System;

use App\Cli;
use App\Commands\System\LogsCommand;
use App\Libs\Extends\PSRContainer;
use App\Libs\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class LogsCommandTest extends TestCase
{
    public function test_jsonl(): void
    {
        $this->initTempApp();

        $date = make_date()->format('Ymd');
        $logPath = self::$tmpPath . '/logs/app.' . $date . '.jsonl';
        mkdir(dirname($logPath), 0o755, true);

        $line = json_encode([
            'id' => 'log-id',
            'datetime' => '2026-05-20T12:00:00.123+00:00',
            'level' => 'notice',
            'levelno' => LOG_NOTICE,
            'logger' => 'app',
            'message' => "Importing 'main'.",
            'source' => ['module' => 'app'],
            'process' => ['id' => 1, 'name' => 'cli'],
            'fields' => ['user' => 'main'],
        ], JSON_THROW_ON_ERROR);

        file_put_contents($logPath, $line . PHP_EOL);

        $tester = $this->makeTester();
        $status = $tester->execute([
            '--type' => 'app',
            '--date' => $date,
            '--limit' => 1,
        ]);

        self::assertSame(LogsCommand::SUCCESS, $status);
        self::assertStringContainsString(
            make_date('2026-05-20T12:00:00.123+00:00')->format('m/d, H:i:s') . " main NOTICE app Importing 'main'.",
            $tester->getDisplay(),
        );
        self::assertStringNotContainsString($line, $tester->getDisplay());
    }

    public function test_json_output(): void
    {
        $this->initTempApp();

        $date = make_date()->format('Ymd');
        $logPath = self::$tmpPath . '/logs/app.' . $date . '.jsonl';
        mkdir(dirname($logPath), 0o755, true);

        $line = json_encode([
            'id' => 'log-id',
            'datetime' => '2026-05-20T12:00:00.123+00:00',
            'level' => 'notice',
            'levelno' => LOG_NOTICE,
            'logger' => 'app',
            'message' => "Importing 'main'.",
            'source' => ['module' => 'app'],
            'process' => ['id' => 1, 'name' => 'cli'],
            'fields' => ['user' => 'main'],
        ], JSON_THROW_ON_ERROR);

        file_put_contents($logPath, $line . PHP_EOL);

        $tester = $this->makeTester();
        $status = $tester->execute([
            '--type' => 'app',
            '--date' => $date,
            '--limit' => 1,
            '--output' => 'json',
        ]);

        self::assertSame(LogsCommand::SUCCESS, $status);
        self::assertStringContainsString('"logger": "app"', $tester->getDisplay());
        self::assertStringContainsString('"message": "Importing \'main\'."', $tester->getDisplay());
        self::assertStringNotContainsString(
            make_date('2026-05-20T12:00:00.123+00:00')->format('m/d, H:i:s') . " main NOTICE app Importing 'main'.",
            $tester->getDisplay(),
        );
    }

    public function test_jsonl_flag(): void
    {
        $this->initTempApp();

        $date = make_date()->format('Ymd');
        $logPath = self::$tmpPath . '/logs/app.' . $date . '.jsonl';
        mkdir(dirname($logPath), 0o755, true);

        $line = json_encode([
            'id' => 'log-id',
            'datetime' => '2026-05-20T12:00:00.123+00:00',
            'level' => 'notice',
            'levelno' => LOG_NOTICE,
            'logger' => 'app',
            'message' => "Importing 'main'.",
            'source' => ['module' => 'app'],
            'process' => ['id' => 1, 'name' => 'cli'],
            'fields' => ['user' => 'main'],
        ], JSON_THROW_ON_ERROR);

        file_put_contents($logPath, $line . PHP_EOL);

        $tester = $this->makeTester();
        $status = $tester->execute([
            '--type' => 'app',
            '--date' => $date,
            '--limit' => 1,
            '--jsonl' => true,
        ]);

        self::assertSame(LogsCommand::SUCCESS, $status);
        self::assertStringContainsString($line, $tester->getDisplay());
    }

    public function test_empty_jsonl_message_skipped(): void
    {
        $this->initTempApp();

        $date = make_date()->format('Ymd');
        $logPath = self::$tmpPath . '/logs/task.' . $date . '.jsonl';
        mkdir(dirname($logPath), 0o755, true);

        $lines = [
            json_encode([
                'id' => 'empty-log-id',
                'datetime' => '2026-05-20T12:00:00.123+00:00',
                'level' => 'info',
                'levelno' => LOG_INFO,
                'logger' => 'task',
                'message' => '',
                'source' => ['module' => 'task'],
                'process' => ['id' => 1, 'name' => 'cli'],
                'fields' => ['task_id' => 'indexes'],
            ], JSON_THROW_ON_ERROR),
            json_encode([
                'id' => 'log-id',
                'datetime' => '2026-05-20T12:00:01.123+00:00',
                'level' => 'info',
                'levelno' => LOG_INFO,
                'logger' => 'task',
                'message' => 'Task finished.',
                'source' => ['module' => 'task'],
                'process' => ['id' => 1, 'name' => 'cli'],
                'fields' => ['task_id' => 'indexes'],
            ], JSON_THROW_ON_ERROR),
        ];

        file_put_contents($logPath, implode(PHP_EOL, $lines) . PHP_EOL);

        $tester = $this->makeTester();
        $status = $tester->execute([
            '--type' => 'task',
            '--date' => $date,
            '--limit' => 2,
        ]);

        self::assertSame(LogsCommand::SUCCESS, $status);

        $outputLines = array_values(array_filter(explode(PHP_EOL, trim($tester->getDisplay()))));

        self::assertCount(1, $outputLines);
        self::assertStringContainsString('Task finished.', $outputLines[0]);
    }

    private function makeTester(): CommandTester
    {
        $application = new Cli(new PSRContainer());
        $application->addCommand(new LogsCommand());

        return new CommandTester($application->find(LogsCommand::ROUTE));
    }
}
