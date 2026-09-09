<?php

declare(strict_types=1);

namespace App\Commands\System;

use App\Command;
use App\Libs\Attributes\Route\Cli;
use Psr\SimpleCache\CacheInterface as iCache;
use Psr\SimpleCache\InvalidArgumentException;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
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

    public function __construct(
        private readonly iCache $cache,
    ) {
        parent::__construct();
    }

    /**
     * Configures the command.
     */
    protected function configure(): void
    {
        $this
            ->setName(self::ROUTE)
            ->addOption('list', 'l', null, 'List all routes')
            ->addOption(
                'filter',
                'f',
                InputOption::VALUE_REQUIRED,
                'Filter routes by exact, partial, or glob match.',
            )
            ->setDescription('Generate routes')
            ->setHelp(
                <<<HELP

                    This command force routes <notice>regeneration</notice> for commands & API endpoint.
                    You do not need to run this command unless told by the devs.
                    This is done automatically on container startup.

                    HELP,
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
            generate_routes();
            return self::SUCCESS;
        }

        return $this->showHttp($input, $output);
    }

    /**
     * @throws InvalidArgumentException
     */
    protected function showHttp(InputInterface $input, OutputInterface $output): int
    {
        $ar = $this->cache->get('routes_http', []);
        if ([] === $ar) {
            $ar = generate_routes('http', [iCache::class => $this->cache]);
        }

        $fn = static function (mixed $val, $type = 'array'): string {
            if (is_string($val)) {
                return $val;
            }

            if (is_array($val)) {
                return implode('callable' === $type ? '::' : ', ', $val);
            }

            return serialize($val);
        };

        $filter = $input->getOption('filter');
        if (null !== $filter) {
            $filter = trim((string) $filter);
            if ('' === $filter) {
                $output->writeln('<error>Route filter cannot be empty.</error>');
                return self::FAILURE;
            }

            $ar = $this->filterRoutes($ar, $filter, $fn);
        }

        $hosts = array_column($ar, 'host');
        $paths = array_column($ar, 'path');
        array_multisort($hosts, SORT_ASC, $paths, SORT_ASC, $ar);

        if ((bool) $input->getOption('json')) {
            $output->writeln((string) json_encode($ar, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return self::SUCCESS;
        }

        if ([] === $ar) {
            $output->writeln('<comment>No routes found.</comment>');
            return self::SUCCESS;
        }

        foreach ($ar as $index => $route) {
            $output->writeln('<info>' . ($index + 1) . '. ' . OutputFormatter::escape((string) ag($route, 'path')) . '</info>');
            $output->writeln(OutputFormatter::escape('   methods: ' . $fn(ag($route, 'method'))));
            $output->writeln(OutputFormatter::escape('   callable: ' . $fn(ag($route, 'callable'), 'callable')));

            if (($index + 1) < count($ar)) {
                $output->writeln('');
            }
        }

        return self::SUCCESS;
    }

    private function filterRoutes(array $routes, string $filter, callable $format): array
    {
        $filter = strtolower($filter);
        $values = static fn(array $route): array => array_map(
            strtolower(...),
            [
                $format(ag($route, 'method')),
                (string) ag($route, 'path', ''),
                (string) ag($route, 'host', ''),
                $format(ag($route, 'callable'), 'callable'),
            ],
        );

        $matches = array_values(array_filter(
            $routes,
            static fn(array $route): bool => in_array($filter, $values($route), true),
        ));

        if ([] === $matches) {
            $matches = array_values(array_filter(
                $routes,
                static fn(array $route): bool => array_any(
                    $values($route),
                    static fn(string $value): bool => str_contains($value, $filter),
                ),
            ));
        }

        if ([] !== $matches) {
            return $matches;
        }

        return array_values(array_filter(
            $routes,
            static fn(array $route): bool => array_any(
                $values($route),
                static fn(string $value): bool => fnmatch($filter, $value),
            ),
        ));
    }
}
