<?php

namespace App\Libs\Extends;

use App\Libs\Config;
use DateTimeInterface;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Logger;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ConsoleHandler extends AbstractProcessingHandler
{
    private OutputInterface|null $output = null;

    private array $levelsMapper = [
        OutputInterface::VERBOSITY_QUIET => Logger::ERROR,
        OutputInterface::VERBOSITY_NORMAL => Logger::WARNING,
        OutputInterface::VERBOSITY_VERBOSE => Logger::NOTICE,
        OutputInterface::VERBOSITY_VERY_VERBOSE => Logger::INFO,
        OutputInterface::VERBOSITY_DEBUG => Logger::DEBUG,
    ];

    public function __construct(OutputInterface|null $output = null, bool $bubble = true, array $levelsMapper = [])
    {
        parent::__construct(Logger::DEBUG, $bubble);

        $this->output = $output;

        if ($levelsMapper) {
            $this->levelsMapper = $levelsMapper;
        }
    }

    public function isHandling(array $record): bool
    {
        return $this->updateLevel() && parent::isHandling($record);
    }

    public function handle(array $record): bool
    {
        // we have to update the logging level each time because the verbosity of the
        // console output might have changed in the meantime (it is not immutable)
        return $this->updateLevel() && parent::handle($record);
    }

    /**
     * Sets the console output to use for printing logs.
     */
    public function setOutput(OutputInterface $output): void
    {
        $this->output = $output;
    }

    protected function write(array $record): void
    {
        $date = $record['datetime'] ?? 'No date set';

        if (true === ($date instanceof DateTimeInterface)) {
            $date = $date->format(DateTimeInterface::ATOM);
        }

        $message = sprintf(
            '[%s] %s: %s',
            $date,
            $record['level_name'] ?? $record['level'] ?? '??',
            $record['message'],
        );

        if (false === empty($record['context']) && true === (bool)Config::get('logs.context')) {
            $message .= ' { ' . arrayToString($record['context']) . ' }';
        }

        $errOutput = $this->output instanceof ConsoleOutputInterface ? $this->output->getErrorOutput() : $this->output;

        $errOutput?->writeln($message, $this->output->getVerbosity());
    }

    /**
     * Updates the logging level based on the verbosity setting of the console output.
     *
     * @return bool Whether the handler is enabled and verbosity is not set to quiet
     */
    private function updateLevel(): bool
    {
        if (null === $this->output) {
            return false;
        }

        $verbosity = $this->output->getVerbosity();

        if (isset($this->levelsMapper[$verbosity])) {
            $this->setLevel($this->levelsMapper[$verbosity]);
        } else {
            $this->setLevel(Logger::DEBUG);
        }

        return true;
    }
}
