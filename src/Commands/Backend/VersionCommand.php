<?php

declare(strict_types=1);

namespace App\Commands\Backend;

use App\Command;
use App\Libs\Attributes\Route\Cli;
use RuntimeException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Class VersionCommand
 *
 * The VersionCommand class is used to retrieve the backend product version.
 */
#[Cli(command: self::ROUTE)]
class VersionCommand extends Command
{
    public const ROUTE = 'backend:version';

    /**
     * Configures the command.
     */
    protected function configure(): void
    {
        $this->setName(self::ROUTE)
            ->setDescription('Get backend product version.')
            ->addOption('select-backend', 's', InputOption::VALUE_REQUIRED, 'Select backend.')
            ->addArgument('backend', InputArgument::OPTIONAL, 'Backend name to restore.');
    }

    /**
     * Runs the command.
     *
     * @param InputInterface $input The input interface.
     * @param OutputInterface $output The output interface.
     *
     * @return int The exit code of the command.
     */
    protected function runCommand(InputInterface $input, OutputInterface $output): int
    {
        if (null !== ($name = $input->getOption('select-backend'))) {
            $name = explode(',', $name, 2)[0];
        }

        if (empty($name) && null !== ($name = $input->getArgument('backend'))) {
            $name = $input->getArgument('backend');
            $output->writeln(
                '<notice>WARNING: The use of backend name as argument is deprecated and will be removed from future versions. Please use [-s, --select-backend] option instead.</notice>'
            );
        }

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

        $output->writeln($backend->getVersion());

        return self::SUCCESS;
    }
}
