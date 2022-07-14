<?php

declare(strict_types=1);

namespace App\Commands\System;

use App\Command;
use App\Libs\Routable;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[Routable(command: self::ROUTE)]
final class RoutesCommand extends Command
{
    public const ROUTE = 'system:routes';

    protected function configure(): void
    {
        $this->setName(self::ROUTE)
            ->setDescription('Generate commands routes.')->setHelp(
                <<<HELP

                This command force routes <notice>regeneration</notice> for commands.
                You do not need to run this command unless told by the team.
                This is done automatically on container startup.

                HELP
            );
    }

    protected function runCommand(InputInterface $input, OutputInterface $output): int
    {
        generateRoutes();

        return self::SUCCESS;
    }
}
