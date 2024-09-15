<?php

declare(strict_types=1);

namespace App\Libs;

use Closure;
use Monolog\Handler\TestHandler;
use Throwable;

class TestCase extends \PHPUnit\Framework\TestCase
{
    protected TestHandler|null $handler = null;

    /**
     * Prints logs to the standard output.
     *
     * @return void
     */
    protected function printLogs(): void
    {
        if (null === $this->handler) {
            return;
        }

        $d = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2)[0];
        $getScript = ag($d, 'file');
        $line = ag($d, 'line');

        dump(
            $getScript . ':' . $line,
            array_map(fn($v) => $v['formatted'] ?? $v['message'], $this->handler->getRecords())
        );
    }

    /**
     * Checks if the given closure throws an exception.
     *
     * @param Closure $closure
     * @param string $reason
     * @param Throwable|string $exception Expected exception class
     * @param string $exceptionMessage (optional) Exception message
     * @param int|null $exceptionCode (optional) Exception code
     * @param callable{ TestCase, Throwable}|null $callback (optional) Custom callback to handle the exception
     * @return void
     */
    protected function checkException(
        Closure $closure,
        string $reason,
        Throwable|string $exception,
        string $exceptionMessage = '',
        int|null $exceptionCode = null,
        callable $callback = null,
    ): void {
        $caught = null;
        try {
            $closure();
        } catch (Throwable $e) {
            $caught = $e;
        } finally {
            if (null !== $callback) {
                $callback($this, $caught);
                return;
            }
            if (null === $caught) {
                $this->fail('No exception was thrown. ' . $reason);
            } else {
                $this->assertSame(
                    is_object($exception) ? $exception::class : $exception,
                    is_object($caught) ? $caught::class : $caught,
                    $reason . '.; ' . $caught->getMessage(),
                );
                if (!empty($exceptionMessage)) {
                    $this->assertStringContainsString($exceptionMessage, $caught->getMessage(), $reason);
                }
                if (!empty($exceptionCode)) {
                    $this->assertEquals($exceptionCode, $caught->getCode(), $reason);
                }
            }
        }
    }
}
