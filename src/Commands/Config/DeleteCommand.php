<?php

declare(strict_types=1);

namespace App\Commands\Config;

use App\Command;
use App\Libs\Attributes\Route\Cli;
use App\Libs\Config;
use App\Libs\Enums\Http\Status;
use App\Libs\Exceptions\RuntimeException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;

/**
 * Class DeleteCommand
 *
 * This command allows you to delete local backend data from the database.
 *
 * @package App\Command
 */
#[Cli(command: self::ROUTE)]
final class DeleteCommand extends Command
{
    public const string ROUTE = 'config:delete';

    /**
     * Configure the command.
     */
    protected function configure(): void
    {
        $this->setName(self::ROUTE)
            ->setDescription('Delete Local backend data.')
            ->addOption('select-backend', 's', InputOption::VALUE_REQUIRED, 'Select backend.')
            ->setHelp(
                r(
                    <<<HELP

                    This command allows you to delete local backend data from the database.

                    This command require <notice>interaction</notice> to work. to bypass the check use <flag>[-n, --no-interaction]</flag> flag.

                    This command will do the following:

                    1. Remove records metadata that references the given <notice>backend</notice>.
                    2. Run data integrity check to remove no longer used records.
                    2. Update <value>servers.yaml</value> file and remove <notice>backend</notice> configuration.

                    ------------------
                    <notice>WARNING:</notice> This command works on the current active database. it will <error>NOT</error> delete
                    data from the backend itself or the backups stored at [<value>{backupDir}</value>].
                    ------------------

                    HELP,
                    [
                        'backupDir' => after(Config::get('path') . '/backup', ROOT_PATH),
                    ]
                )
            );
    }

    /**
     * Runs the command to remove a backend from the database.
     *
     * @param InputInterface $input The input interface instance for retrieving command input.
     * @param OutputInterface $output The output interface instance for displaying command output.
     *
     * @return int The status code indicating the success or failure of the command execution.
     */
    protected function runCommand(InputInterface $input, OutputInterface $output): int
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

                    {cmd} <cmd>{route}</cmd> -- <value>{backend}</value>

                    ERROR,
                    [
                        'cmd' => trim(commandContext()),
                        'route' => self::ROUTE,
                        'backend' => $input->getArgument('backend'),
                    ]
                )
            );
            return self::FAILURE;
        }

        $name = $input->getOption('select-backend');
        if (empty($name)) {
            $output->writeln(r('<error>ERROR: Backend not specified. Please use [-s, --select-backend].</error>'));
            return self::FAILURE;
        }

        try {
            $this->getBackend($name);
        } catch (RuntimeException $e) {
            $output->writeln('<error>ERROR: ' . $e->getMessage() . '</error>');
            return self::FAILURE;
        }

        $helper = $this->getHelper('question');

        if (!$input->getOption('no-interaction')) {
            $question = new Question(
                r(
                    <<<HELP
                    <question>Are you sure you want to remove '<value>{name}</value>' data?</question>
                    ------------------
                    <notice>WARNING:</notice> This command will remove entries from database related to the backend.
                    Database records will be removed if '<value>{name}</value>' was the only backend referencing them.
                    Otherwise, they will be kept and only reference to the backend will be removed.
                    ------------------
                    <notice>To confirm deletion please write the backend name</notice>

                    HELP. PHP_EOL . '> ',
                    [
                        'name' => $name,
                    ]
                )
            );

            $response = $helper->ask($input, $output, $question);
            $output->writeln('');

            if ($name !== $response) {
                $output->writeln(
                    r("Backend '<value>{backend}</value>' deletion was cancelled. Invalid name was given.", [
                        'backend' => $name
                    ])
                );
                return self::FAILURE;
            }
        }

        $output->writeln(
            r("Deleting '<value>{backend}</value>'. This will take a while. Please wait...", [
                'backend' => $name
            ])
        );

        $response = APIRequest('DELETE', '/backend/' . $name);

        if (Status::OK !== $response->status) {
            $output->writeln(r("<error>API error. {status}: {message}</error>", [
                'key' => $name,
                'status' => $response->status->value,
                'message' => ag($response->body, 'error.message', 'Unknown error.')
            ]));
            return self::FAILURE;
        }

        $message = "<info>Successfully removed '{backend}' data. Deleted '{references}' references and '{records}' records.</info>";
        $output->writeln(r($message, [
            'backend' => $name,
            'references' => ag($response->body, 'deleted.references', 0),
            'records' => ag($response->body, 'deleted.records', 0),
        ]));

        return self::SUCCESS;
    }
}
