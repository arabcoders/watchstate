<?php

declare(strict_types=1);

namespace App\Libs\Extends;

use App\Libs\Config;
use DateTimeInterface;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Class ConsoleHandler
 *
 * This class is responsible for handling log records and outputting them to the console.
 *
 * @extends AbstractProcessingHandler
 */
class ConsoleHandler extends AbstractProcessingHandler
{
    /**
     * @var OutputInterface|null $output The console output interface to be used for printing log records
     */
    private ?OutputInterface $output;

    /**
     * Maps verbosity levels from the OutputInterface to corresponding log levels.
     *
     * @var array $levelsMapper
     *      The mapping of verbosity levels to log levels.
     *      The verbosity levels are defined in the OutputInterface class and the log levels are defined in the Level class.
     *      The keys of the array are the verbosity levels and the values are the corresponding log levels.
     *      Example:
     *      OutputInterface::VERBOSITY_QUIET => Level::Error,
     *      OutputInterface::VERBOSITY_NORMAL => Level::Warning,
     *      OutputInterface::VERBOSITY_VERBOSE => Level::Notice,
     *      OutputInterface::VERBOSITY_VERY_VERBOSE => Level::Info,
     *      OutputInterface::VERBOSITY_DEBUG => Level::Debug
     */
    private array $levelsMapper = [
        OutputInterface::VERBOSITY_QUIET => Level::Error,
        OutputInterface::VERBOSITY_NORMAL => Level::Warning,
        OutputInterface::VERBOSITY_VERBOSE => Level::Notice,
        OutputInterface::VERBOSITY_VERY_VERBOSE => Level::Info,
        OutputInterface::VERBOSITY_DEBUG => Level::Debug,
    ];

    /**
     * Constructor for the class.
     *
     * @param OutputInterface|null $output The console output for determining verbosity
     * @param bool $bubble Whether the log messages should bubble up the stack or not
     * @param array $levelsMapper (Optional) An array mapping verbosity levels to logging levels
     */
    public function __construct(?OutputInterface $output = null, bool $bubble = true, array $levelsMapper = [])
    {
        parent::__construct(Level::Debug, $bubble);

        $this->output = $output;

        if ($levelsMapper) {
            $this->levelsMapper = $levelsMapper;
        }
    }

    /**
     * Determines if the log record is being handled by the handler.
     *
     * @param LogRecord $record The log record being evaluated
     *
     * @return bool Whether the handler is handling the log record
     */
    public function isHandling(LogRecord $record): bool
    {
        return $this->updateLevel() && parent::isHandling($record);
    }

    /**
     * Handles a log record.
     *
     * This method calls the updateLevel() method to update the logging level
     * based on the verbosity setting of the console output. It then calls the
     * parent's handle() method to handle the log record.
     *
     * @param LogRecord $record The log record to handle
     *
     * @return bool Whether the log record was successfully handled
     */
    public function handle(LogRecord $record): bool
    {
        // we have to update the logging level each time because the verbosity of the
        // console output might have changed in the meantime (it is not immutable)
        return $this->updateLevel() && parent::handle($record);
    }

    /**
     * Sets the output interface to be used by the logger.
     *
     * @param OutputInterface $output The console output interface to be set
     */
    public function setOutput(OutputInterface $output): void
    {
        $this->output = $output;
    }

    /**
     * Writes a log record to the output.
     *
     * @param LogRecord $record The log record to be written
     */
    protected function write(LogRecord $record): void
    {
        $date = $record['datetime'] ?? 'No date set';

        if (true === $date instanceof DateTimeInterface) {
            $date = $date->format(DateTimeInterface::ATOM);
        }

        $message = r('[{date}] {level}: {message}', [
            'date' => $date,
            'level' => $record['level_name'] ?? $record['level'] ?? '??',
            'message' => $record['message'],
        ]);

        if (false === empty($record['context']) && true === (bool) Config::get('logs.context')) {
            $message .= ' ' . array_to_json($record['context']);
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

        $this->setLevel($this->levelsMapper[$verbosity] ?? Level::Debug);

        return true;
    }
}
