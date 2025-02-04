<?php

declare(strict_types=1);

namespace App\Commands\Backend;

use App\Command;
use App\Libs\Attributes\Route\Cli;
use App\Libs\Options;
use RuntimeException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Class InfoCommand
 *
 * This command retrieves and displays backend product information.
 *
 * @Routable(command: self::ROUTE)
 */
#[Cli(command: self::ROUTE)]
class InfoCommand extends Command
{
    public const string ROUTE = 'backend:info';

    /**
     * Configures the command.
     *
     * @return void
     */
    protected function configure(): void
    {
        $this->setName(self::ROUTE)
            ->setDescription('Get backend product info.')
            ->addOption('include-raw-response', null, InputOption::VALUE_NONE, 'Include unfiltered raw response.')
            ->addOption('select-backend', 's', InputOption::VALUE_REQUIRED, 'Select backend.');
    }

    /**
     * Executes the command.
     *
     * @param InputInterface $input The input interface.
     * @param OutputInterface $output The output interface.
     * @return int The exit code. 0 for success, 1 for failure.
     */
    protected function runCommand(InputInterface $input, OutputInterface $output): int
    {
        $mode = $input->getOption('output');
        $name = $input->getOption('select-backend');

        if (empty($name)) {
            $output->writeln(r('<error>ERROR: Backend not specified. Please use [-s, --select-backend].</error>'));
            return self::FAILURE;
        }

        $opts = [];

        if ($input->getOption('include-raw-response')) {
            $opts[Options::RAW_RESPONSE] = true;
        }

        try {
            $backend = $this->getBackend($name);
        } catch (RuntimeException) {
            $output->writeln(r("<error>ERROR: Backend '{backend}' not found.</error>", ['backend' => $name]));
            return self::FAILURE;
        }

        $info = $backend->getInfo($opts);

        if ('table' === $mode) {
            foreach ($info as &$val) {
                if (false === is_bool($val)) {
                    continue;
                }
                $val = $val ? 'Yes' : 'No';
            }

            $info = [$info];
        }

        $this->displayContent($info, $output, $mode);

        return self::SUCCESS;
    }
}
