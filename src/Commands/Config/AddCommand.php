<?php

declare(strict_types=1);

namespace App\Commands\Config;

use App\Command;
use App\Libs\Attributes\Route\Cli;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;

/**
 * Class AddCommand.
 *
 * This command allow you to add new backend. This command is mainly proxy to config:manage command.
 * And act as shortcut for running the following command:
 * config:manage --add -s backend_name
 */
#[Cli(command: self::ROUTE)]
final class AddCommand extends Command
{
    public const ROUTE = 'config:add';

    /**
     * Configures the command.
     */
    protected function configure(): void
    {
        $this->setName(self::ROUTE)
            ->setDescription('Add new backend.')
            ->addOption('select-backend', 's', InputOption::VALUE_REQUIRED, 'Select backend.')
            ->setHelp(
                r(
                    <<<HELP

                    This command allow you to add new backend.
                    This command require <notice>interaction</notice> to work.

                    This command is shortcut for running the following command:

                    {cmd} <cmd>{manage_route}</cmd> <flag>--add -s</flag> <value>backend_name</value>

                    HELP,
                    [
                        'cmd' => trim(commandContext()),
                        'route' => self::ROUTE,
                        'manage_route' => ManageCommand::ROUTE,
                    ]
                )
            );
    }

    /**
     * Executes the command.
     *
     * @param InputInterface $input The input object.
     * @param OutputInterface $output The output object.
     *
     * @return int The exit code.
     * @throws \Symfony\Component\Console\Exception\ExceptionInterface If an error occurs during command execution.
     */
    protected function runCommand(InputInterface $input, OutputInterface $output): int
    {
        if (function_exists('stream_isatty') && defined('STDERR')) {
            $tty = stream_isatty(STDERR);
        } else {
            $tty = true;
        }

        if (false === $tty || $input->getOption('no-interaction')) {
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

        $opts = [
            '--add' => true,
        ];

        foreach ($input->getOptions() as $option => $val) {
            if (null === $val) {
                continue;
            }
            $opts['--' . $option] = $val;
        }

        $name = $input->getOption('select-backend');

        if (empty($name)) {
            // -- $backend.token
            $name = (function () use (&$opts, $input, $output) {
                $chosen = ag($opts, 'backend');

                $question = new Question(
                    <<<HELP
                    <question>What should we be calling this <value>backend</value>?</question>
                    ------------------
                    Backend name is used to identify the backend. The backend name must only contains
                    <value>lower case a-z</value>, <value>0-9</value> and <value>_</value>.
                    ------------------
                    <notice>Choose good name to identify your backend. For example, <value>home_plex</value>.</notice>
                    HELP. PHP_EOL . '> ',
                    $chosen
                );

                $question->setValidator(function ($answer) {
                    if (empty($answer)) {
                        throw new \RuntimeException('Backend Name cannot be empty.');
                    }
                    if (!isValidName($answer) || strtolower($answer) !== $answer) {
                        throw new \RuntimeException(
                            r(
                                '<error>ERROR:</error> Invalid [<value>{name}</value>] name was given. Only [<value>a-z, 0-9, _</value>] are allowed.',
                                [
                                    'name' => $answer
                                ],
                            )
                        );
                    }
                    return $answer;
                });

                return (new QuestionHelper())->ask($input, $output, $question);
            })();
        }

        $opts['--select-backend'] = strtolower($name);

        return $this->getApplication()?->find(ManageCommand::ROUTE)->run(new ArrayInput($opts), $output) ?? 1;
    }
}
