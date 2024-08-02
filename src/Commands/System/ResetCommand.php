<?php

declare(strict_types=1);

namespace App\Commands\System;

use App\Command;
use App\Libs\Attributes\Route\Cli;
use App\Libs\Enums\Http\Status;
use Symfony\Component\Console\Input\InputInterface as iInput;
use Symfony\Component\Console\Output\OutputInterface as iOutput;
use Symfony\Component\Console\Question\Question;

/**
 * Class SuppressCommand
 *
 * This command manage the Log Suppressor.
 */
#[Cli(command: self::ROUTE)]
final class ResetCommand extends Command
{
    public const string ROUTE = 'system:reset';

    /**
     * Configure the command.
     */
    protected function configure(): void
    {
        $this->setName(self::ROUTE)
            ->setDescription('Reset the system state.')
            ->setHelp(
                <<<HELP
                Reset the system history state.

                This command will do the following:

                1. Reset the local database.
                2. Attempt to flush the cache.
                3. Reset the last sync time for all backends.

                HELP,
            );
    }

    /**
     * Make sure the command is not running in parallel.
     *
     * @param iInput $input The input object containing the command data.
     * @param iOutput $output The output object for displaying command output.
     *
     * @return int The exit code of the command execution.
     */
    protected function runCommand(iInput $input, iOutput $output): int
    {
        return $this->single(fn(): int => $this->process($input, $output), $output);
    }

    private function process(iInput $input, iOutput $output): int
    {
        if (function_exists('stream_isatty') && defined('STDERR')) {
            $tty = stream_isatty(STDERR);
        } else {
            $tty = true;
        }

        if (false === $tty && !$input->getOption('no-interaction')) {
            $output->writeln(
                r(
                    <<<ERROR

                    <error>ERROR:</error> This command require <notice>interaction</notice>. For example:

                    {cmd} <cmd>{route}</cmd>

                    ERROR,
                    [
                        'cmd' => trim(commandContext()),
                        'route' => self::ROUTE,
                    ]
                )
            );
            return self::FAILURE;
        }

        $helper = $this->getHelper('question');

        if (!$input->getOption('no-interaction')) {
            try {
                $random = bin2hex(random_bytes(4));
            } catch (\Throwable) {
                $random = substr(md5(uniqid((string)mt_rand(), true)), 0, 8);
            }
            $question = new Question(
                r(
                    <<<HELP
                    <question>Are you sure you want to reset WatchState?</question>
                    ------------------
                    <notice>WARNING:</notice> This command will completely reset your WatchState local database.
                    ------------------
                    <notice>To confirm deletion please write '<value>{random}</value>' in the box below</notice>

                    HELP. PHP_EOL . '> ',
                    [
                        'random' => $random
                    ]
                )
            );

            $response = $helper->ask($input, $output, $question);
            $output->writeln('');

            if ($random !== $response) {
                $output->writeln(
                    r(
                        "<question>Reset has failed. Incorrect value provided '<value>{response}</value>' vs '<value>{random}</value>'.</question>",
                        [
                            'random' => $random,
                            'response' => $response
                        ]
                    )
                );
                return self::FAILURE;
            }
        }

        $output->writeln(
            r("<info>Requesting The API to reset the local state... Please wait, it will take a while.</info>")
        );

        $response = APIRequest('DELETE', '/system/reset');
        if (Status::HTTP_OK !== $response->status) {
            $output->writeln(r("<error>API error. {status}: {message}</error>", [
                'status' => $response->status->value,
                'message' => ag($response->body, 'error.message', 'Unknown error.')
            ]));
            return self::FAILURE;
        }

        $output->writeln(r("<info>Local database has been successfully reset.</info>"));
        return self::SUCCESS;
    }
}
