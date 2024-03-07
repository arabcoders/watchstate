<?php

declare(strict_types=1);

namespace App\Commands\Backend;

use App\Command;
use App\Libs\Attributes\Route\Cli;
use App\Libs\Options;
use RuntimeException;
use Symfony\Component\Console\Input\InputArgument;
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
    public const ROUTE = 'backend:info';

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
            ->addOption('select-backend', 's', InputOption::VALUE_REQUIRED, 'Select backend.')
            ->addArgument('backend', InputArgument::OPTIONAL, 'Backend name to restore.');
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

        $mode = $input->getOption('output');
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
