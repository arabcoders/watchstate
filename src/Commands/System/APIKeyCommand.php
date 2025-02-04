<?php

declare(strict_types=1);

namespace App\Commands\System;

use App\Command;
use App\Libs\Attributes\Route\Cli;
use App\Libs\Config;
use App\Libs\EnvFile;
use Symfony\Component\Console\Input\InputInterface as iInput;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface as iOutput;

/**
 * Class APIKeyCommand
 *
 * This class is a command that allows the user to generate a new API key or show the current one.
 */
#[Cli(command: self::ROUTE)]
final class APIKeyCommand extends Command
{
    public const string ROUTE = 'system:apikey';

    /**
     * Configure the command.
     */
    protected function configure(): void
    {
        $this->setName(self::ROUTE)
            ->addOption('regenerate', 'r', InputOption::VALUE_NONE, 'Re-generate a new API key.')
            ->setDescription('Show current API key or generate a new one.');
    }

    /**
     * Executes the command.
     *
     * @param iInput $input The input interface.
     * @param iOutput $output The output interface.
     *
     * @return int The exit status code.
     */
    protected function runCommand(iInput $input, iOutput $output): int
    {
        $regenerate = (bool)$input->getOption('regenerate');
        if ($regenerate || null === ($apiKey = Config::get('api.key'))) {
            return $this->regenerate($output);
        }

        $output->writeln('<info>Current API key:</info>');
        $output->writeln('<comment>' . $apiKey . '</comment>');

        return self::SUCCESS;
    }

    private function regenerate(iOutput $output): int
    {
        try {
            $apiKey = bin2hex(random_bytes(16));
        } catch (\Exception $e) {
            $output->writeln(
                r('<error>Failed to generate a new API key. {error}</error>', ['error' => $e->getMessage()])
            );
            return self::FAILURE;
        }

        $output->writeln('<info>The New API key is:</info>');
        $output->writeln('<comment>' . $apiKey . '</comment>');

        if (null !== ($oldKey = Config::get('api.key'))) {
            $output->writeln('<info>Old API key:</info>');
            $output->writeln('<comment>' . $oldKey . '</comment>');
        }

        $envFile = new EnvFile(fixPath(Config::get('path') . '/config/.env'), true);
        $envFile->set('WS_API_KEY', $apiKey)->persist();

        $output->writeln(r("<info>API key has been added to '{file}'.</info>", ['file' => $envFile->file]));

        return self::SUCCESS;
    }
}
