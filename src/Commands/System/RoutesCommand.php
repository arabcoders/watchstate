<?php

declare(strict_types=1);

namespace App\Commands\System;

use App\Command;
use App\Libs\Attributes\Route\Cli;
use Psr\SimpleCache\CacheInterface as iCache;
use Psr\SimpleCache\InvalidArgumentException;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Helper\TableSeparator;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Class RoutesCommand
 *
 * This command is used to generate routes for commands. It is automatically run on container startup.
 */
#[Cli(command: self::ROUTE)]
final class RoutesCommand extends Command
{
    public const string ROUTE = 'system:routes';

    public function __construct(private readonly iCache $cache)
    {
        parent::__construct();
    }

    /**
     * Configures the command.
     */
    protected function configure(): void
    {
        $this->setName(self::ROUTE)
            ->addOption('list', 'l', null, 'List all routes')
            ->setDescription('Generate routes')->setHelp(
                <<<HELP

                This command force routes <notice>regeneration</notice> for commands & API endpoint.
                You do not need to run this command unless told by the devs.
                This is done automatically on container startup.

                HELP
            );
    }

    /**
     * Executes the command to generate routes.
     *
     * @param InputInterface $input The input interface object.
     * @param OutputInterface $output The output interface object.
     *
     * @return int The exit code of the command execution.
     * @throws InvalidArgumentException
     */
    protected function runCommand(InputInterface $input, OutputInterface $output): int
    {
        if (!$input->getOption('list')) {
            generateRoutes();
            return self::SUCCESS;
        }

        return $this->showHttp($input, $output);
    }

    /**
     * @throws InvalidArgumentException
     */
    protected function showHttp(InputInterface $input, OutputInterface $output): int
    {
        $list = [];

        $table = new Table($output);
        $table->setHeaders(
            [
                'Method/s',
                'Pattern',
                'Callable',
            ]
        );

        $ar = $this->cache->get('routes_http', []);

        $fn = function (mixed $val, $type = 'array'): string {
            if (is_string($val)) {
                return $val;
            }

            if (is_array($val)) {
                return implode('callable' === $type ? '::' : ', ', $val);
            }

            return serialize($val);
        };

        $hosts = array_column($ar, 'host');
        $paths = array_column($ar, 'path');
        array_multisort($hosts, SORT_ASC, $paths, SORT_ASC, $ar);

        if ('json' === $input->getOption('output')) {
            $output->writeln((string)json_encode($ar, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return self::SUCCESS;
        }

        foreach ($ar as $route) {
            $list[] = [
                $fn(ag($route, 'method')),
                ag($route, 'path'),
                $fn(ag($route, 'callable'), 'callable'),
            ];

            $list[] = new TableSeparator();
        }

        array_pop($list);

        $table->setRows($list);

        $table->render();

        return self::SUCCESS;
    }

}
