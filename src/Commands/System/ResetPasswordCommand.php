<?php

declare(strict_types=1);

namespace App\Commands\System;

use App\Command;
use App\Libs\Attributes\Route\Cli;
use App\Libs\Config;
use App\Libs\Enums\Http\Status;
use Symfony\Component\Console\Input\InputInterface as iInput;
use Symfony\Component\Console\Output\OutputInterface as iOutput;

/**
 * Class ResetPasswordCommand
 */
#[Cli(command: self::ROUTE)]
final class ResetPasswordCommand extends Command
{
    public const string ROUTE = 'system:resetpassword';

    protected function configure(): void
    {
        $this->setName(self::ROUTE)
            ->setDescription('Reset the system user and password.')
            ->setHelp(
                'Resets the current system user and password to allow you to signup again. It will also reset the secret key'
            );
    }

    protected function runCommand(iInput $input, iOutput $output): int
    {
        $secret_file = Config::get('path') . '/config/.secret.key';
        if (file_exists($secret_file)) {
            unlink($secret_file);
        }

        $response = APIRequest('DELETE', '/system/env/WS_SYSTEM_USER');
        if (Status::OK !== $response->status) {
            $output->writeln(r("<error>Failed to reset the system user.</error>"));
            return self::FAILURE;
        }

        $response = APIRequest('DELETE', '/system/env/WS_SYSTEM_PASSWORD');
        if (Status::OK !== $response->status) {
            $output->writeln(r("<error>Failed to reset the system password.</error>"));
            return self::FAILURE;
        }

        $output->writeln(r("<info>System user and password has been reset.</info>"));

        return self::SUCCESS;
    }
}
