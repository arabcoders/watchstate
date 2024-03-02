<?php

declare(strict_types=1);

namespace App\Commands\Backend;

use App\Command;
use App\Libs\Attributes\Route\Cli;
use App\Libs\Config;
use App\Libs\Options;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Yaml;

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
            ->addOption('config', 'c', InputOption::VALUE_REQUIRED, 'Use Alternative config file.')
            ->addArgument('backend', InputArgument::REQUIRED, 'Backend name to restore.');
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
        $name = $input->getArgument('backend');
        $mode = $input->getOption('output');
        $opts = [];

        if (($config = $input->getOption('config'))) {
            try {
                Config::save('servers', Yaml::parseFile($this->checkCustomBackendsFile($config)));
            } catch (\App\Libs\Exceptions\RuntimeException $e) {
                $output->writeln(r('<error>{message}</error>', ['message' => $e->getMessage()]));
                return self::FAILURE;
            }
        }

        if (null === ag(Config::get('servers', []), $name, null)) {
            $output->writeln(r("<error>ERROR: Backend '{backend}' not found.</error>", ['backend' => $name]));
            return self::FAILURE;
        }

        if ($input->getOption('include-raw-response')) {
            $opts[Options::RAW_RESPONSE] = true;
        }

        $backend = $this->getBackend($name);

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
