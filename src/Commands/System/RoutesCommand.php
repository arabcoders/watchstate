<?php

declare(strict_types=1);

namespace App\Commands\System;

use App\Command;
use App\Libs\Attributes\Route\Cli;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Class RoutesCommand
 *
 * This command is used to generate routes for commands. It is automatically run on container startup.
 */
#[Cli(command: self::ROUTE)]
final class RoutesCommand extends Command
{
    public const ROUTE = 'system:routes';

    /**
     * Configures the command.
     */
    protected function configure(): void
    {
        $this->setName(self::ROUTE)
            ->setDescription('Generate routes')->setHelp(
                <<<HELP

                This command force routes <notice>regeneration</notice> for commands & API endpoint.
                You do not need to run this command unless told by the devs.
                This is done automatically on container startup.

                HELP
            );
    }

    /**
     * Executes the command to generate routes.
     *
     * @param InputInterface $input The input interface object.
     * @param OutputInterface $output The output interface object.
     *
     * @return int The exit code of the command execution.
     */
    protected function runCommand(InputInterface $input, OutputInterface $output): int
    {
        generateRoutes();

        return self::SUCCESS;
    }
}
