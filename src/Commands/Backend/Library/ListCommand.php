<?php

declare(strict_types=1);

namespace App\Commands\Backend\Library;

use App\Command;
use App\Libs\Attributes\Route\Cli;
use App\Libs\Options;
use RuntimeException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Class ListCommand
 *
 * This command list the backend libraries. This help you to know which library are supported.
 */
#[Cli(command: self::ROUTE)]
final class ListCommand extends Command
{
    public const ROUTE = 'backend:library:list';

    /**
     * Configures the command.
     */
    protected function configure(): void
    {
        $this->setName(self::ROUTE)
            ->setDescription('Get Backend libraries list.')
            ->addOption('include-raw-response', null, InputOption::VALUE_NONE, 'Include unfiltered raw response.')
            ->addArgument('backend', InputArgument::REQUIRED, 'Backend name.')
            ->setHelp(
                <<<HELP

                This command list the backend libraries. This help you to know which library are supported.
                the <notice>Id</notice> column refers to backend <notice>library id</notice>.

                HELP
            );
    }

    /**
     * Executes the command.
     *
     * @param InputInterface $input The input instance.
     * @param OutputInterface $output The output instance.
     *
     * @return int The command exit code.
     */
    protected function runCommand(InputInterface $input, OutputInterface $output): int
    {
        $mode = $input->getOption('output');
        $name = $input->getArgument('backend');

        $opts = $backendOpts = [];

        if ($input->getOption('include-raw-response')) {
            $opts[Options::RAW_RESPONSE] = true;
        }

        if ($input->getOption('trace')) {
            $backendOpts = ag_set($backendOpts, 'options.' . Options::DEBUG_TRACE, true);
        }

        try {
            $backend = $this->getBackend($name, $backendOpts);
        } catch (RuntimeException) {
            $output->writeln(r("<error>ERROR: Backend '{backend}' not found.</error>", ['backend' => $name]));
            return self::FAILURE;
        }

        $libraries = $backend->listLibraries(opts: $opts);

        if (count($libraries) < 1) {
            $arr = [
                'info' => sprintf('%s: No libraries were found.', $name),
            ];
            $this->displayContent('table' === $mode ? [$arr] : $arr, $output, $mode);
            return self::FAILURE;
        }

        if ('table' === $mode) {
            $list = [];

            foreach ($libraries as $item) {
                foreach ($item as $key => $val) {
                    if (false === is_bool($val)) {
                        continue;
                    }
                    $item[$key] = $val ? 'Yes' : 'No';
                }
                $list[] = $item;
            }

            $libraries = $list;
        }

        $this->displayContent($libraries, $output, $mode);

        return self::SUCCESS;
    }
}
