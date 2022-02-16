<?php

declare(strict_types=1);

namespace App\Libs\Extends;

use Monolog\Logger;
use Psr\Log\LoggerInterface;
use Stringable;
use Symfony\Component\Console\Output\OutputInterface;

final class CliLogger implements LoggerInterface
{
    private array $levelMapper = [
        Logger::DEBUG => OutputInterface::VERBOSITY_DEBUG,
        Logger::INFO => OutputInterface::VERBOSITY_VERY_VERBOSE,
        Logger::NOTICE => OutputInterface::VERBOSITY_VERBOSE,
        Logger::WARNING => OutputInterface::VERBOSITY_NORMAL,
        Logger::ERROR => OutputInterface::VERBOSITY_QUIET,
        Logger::CRITICAL => OutputInterface::VERBOSITY_QUIET,
        Logger::ALERT => OutputInterface::VERBOSITY_QUIET,
        Logger::EMERGENCY => OutputInterface::VERBOSITY_QUIET,
    ];

    public function __construct(public OutputInterface $output, public bool $debug = false)
    {
    }

    public function emergency(Stringable|string $message, array $context = []): void
    {
        $this->log(Logger::EMERGENCY, $message, $context);
    }

    public function alert(Stringable|string $message, array $context = []): void
    {
        $this->log(Logger::ALERT, $message, $context);
    }

    public function critical(Stringable|string $message, array $context = []): void
    {
        $this->log(Logger::CRITICAL, $message, $context);
    }

    public function error(Stringable|string $message, array $context = []): void
    {
        $this->log(Logger::ERROR, $message, $context);
    }

    public function warning(Stringable|string $message, array $context = []): void
    {
        $this->log(Logger::WARNING, $message, $context);
    }

    public function notice(Stringable|string $message, array $context = []): void
    {
        $this->log(Logger::NOTICE, $message, $context);
    }

    public function info(Stringable|string $message, array $context = []): void
    {
        $this->log(Logger::INFO, $message, $context);
    }

    public function debug(Stringable|string $message, array $context = []): void
    {
        $this->log(Logger::DEBUG, $message, $context);
    }

    public function log($level, Stringable|string $message, array $context = []): void
    {
        $debug = '';

        if ($this->debug) {
            $debug = '[M: ' . fsize(memory_get_usage() - BASE_MEMORY) . '] ';
        }

        $levels = array_flip(Logger::getLevels());

        $message = '[' . makeDate() . '] logger.' . ($levels[$level] ?? $level) . ': ' . $debug . $message;

        if (!empty($context)) {
            $list = [];

            foreach ($context as $key => $val) {
                $val = (is_array($val) ? json_encode($val, flags: JSON_UNESCAPED_SLASHES) : ($val ?? 'None'));
                $list[] = sprintf("(%s: %s)", $key, $val);
            }

            $message .= ' [' . implode(', ', $list) . ']';
        }

        $this->output->writeln($message, $this->levelMapper[$level] ?? OutputInterface::VERBOSITY_NORMAL);
    }
}
