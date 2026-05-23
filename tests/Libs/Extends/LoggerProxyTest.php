<?php

declare(strict_types=1);

namespace Tests\Libs\Extends;

use App\Libs\Extends\LoggerProxy;
use App\Libs\TestCase;
use Monolog\Level;

final class LoggerProxyTest extends TestCase
{
    public function test_normalizes_levels(): void
    {
        $records = [];
        $logger = LoggerProxy::create(static function (Level $level, string $message, array $context) use (&$records): void {
            $records[] = [$level, $message, $context];
        });

        $logger->log(Level::Error, 'enum');
        $logger->log('ERROR', 'backend');
        $logger->log('error', 'psr');
        $logger->log(Level::Error->value, 'int');
        $logger->log('unknown', 'fallback');

        self::assertSame(Level::Error, $records[0][0]);
        self::assertSame(Level::Error, $records[1][0]);
        self::assertSame(Level::Error, $records[2][0]);
        self::assertSame(Level::Error, $records[3][0]);
        self::assertSame(Level::Notice, $records[4][0]);
    }
}
