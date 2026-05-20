<?php

declare(strict_types=1);

namespace App\Commands\System;

use App\API\Logs\Index as LogsIndex;
use App\Command;
use App\Libs\Attributes\Route\Cli;
use App\Libs\Config;
use App\Libs\Exceptions\InvalidArgumentException;
use LimitIterator;
use SplFileObject;
use Symfony\Component\Console\Completion\CompletionInput;
use Symfony\Component\Console\Completion\CompletionSuggestions;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Yaml\Yaml;
use Throwable;

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

        if (null === ($file = $this->resolveLogFile($type, $date))) {
            $output->writeln(
                sprintf(
                    '<info>Log file [%s] does not exist or is empty. This means it has been pruned, the date is incorrect or nothing has written to that file yet.</info>',
                    r('logs/{type}.{date}.{extension}', [
                        'type' => $type,
                        'date' => $date,
                        'extension' => 'jsonl',
                    ]),
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

                    $buffer = '';
                    while (!feof($f)) {
                        $chunk = fread($f, 4096);
                        if (false === $chunk || '' === $chunk) {
                            continue;
                        }

                        $buffer .= $chunk;
                    }

                    $lines = preg_split('/\R/', $buffer);
                    if (false === $lines) {
                        $lines = [];
                    }

                    foreach ($lines as $line) {
                        $line = trim((string) $line);
                        if ('' === $line) {
                            continue;
                        }

                        $this->renderLogLine($line, $input, $output);
                    }

                    flush();

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

            $this->renderLogLine($line, $input, $output);
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

        foreach (['*.*.jsonl'] as $pattern) {
            $files = glob($path . '/' . $pattern);
            if (false === $files) {
                continue;
            }

            foreach ($files as $file) {
                preg_match('/(\w+)\.(\w+)\.(jsonl)/i', basename($file), $matches);

                $size = filesize($file);

                $builder = [
                    'type' => $matches[1] ?? '??',
                    'tag' => $matches[2] ?? '??',
                    'format' => $matches[3] ?? '??',
                    'size' => $isTable ? fsize($size) : $size,
                    'modified' => make_date(filemtime($file))->format('Y-m-d H:i:s T'),
                ];

                if (!$isTable) {
                    $builder['file'] = $file;
                    $builder['modified'] = make_date(filemtime($file));
                }

                $list[] = $builder;
            }
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

    private function resolveLogFile(string $type, string $date): ?string
    {
        $file = r(text: Config::get('tmpDir') . '/logs/{type}.{date}.jsonl', context: [
            'type' => $type,
            'date' => $date,
        ]);

        return true === file_exists($file) ? $file : null;
    }

    private function renderLogLine(string $line, InputInterface $input, OutputInterface $output): void
    {
        if ($input->hasOption('jsonl') && true === (bool) $input->getOption('jsonl')) {
            $output->writeln($line);
            return;
        }

        if (null !== ($jsonl = $this->parseJsonlLine($line))) {
            if ('' === trim((string) ag($jsonl, 'message', ''))) {
                return;
            }

            $this->writeStructuredEntry($jsonl, $input, $output);
            return;
        }

        $this->writeStructuredEntry(LogsIndex::formatLog($line), $input, $output);
    }

    /**
     * @param array<string,mixed> $entry
     */
    private function writeStructuredEntry(array $entry, InputInterface $input, OutputInterface $output): void
    {
        $mode = $this->getDisplayMode($input);

        if ('json' === $mode) {
            $json = json_encode(
                $entry,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_IGNORE,
            );

            $output->writeln(false === $json ? 'null' : $json);
            return;
        }

        if ('yaml' === $mode) {
            $output->writeln(trim(Yaml::dump(input: $entry, inline: 8, indent: 2)));
            return;
        }

        $output->writeln($this->formatEventLine($entry));
    }

    /**
     * @return array<string,mixed>|null
     */
    private function parseJsonlLine(string $line): ?array
    {
        $payload = json_decode($line, true, 512, JSON_INVALID_UTF8_IGNORE);

        if (!is_array($payload)) {
            return null;
        }

        foreach (['id', 'datetime', 'level', 'message'] as $required) {
            if (false === array_key_exists($required, $payload)) {
                return null;
            }

            if ('message' === $required) {
                continue;
            }

            if ('' === trim((string) $payload[$required])) {
                return null;
            }
        }

        if (!array_key_exists('logger', $payload) && !array_key_exists('channel', $payload)) {
            return null;
        }

        return $payload;
    }

    /**
     * @param array<string,mixed> $entry
     */
    private function formatEventLine(array $entry): string
    {
        $severity = strtoupper((string) ag($entry, 'level', '-'));
        $severityText = OutputFormatter::escape($severity);
        $message = $this->messageText($entry);
        $severityColor = $this->severityColor((string) ag($entry, 'level', ''), $message);

        if (null !== $severityColor) {
            $severityText = sprintf('<fg=%s;options=bold>%s</>', $severityColor, $severityText);
        } else {
            $severityText = sprintf('<options=bold>%s</>', $severityText);
        }

        return sprintf(
            '<comment>%s</comment> <info>%s</info> %s <fg=cyan>%s</> %s',
            OutputFormatter::escape($this->formatTimestamp((string) ag($entry, ['datetime', 'date'], '-'))),
            OutputFormatter::escape($this->hostname($entry)),
            $severityText,
            OutputFormatter::escape((string) ag($entry, ['logger', 'channel'], '-')),
            OutputFormatter::escape($message),
        );
    }

    /**
     * @param array<string,mixed> $entry
     */
    private function hostname(array $entry): string
    {
        $value = ag(
            $entry,
            [
                'fields.structured.request.name',
                'fields.hostname',
                'fields.route.ip',
                'fields.task_id',
                'fields.command',
                'fields.user',
                'fields.backend',
                'fields.cli.stream',
                'source.module',
                'process.name',
                'logger',
                'channel',
            ],
            '-',
        );

        return '' !== trim((string) $value) ? trim((string) $value) : '-';
    }

    /**
     * @param array<string,mixed> $entry
     */
    private function messageText(array $entry): string
    {
        $message = trim((string) ag($entry, ['message', 'text'], '-'));

        if ('' === $message) {
            return '-';
        }

        if (null !== ($exception = ag($entry, 'exception_message')) && '' !== trim((string) $exception)) {
            return $message . ' [' . trim((string) $exception) . ']';
        }

        return $message;
    }

    private function formatTimestamp(string $value): string
    {
        if ('' === trim($value)) {
            return '-';
        }

        try {
            return make_date($value)->format('m/d, H:i:s');
        } catch (Throwable) {
            return $value;
        }
    }

    private function severityColor(string $severity, string $message): ?string
    {
        $value = strtolower($severity . ' ' . $message);

        if (1 === preg_match('/(critical|crit|alert|error|err|fatal|panic|exception|failed)/', $value)) {
            return 'red';
        }

        if (1 === preg_match('/(warning|warn|notice|noti|deprecated)/', $value)) {
            return 'yellow';
        }

        if (1 === preg_match('/(success|started|listening|connected|ready|complete|done)/', $value)) {
            return 'green';
        }

        if (1 === preg_match('/(info|inf|debug|deb|trace)/', $value)) {
            return 'cyan';
        }

        return null;
    }

    private function getDisplayMode(InputInterface $input): string
    {
        $mode = (string) $input->getOption('output');

        return in_array($mode, self::DISPLAY_OUTPUT, true) ? $mode : self::DISPLAY_OUTPUT[0];
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
