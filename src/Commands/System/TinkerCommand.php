<?php

declare(strict_types=1);

namespace App\Commands\System;

use App\Command;
use App\Libs\Attributes\Route\Cli;
use Psy\Configuration;
use Psy\Shell;
use Psy\VersionUpdater\Checker;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface as iInput;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface as iOutput;

/**
 * Class TinkerCommand
 *
 * Interactive shell command to manually write scripts.
 */
#[Cli(command: self::ROUTE)]
final class TinkerCommand extends Command
{
    public const string ROUTE = 'system:tinker';

    /**
     * Class Constructor.
     */
    public function __construct()
    {
        set_time_limit(0);
        ini_set('memory_limit', '-1');

        parent::__construct();
    }

    /**
     * Configure the Tinker command.
     *
     * @return void
     */
    protected function configure(): void
    {
        $this->setName(self::ROUTE)
            ->addOption('execute', 'e', InputOption::VALUE_OPTIONAL, 'Execute the given code using Tinker')
            ->addArgument('include', InputArgument::IS_ARRAY, 'Include file(s) before starting tinker')
            ->setDescription('A Interactive shell to manually write scripts.');
    }

    /**
     * Run the interactive shell.
     *
     * @param iInput $input The input object containing the command input.
     * @param iOutput $output The output object for writing command output.
     *
     * @return int Returns 0 on success or an error code on failure.
     */
    protected function execute(iInput $input, iOutput $output): int
    {
        $this->getApplication()->setCatchExceptions(false);

        $config = Configuration::fromInput($input);
        $config->setUpdateCheck(Checker::NEVER);

        if ($input->getOption('execute')) {
            $config->setRawOutput(true);
        }

        $shell = new Shell($config);
        $shell->addCommands($this->getCommands());
        $shell->setIncludes($input->getArgument('include'));

        if ($code = $input->getOption('execute')) {
            $shell->setOutput($output);
            $shell->execute($code);
            return 0;
        }

        return $shell->run();
    }

    /**
     * Get commands to pass through to PsySH.
     *
     * @return array<Command> The commands to pass through to PsySH.
     */
    protected function getCommands(): array
    {
        $commands = [];

        foreach ($this->getApplication()->all() as $command) {
            $commands[] = $command;
        }

        return $commands;
    }
}
