<?php

declare(strict_types=1);

namespace App\Commands\System;

use App\Command;
use App\Libs\Attributes\Route\Cli;
use App\Libs\Config;
use App\Libs\Exceptions\InvalidArgumentException;
use LimitIterator;
use SplFileObject;
use Symfony\Component\Console\Completion\CompletionInput;
use Symfony\Component\Console\Completion\CompletionSuggestions;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

/**
 * Class LogsCommand.
 *
 * This class is used to view and clear log files.
 */
#[Cli(command: self::ROUTE)]
final class LogsCommand extends Command
{
    public const string ROUTE = 'system:logs';

    /**
     * @var array Constant array containing names of supported log files.
     */
    private const array LOG_FILES = [
        'app',
        'access',
        'task',
        'webhook',
    ];

    /**
     * @var int The default limit of how many lines to show.
     */
    public const int DEFAULT_LIMIT = 50;

    /**
     * Configure the command.
     */
    protected function configure(): void
    {
        $defaultDate = make_date()->format('Ymd');

        $this
            ->setName(self::ROUTE)
            ->addOption(
                'type',
                't',
                InputOption::VALUE_REQUIRED,
                sprintf('Log type, can be [%s].', implode(', ', self::LOG_FILES)),
                self::LOG_FILES[0],
            )
            ->addOption(
                'date',
                'd',
                InputOption::VALUE_REQUIRED,
                'Which log date to open. Format is [YYYYMMDD].',
                $defaultDate,
            )
            ->addOption('list', null, InputOption::VALUE_NONE, 'List All log files.')
            ->addOption(
                'limit',
                'l',
                InputOption::VALUE_OPTIONAL,
                'Show last X number of log lines.',
                self::DEFAULT_LIMIT,
            )
            ->addOption('follow', 'f', InputOption::VALUE_NONE, 'Follow log file.')
            ->addOption('clear', null, InputOption::VALUE_NONE, 'Clear log file')
            ->setDescription('View current logs.')
            ->setHelp(
                r(
                    <<<HELP

                        This command allow you to access all recorded logs.

                        -------------------
                        <notice>[ Expected Values ]</notice>
                        -------------------

                        <flag>type</flag>  expects the value to be one of [{files}]. [<value>Default: {defaultLog}</value>].
                        <flag>date</flag>  expects the value to be [<value>(number){8}</value>]. [<value>Default: {defaultDate}</value>].
                        <flag>limit</flag> expects the value to be [<value>(number)</value>]. [<value>Default: {defaultLimit}</value>].

                        -------
                        <notice>[ FAQ ]</notice>
                        -------

                        <question># How to see all log files?</question>

                        {cmd} <cmd>{route}</cmd> <flag>--list</flag>

                        <question># How to follow log file?</question>

                        {cmd} <cmd>{route}</cmd> <flag>--follow</flag>

                        <question># How to clear log file?</question>

                        {cmd} <cmd>{route}</cmd> <flag>--type</flag> <value>{defaultLog}</value> <flag>--date</flag> <value>{defaultDate}</value> <flag>--clear</flag>

                        You can clear log file by running this command, However clearing log file require <notice>interaction</notice>.
                        To bypass the check use <flag>[-n, --no-interaction]</flag> flag.

                        <question># How to increase/decrease the returned log lines?</question>

                        By default, we return the last [<value>{defaultLimit}</value>] log lines. However, you can increase/decrease
                        the limit however you like by using [<flag>-l, --limit</flag>] flag. For example,

                        {cmd} <cmd>{route}</cmd> <flag>--limit</flag> <value>100</value>

                        <question># Where log files stored?</question>

                        By default, We store logs at [<value>{logsPath}</value>]

                        HELP,
                    [
                        'files' => implode(
                            ', ',
                            array_map(static fn($val) => '<value>' . $val . '</value>', self::LOG_FILES),
                        ),
                        'defaultLog' => self::LOG_FILES[0],
                        'defaultDate' => $defaultDate,
                        'defaultLimit' => self::DEFAULT_LIMIT,
                        'cmd' => trim(command_context()),
                        'route' => self::ROUTE,
                        'logsPath' => Config::get('tmpDir') . '/logs',
                    ],
                ),
            );
    }

    /**
     * Run the command.
     *
     * @param InputInterface $input The input interface.
     * @param OutputInterface $output The output interface.
     *
     * @return int The exit code of the command.
     *
     * @throws InvalidArgumentException If the log type is not one of the supported log files.
     * @throws InvalidArgumentException If the log date is not in the correct format.
     */
    protected function runCommand(InputInterface $input, OutputInterface $output): int
    {
        if ($input->getOption('list')) {
            return $this->listLogs($input, $output);
        }

        $type = $input->getOption('type');

        if (false === in_array($type, self::LOG_FILES, true)) {
            throw new InvalidArgumentException(
                sprintf('Log type has to be one of the supported log files [%s].', implode(', ', self::LOG_FILES)),
            );
        }

        $date = $input->getOption('date');

        if (1 !== preg_match('/^\d{8}$/', $date)) {
            throw new InvalidArgumentException('Log date must be in [YYYYMMDD] format. For example [20220622].');
        }

        $limit = (int) $input->getOption('limit');

        $file = r(text: Config::get('tmpDir') . '/logs/{type}.{date}.log', context: [
            'type' => $type,
            'date' => $date,
        ]);

        if (false === file_exists($file)) {
            $output->writeln(
                sprintf(
                    '<info>Log file [%s] does not exist or is empty. This means it has been pruned, the date is incorrect or nothing has written to that file yet.</info>',
                    after($file, dirname(Config::get('tmpDir'))),
                ),
            );
            return self::SUCCESS;
        }

        $file = new SplFileObject($file, 'r');

        if ($input->getOption('clear')) {
            return $this->handleClearLog($file, $input, $output);
        }

        if ($input->getOption('follow')) {
            $p = $file->getRealPath();
            $lastPos = 0;

            while (true) {
                clearstatcache(false, $p);
                $len = filesize($p);
                if ($len < $lastPos) {
                    //-- file deleted or reset
                    $lastPos = $len;
                } elseif ($len > $lastPos) {
                    if (false === ($f = fopen($p, 'rb'))) {
                        $output->writeln(
                            sprintf('<error>Unable to open file \'%s\'.</error>', $file->getRealPath()),
                        );
                        return self::FAILURE;
                    }

                    fseek($f, $lastPos);

                    while (!feof($f)) {
                        $buffer = fread($f, 4096);
                        $output->write((string) $buffer);
                        flush();
                    }

                    $lastPos = ftell($f);

                    fclose($f);
                }

                usleep(500_000); //0.5s
            }
        }

        $limit = $limit < 1 ? self::DEFAULT_LIMIT : $limit;

        if ($file->getSize() < 1) {
            $output->writeln(
                sprintf('<comment>File \'%s\' is empty.</comment>', $file->getRealPath()),
            );
            return self::SUCCESS;
        }

        $file->seek(PHP_INT_MAX);

        $lastLine = $file->key();

        $it = new LimitIterator($file, max(0, $lastLine - $limit), $lastLine);

        foreach ($it as $line) {
            $line = trim((string) $line);

            if (empty($line)) {
                continue;
            }

            $output->writeln($line);
        }

        return self::SUCCESS;
    }

    /**
     * Lists the logs.
     *
     * @param InputInterface $input The input interface.
     * @param OutputInterface $output The output interface.
     *
     * @return int The exit code.
     */
    private function listLogs(InputInterface $input, OutputInterface $output): int
    {
        $path = fix_path(Config::get('tmpDir') . '/logs');

        $list = [];

        $isTable = $input->getOption('output') === 'table';

        foreach (glob($path . '/*.*.log') as $file) {
            preg_match('/(\w+)\.(\w+)\.log/i', basename($file), $matches);

            $size = filesize($file);

            $builder = [
                'type' => $matches[1] ?? '??',
                'tag' => $matches[2] ?? '??',
                'size' => $isTable ? fsize($size) : $size,
                'modified' => make_date(filemtime($file))->format('Y-m-d H:i:s T'),
            ];

            if (!$isTable) {
                $builder['file'] = $file;
                $builder['modified'] = make_date(filemtime($file));
            }

            $list[] = $builder;
        }

        $this->displayContent($list, $output, $input->getOption('output'));

        return self::SUCCESS;
    }

    /**
     * Clears the contents of a log file.
     *
     * @param SplFileObject $file The log file object.
     * @param InputInterface $input The input interface.
     * @param OutputInterface $output The output interface.
     *
     * @return int The exit code.
     */
    private function handleClearLog(SplFileObject $file, InputInterface $input, OutputInterface $output): int
    {
        $logfile = after($file->getRealPath(), Config::get('tmpDir') . '/');

        if ($file->getSize() < 1) {
            $output->writeln(sprintf('<comment>Logfile [%s] is already empty.</comment>', $logfile));
            return self::SUCCESS;
        }

        if (false === $file->isWritable()) {
            $output->writeln(sprintf('<comment>Unable to write to logfile [%s].</comment>', $logfile));
            return self::FAILURE;
        }

        if (false === $input->getOption('no-interaction')) {
            if (function_exists('stream_isatty') && defined('STDERR')) {
                $tty = stream_isatty(STDERR);
            } else {
                $tty = true;
            }

            if (false === $tty) {
                $output->writeln('<error>ERROR: no interactive session found.</error>');
                $output->writeln(
                    '<comment>to clear log without interactive session, pass</comment> [<flag>-n, --no-interaction</flag>] flag.',
                );
                return self::FAILURE;
            }

            $question = new ConfirmationQuestion(
                sprintf(
                    'Clear file <info>[%s]</info> contents? <comment>%s</comment>' . PHP_EOL . '> ',
                    after($file->getRealPath(), Config::get('tmpDir') . '/'),
                    '[Y|N] [Default: No]',
                ),
                false,
            );

            $confirmClear = $this->getHelper('question')->ask($input, $output, $question);

            if (true !== (bool) $confirmClear) {
                return self::SUCCESS;
            }
        }

        $file->openFile('w');

        return self::SUCCESS;
    }

    /**
     * Complete the suggestions for the given input.
     *
     * @param CompletionInput $input The completion input.
     * @param CompletionSuggestions $suggestions The completion suggestions.
     */
    public function complete(CompletionInput $input, CompletionSuggestions $suggestions): void
    {
        parent::complete($input, $suggestions);

        if ($input->mustSuggestOptionValuesFor('type')) {
            $currentValue = $input->getCompletionValue();

            $suggest = [];

            foreach (self::LOG_FILES as $name) {
                if (!(empty($currentValue) || str_starts_with($name, $currentValue))) {
                    continue;
                }

                $suggest[] = $name;
            }

            $suggestions->suggestValues($suggest);
        }
    }

    /**
     * Retrieve the types of log files.
     *
     * @return array The array of available log file types.
     */
    public static function getTypes(): array
    {
        return self::LOG_FILES;
    }
}
