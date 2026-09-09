<?php

declare(strict_types=1);

namespace Tests\Commands\System;

use App\Commands\System\ReportCommand;
use App\Libs\ReportGenerator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

final class ReportCommandTest extends TestCase
{
    public function test_no_logs(): void
    {
        $sections = ['system', 'backends', 'suppression', 'tasks'];
        $generator = $this->createMock(ReportGenerator::class);
        $generator
            ->expects(self::once())
            ->method('generate')
            ->with(10, false, $sections)
            ->willReturn([
                'generated_at' => '2026-09-09T00:00:00+00:00',
                'system' => [],
                'users' => ['main'],
                'backends' => [[
                    'name' => 'test_jellyfin',
                    'user' => 'main',
                    'type' => 'jellyfin',
                    'version' => '1.2.3',
                ]],
                'suppression' => [],
                'tasks' => [],
                'logs' => [['type' => 'app', 'entries' => [['message' => 'must not render']]]],
            ]);

        $application = new Application();
        $application->addCommand(new ReportCommand($generator));
        $tester = new CommandTester($application->find(ReportCommand::ROUTE));
        $status = $tester->execute(['--no-logs' => true]);

        self::assertSame(ReportCommand::SUCCESS, $status);
        self::assertStringContainsString('Jellyfin (1.2.3) ==> main@test_jellyfin', $tester->getDisplay());
        self::assertStringNotContainsString('[ Logs ]', $tester->getDisplay());
        self::assertStringNotContainsString('must not render', $tester->getDisplay());
    }
}
