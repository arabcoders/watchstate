<?php

declare(strict_types=1);

namespace App\Commands\System;

use App\Command;
use App\Libs\Attributes\Route\Cli;
use App\Libs\Config;
use App\Libs\Enums\Http\Status;
use App\Libs\TokenUtil;
use Symfony\Component\Console\Input\InputInterface as iInput;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface as iOutput;

#[Cli(command: self::ROUTE)]
final class APIKeyCommand extends Command
{
    public const string ROUTE = 'system:apikey';

    protected function configure(): void
    {
        $this->setName(self::ROUTE)
            ->addOption('regenerate', 'r', InputOption::VALUE_NONE, 'Re-generate a new API key.')
            ->setDescription('Show current API key or generate a new one.');
    }

    protected function runCommand(iInput $input, iOutput $output): int
    {
        $regenerate = (bool)$input->getOption('regenerate');
        if ($regenerate || null === ($apiKey = Config::get('api.key'))) {
            return $this->regenerate($output);
        }

        $output->writeln('<info>Current system API key:</info>');
        $output->writeln('<comment>' . $apiKey . '</comment>');

        return self::SUCCESS;
    }

    private function regenerate(iOutput $output): int
    {
        $apiKey = TokenUtil::generateSecret(16);
        $response = APIRequest('POST', '/system/env/WS_API_KEY', [
            'value' => $apiKey,
        ]);

        if (Status::OK !== $response->status) {
            $output->writeln(r("<error>Failed to set the new API key.</error>"));
            return self::FAILURE;
        }

        $output->writeln('<info>The New system API key:</info>');
        $output->writeln('<comment>' . $apiKey . '</comment>');

        return self::SUCCESS;
    }
}
