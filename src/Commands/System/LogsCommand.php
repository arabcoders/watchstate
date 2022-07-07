<?php

declare(strict_types=1);

namespace App\Commands\System;

use App\Command;
use App\Libs\Config;
use App\Libs\Routable;
use Exception;
use LimitIterator;
use SplFileObject;
use Symfony\Component\Console\Completion\CompletionInput;
use Symfony\Component\Console\Completion\CompletionSuggestions;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

#[Routable(command: self::ROUTE)]
final class LogsCommand extends Command
{
    public const ROUTE = 'system:logs';

    private const LOG_FILES = [
        'app',
        'access',
        'task'
    ];

    public const DEFAULT_LIMIT = 50;

    protected function configure(): void
    {
        $this->setName(self::ROUTE)
            ->addOption(
                'type',
                null,
                InputOption::VALUE_REQUIRED,
                sprintf('Log type, can be [%s].', implode(', ', self::LOG_FILES)),
                self::LOG_FILES[0]
            )
            ->addOption(
                'date',
                null,
                InputOption::VALUE_REQUIRED,
                'Which log date to open. Format is [YYYYMMDD].',
                makeDate()->format('Ymd'),
            )
            ->addOption('list', null, InputOption::VALUE_NONE, 'List All log files.')
            ->addOption(
                'limit',
                'l',
                InputOption::VALUE_OPTIONAL,
                'Show last X number of log lines.',
                self::DEFAULT_LIMIT
            )
            ->addOption('tail', 't', InputOption::VALUE_NONE, 'Tail logfile.')
            ->addOption('clear', null, InputOption::VALUE_NONE, 'Clear log file')
            ->setAliases(['logs'])
            ->setDescription('View current logs.');
    }

    /**
     * @throws Exception
     */
    protected function runCommand(InputInterface $input, OutputInterface $output): int
    {
        if ($input->getOption('list')) {
            return $this->listLogs($input, $output);
        }

        $type = $input->getOption('type');

        if (false === in_array($type, self::LOG_FILES)) {
            throw new \RuntimeException(
                sprintf('Log type has to be one of the supported log files [%s].', implode(', ', self::LOG_FILES))
            );
        }

        $date = $input->getOption('date');

        if (1 !== preg_match('/^\d{8}$/', $date)) {
            throw new \RuntimeException('Log date must be in [YYYYMMDD] format. For example [20220622].');
        }

        $limit = (int)$input->getOption('limit');

        $file = sprintf(Config::get('tmpDir') . '/logs/%s.%s.log', $type, $date);

        if (false === file_exists($file) || filesize($file) < 1) {
            $output->writeln(
                sprintf(
                    '<info>Log file [%s] does not exist or is empty. This means it has been pruned, the date is incorrect or nothing has written to that file yet.</info>',
                    after($file, dirname(Config::get('tmpDir')))
                )
            );
            return self::SUCCESS;
        }

        $file = new SplFileObject($file, 'r');

        if ($input->getOption('clear')) {
            return $this->handleClearLog($file, $input, $output);
        }

        if ($input->getOption('tail')) {
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
                            sprintf('<error>Unable to open file \'%s\'.</error>', $file->getRealPath())
                        );
                        return self::FAILURE;
                    }

                    fseek($f, $lastPos);

                    while (!feof($f)) {
                        $buffer = fread($f, 4096);
                        $output->write((string)$buffer);
                        flush();
                    }

                    $lastPos = ftell($f);

                    fclose($f);
                }

                usleep(500000); //0.5s
            }
        }

        $limit = $limit < 1 ? self::DEFAULT_LIMIT : $limit;

        if ($file->getSize() < 1) {
            $output->writeln(
                sprintf('<comment>File \'%s\' is empty.</comment>', $file->getRealPath())
            );
            return self::SUCCESS;
        }

        $file->seek(PHP_INT_MAX);

        $lastLine = $file->key();

        $it = new LimitIterator($file, max(0, $lastLine - $limit), $lastLine);

        foreach ($it as $line) {
            $line = trim((string)$line);

            if (empty($line)) {
                continue;
            }

            $output->writeln($line);
        }

        return self::SUCCESS;
    }

    private function listLogs(InputInterface $input, OutputInterface $output): int
    {
        $path = fixPath(Config::get('tmpDir') . '/logs');

        $list = [];

        $isTable = $input->getOption('output') === 'table';

        foreach (glob($path . '/*.*.log') as $file) {
            preg_match('/(\w+)\.(\d+)\.log/i', basename($file), $matches);

            $size = filesize($file);

            $builder = [
                'type' => $matches[1],
                'date' => $matches[2],
                'size' => $isTable ? fsize($size) : $size,
                'modified' => makeDate(filemtime($file))->format('Y-m-d H:i:s T'),
            ];

            if (!$isTable) {
                $builder['file'] = $file;
            }

            $list[] = $builder;
        }

        $this->displayContent($list, $output, $input->getOption('output'));

        return self::SUCCESS;
    }

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

        if (function_exists('stream_isatty') && defined('STDERR')) {
            $tty = stream_isatty(STDERR);
        } else {
            $tty = true;
        }

        if (false === $tty || $input->getOption('no-interaction')) {
            $output->writeln('<error>ERROR: This command flag require interaction.</error>');
            $output->writeln(
                '<comment>If you are running this tool inside docker, you have to enable interaction using "-ti" flag</comment>'
            );
            $output->writeln(
                '<comment>For example: docker exec -ti watchstate console backends:manage my_plex</comment>'
            );
            return self::FAILURE;
        }

        $question = new ConfirmationQuestion(
            sprintf(
                'Clear file <info>[%s]</info> contents? <comment>%s</comment>' . PHP_EOL . '> ',
                after($file->getRealPath(), Config::get('tmpDir') . '/'),
                '[Y|N] [Default: No]',
            ),
            false
        );

        $confirmClear = $this->getHelper('question')->ask($input, $output, $question);

        if (true !== (bool)$confirmClear) {
            return self::SUCCESS;
        }

        $file->openFile('w');

        return self::SUCCESS;
    }

    public function complete(CompletionInput $input, CompletionSuggestions $suggestions): void
    {
        parent::complete($input, $suggestions);

        if ($input->mustSuggestOptionValuesFor('type')) {
            $currentValue = $input->getCompletionValue();

            $suggest = [];

            foreach (self::LOG_FILES as $name) {
                if (empty($currentValue) || str_starts_with($name, $currentValue)) {
                    $suggest[] = $name;
                }
            }

            $suggestions->suggestValues($suggest);
        }
    }
}
