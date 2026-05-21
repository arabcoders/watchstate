<?php

declare(strict_types=1);

namespace Tests\Commands\System;

use App\Commands\System\BenchmarkDirectMapperCommand;
use App\Libs\TestCase;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Symfony\Component\Console\Input\InputInterface as iInput;

final class BenchmarkDirectMapperCommandTest extends TestCase
{
    public function test_logs_report_created(): void
    {
        $this->initTempDir();

        $handler = new TestHandler();
        $logger = new Logger('test', [$handler]);
        $command = new BenchmarkDirectMapperCommand($this->createStub(\App\Libs\Mappers\ImportInterface::class), $logger);
        $path = \Closure::bind(
            static fn(BenchmarkDirectMapperCommand $command, array $report, array $lines, string $benchDir, float $startedAt): ?string =>
                $command->storeReport($report, $lines, $benchDir, $startedAt),
            null,
            BenchmarkDirectMapperCommand::class,
        )(
            $command,
            ['desc' => 'baseline'],
            ['line 1'],
            self::$tmpPath . '/benchmarks',
            microtime(true) - 0.5,
        );

        self::assertIsString($path);
        self::assertFileExists($path);

        $records = $handler->getRecords();
        self::assertSame('benchmark.report.created', $records[0]->context['event_name']);
        self::assertSame($path, $records[0]->context['path']);
    }

    public function test_logs_compare_missing_baseline(): void
    {
        $handler = new TestHandler();
        $logger = new Logger('test', [$handler]);
        $command = new BenchmarkDirectMapperCommand($this->createStub(\App\Libs\Mappers\ImportInterface::class), $logger);
        $input = $this->createMock(iInput::class);
        $input->expects($this->once())->method('getOption')->with('compare')->willReturn('/missing/baseline.txt');

        self::assertNull(\Closure::bind(
            static fn(BenchmarkDirectMapperCommand $command, iInput $input, string $benchDir): ?string =>
                $command->resolveComparePath($input, $benchDir),
            null,
            BenchmarkDirectMapperCommand::class,
        )($command, $input, '/unused'));

        $records = $handler->getRecords();
        self::assertSame('benchmark.compare.failed', $records[0]->context['event_name']);
        self::assertSame('baseline_not_found', $records[0]->context['reason']);
    }
}
