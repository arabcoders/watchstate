<?php

declare(strict_types=1);

namespace Tests\Commands\System;

use App\Commands\System\CounterCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class CounterCommandTest extends TestCase
{
    public function test_counts(): void
    {
        $tester = new CommandTester(new CounterCommand());

        self::assertSame(Command::SUCCESS, $tester->execute(['target' => '1']));
        self::assertSame('[1/1] Counter tick.' . PHP_EOL, $tester->getDisplay());
    }

    public function test_rejects_target(): void
    {
        $tester = new CommandTester(new CounterCommand());

        self::assertSame(Command::FAILURE, $tester->execute(['target' => '0']));
        self::assertStringContainsString('Target must be a positive integer.', $tester->getDisplay());
    }
}
