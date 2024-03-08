<?php

declare(strict_types=1);

namespace App\Commands\Backend\Library;

use App\Command;
use App\Commands\Config\EditCommand;
use App\Libs\Attributes\Route\Cli;
use App\Libs\Config;
use App\Libs\ConfigFile;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

/**
 * Class IgnoreCommand
 *
 * This class represents a command for managing ignored libraries in the Backend.
 */
#[Cli(command: self::ROUTE)]
final class IgnoreCommand extends Command
{
    public const ROUTE = 'backend:library:ignore';

    public function __construct(private LoggerInterface $logger)
    {
        parent::__construct();
    }

    /**
     * Configure the command.
     */
    protected function configure(): void
    {
        $this->setName(self::ROUTE)
            ->setDescription('Manage Backend ignored libraries.')
            ->addOption('select-backend', 's', InputOption::VALUE_REQUIRED, 'Select backend.')
            ->setHelp(
                r(
                    <<<HELP

                    This command help manage your ignored libraries list.
                    This command require interaction to select the library that you want to ignore.

                    -------
                    <notice>[ FAQ ]</notice>
                    -------

                    <question># I can't use interaction is there different way to ignore library?</question>

                    Yes, First get your libraries ids by running this command:

                    {cmd} <cmd>{library_list}</cmd> <flag>-s</flag> <value>backend_name</value>

                    You are mainly interested in the <notice>Id</notice> column, once you have list of your ids,
                    you can run the following command to update the backend ignorelist.

                    {cmd} <cmd>{backend_edit}</cmd> <flag>--key</flag> '<value>options.ignore</value>' <flag>--set</flag> '<value>id1</value>,<value>id2</value>,<value>id3</value>' <flag>-s</flag> <value>backend_name</value>

                    The [<value>options.ignore</value>] key accept comma seperated list of ids.

                    HELP,
                    [
                        'cmd' => trim(commandContext()),
                        'library_list' => ListCommand::ROUTE,
                        'backend_edit' => EditCommand::ROUTE,
                        'configPath' => Config::get('path') . '/config/servers.yaml',
                    ]
                )
            );
    }

    /**
     * Run the command.
     *
     * @param InputInterface $input The input interface.
     * @param OutputInterface $output The output interface.
     * @param null|array $rerun The rerun option.
     *
     * @return int The command status code.
     */
    protected function runCommand(InputInterface $input, OutputInterface $output, null|array $rerun = null): int
    {
        $name = $input->getOption('select-backend');

        if (empty($name)) {
            $output->writeln(r('<error>ERROR: Backend not specified. Please use [-s, --select-backend].</error>'));
            return self::FAILURE;
        }

        try {
            $backend = $this->getBackend($name);
        } catch (RuntimeException) {
            $output->writeln(r("<error>ERROR: Backend '{backend}' not found.</error>", ['backend' => $name]));
            return self::FAILURE;
        }

        $list = $backend->listLibraries();

        if (empty($list)) {
            $output->writeln(r('<error>ERROR: Could not find any library for [{backend}].</error>', [
                'backend' => $name
            ]));
            return self::FAILURE;
        }

        $helper = $this->getHelper('question');

        $newList = [];

        foreach ($list as $library) {
            $yes = false;

            if (false === ag($library, 'supported') || true === ag($library, 'ignored')) {
                $yes = true;
            }

            $text = r('Ignore [<info>{library}</info>] - [Type: <comment>{type}</comment>] ? <comment>%s</comment>', [
                'library' => ag($library, 'title'),
                'type' => ag($library, 'type'),
            ]);

            $question = new ConfirmationQuestion(
                sprintf(
                    $text . PHP_EOL . '> ',
                    '[Y|N] [Default: ' . ($yes ? 'Yes' : 'No') . ']',
                ),
                $yes
            );

            if (true === (bool)$helper->ask($input, $output, $question)) {
                $newList[ag($library, 'id')] = ag($library, 'title');
            }
        }

        $confirmText = implode(PHP_EOL, array_map(fn($val) => '[<info>✓</info>] ' . $val, $newList));

        if (empty($confirmText)) {
            $confirmText = '[<comment>-</comment>] Non selected';
        }

        $text = r(
            <<<TEXT
Confirm Ignored list selection <comment>[Y|N] [Default: No]</comment>
-------------------
{list}
-------------------
TEXT,
            [
                'list' => $confirmText,
            ]
        );

        $question = new ConfirmationQuestion($text . PHP_EOL . '> ', false);

        if (false === (bool)$helper->ask($input, $output, $question)) {
            $output->writeln('<comment>Nothing updated. Confirmation failed.</comment>');
            return self::FAILURE;
        }

        $ignored = implode(',', array_keys($newList));

        $configFile = ConfigFile::open(Config::get('backends_file'), 'yaml');
        $configFile->setLogger($this->logger);

        if (empty($ignored)) {
            $configFile->delete("{$name}.options.ignore");
            if (count($configFile->get("{$name}.options")) < 1) {
                $configFile->delete("{$name}.options");
            }
        } else {
            $configFile->set("{$name}.options.ignore", $ignored);
        }

        $configFile->persist();

        return self::SUCCESS;
    }
}
