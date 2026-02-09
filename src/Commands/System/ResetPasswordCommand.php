<?php

declare(strict_types=1);

namespace App\Commands\System;

use App\Command;
use App\Libs\Attributes\Route\Cli;
use App\Libs\Enums\Http\Status;
use Symfony\Component\Console\Input\InputInterface as iInput;
use Symfony\Component\Console\Output\OutputInterface as iOutput;

#[Cli(command: self::ROUTE)]
final class ResetPasswordCommand extends Command
{
    public const string ROUTE = 'system:resetpassword';

    protected function configure(): void
    {
        $this
            ->setName(self::ROUTE)
            ->setDescription('Reset the system user and password.')
            ->setHelp('Resets the current system user and password.');
    }

    protected function runCommand(iInput $input, iOutput $output): int
    {
        $response = api_request('DELETE', '/system/env/WS_SYSTEM_SECRET');
        if (Status::OK !== $response->status) {
            $output->writeln(r('<error>Failed to reset the system secret key.</error>'));
            return self::FAILURE;
        }

        $response = api_request('DELETE', '/system/env/WS_SYSTEM_USER');
        if (Status::OK !== $response->status) {
            $output->writeln(r('<error>Failed to reset the system user.</error>'));
            return self::FAILURE;
        }

        $response = api_request('DELETE', '/system/env/WS_SYSTEM_PASSWORD');
        if (Status::OK !== $response->status) {
            $output->writeln(r('<error>Failed to reset the system password.</error>'));
            return self::FAILURE;
        }

        $output->writeln(r('<info>System user and password has been reset.</info>'));

        return self::SUCCESS;
    }
}
