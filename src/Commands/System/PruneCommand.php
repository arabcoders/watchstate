<?php

declare(strict_types=1);

namespace App\Commands\System;

use App\Command;
use App\Libs\Attributes\Route\Cli;
use App\Libs\Config;
use Cron\CronExpression;
use DateTimeZone;
use Psr\Log\LoggerInterface as iLogger;
use Psr\SimpleCache\CacheInterface as iCache;
use Symfony\Component\Console\Completion\CompletionInput;
use Symfony\Component\Console\Completion\CompletionSuggestions;
use Symfony\Component\Console\Completion\Suggestion;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

#[Cli(command: self::ROUTE)]
class PruneCommand extends Command
{
    public const string ROUTE = 'system:prune';
    public const string TASK_NAME = 'prune';

    public function __construct(
        private readonly iLogger $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName(self::ROUTE)
            ->addOption('run', 'r', InputOption::VALUE_NONE, 'Run due prune handlers.')
            ->addOption('prune', 'p', InputOption::VALUE_REQUIRED, 'Run the specified pruner only.')
            ->addOption('no-cache', 'c', InputOption::VALUE_NONE, 'Do not use cached prune discovery.')
            ->addOption('refresh-cache', null, InputOption::VALUE_NONE, 'Refresh cached prune discovery before using it.')
            ->addOption('execute', 'x', InputOption::VALUE_NONE, 'Perform the pruning operation.')
            ->setDescription('List and run prune handlers.')
            ->setHelp('List prune handlers or run them.');
    }

    protected function runCommand(InputInterface $input, OutputInterface $output): int
    {
        if (false === (bool) $input->getOption('run')) {
            $this->listPruners($input, $output);
            return self::SUCCESS;
        }

        return $this->runPruners($input, $output);
    }

    /**
     * @return array<string, array{name:string,cron:?string,desc:?string,enabled:bool,callable:string|array|\Closure,item:mixed,target:mixed}>
     */
    protected function getPruners(): array
    {
        return $this->loadPruners();
    }

    protected function shouldForcePrunerDiscovery(InputInterface $input): bool
    {
        return (bool) $input->getOption('no-cache') || (bool) $input->getOption('refresh-cache');
    }

    /**
     * @return array<string, array{name:string,cron:?string,desc:?string,enabled:bool,callable:string|array|\Closure,item:mixed,target:mixed}>
     */
    protected function resolvePruners(InputInterface $input): array
    {
        if (false === $this->shouldForcePrunerDiscovery($input)) {
            return $this->getPruners();
        }

        return $this->loadPruners(refresh: true);
    }

    /**
     * @param array<string> $paths
     *
     * @return array<string, array{name:string,cron:?string,desc:?string,enabled:bool,callable:string|array|\Closure,item:mixed,target:mixed}>
     */
    protected function loadPruners(bool $refresh = false, array $paths = []): array
    {
        $paths = [] !== $paths ? $paths : (array) Config::get('prune.paths', [__DIR__ . '/../Prune']);
        $paths = array_values(array_filter($paths, static fn(mixed $path): bool => is_scalar($path) || $path instanceof \Stringable));
        $paths = array_values(array_unique(array_filter(array_map(
            static fn(mixed $path): string => trim((string) $path),
            $paths,
        ))));

        $cacheTime = (int) Config::get('prune.cache.time', 0);
        if (0 === $cacheTime) {
            return discover_pruners($paths);
        }

        $cacheName = 'pruners';
        if ([] !== $paths) {
            $cacheName .= '.' . hash('sha256', implode('|', $paths));
        }

        $cache = \App\Libs\Container::get(iCache::class);

        try {
            if (false === $refresh && $cache->has($cacheName)) {
                return $cache->get($cacheName, []);
            }
        } catch (\Psr\SimpleCache\InvalidArgumentException) {
        }

        $pruners = discover_pruners($paths);

        try {
            $cache->set($cacheName, $pruners, $cacheTime);
        } catch (\Psr\SimpleCache\InvalidArgumentException) {
        }

        return $pruners;
    }

    protected function runPruners(InputInterface $input, OutputInterface $output): int
    {
        $execute = (bool) $input->getOption('execute');
        $run = [];
        $pruners = $this->resolvePruners($input);

        if (null !== ($prunerName = $input->getOption('prune'))) {
            $prunerName = normalize_pruner_name((string) $prunerName);

            if (false === ag_exists($pruners, $prunerName)) {
                $this->logger->warning("Unknown pruner '{pruner}'. No pruner with that name registered.", [
                    'pruner' => $prunerName,
                ]);

                return self::FAILURE;
            }

            $run[$prunerName] = ag($pruners, $prunerName);
        } else {
            foreach ($pruners as $name => $pruner) {
                if (false === (bool) ag($pruner, 'enabled', true)) {
                    continue;
                }

                try {
                    if (false === $this->isPrunerDue($pruner)) {
                        continue;
                    }

                    $run[$name] = $pruner;
                } catch (Throwable $e) {
                    $this->reportPrunerError($pruner, $e, $output);
                }
            }
        }

        if (count($run) < 1) {
            $this->logger->debug("No pruners scheduled at '{datetime}'.", [
                'datetime' => make_date(),
            ]);
        }

        foreach ($run as $pruner) {
            try {
                ag($pruner, 'item')->call($execute);
            } catch (Throwable $e) {
                $this->reportPrunerError($pruner, $e, $output);
            }
        }

        return self::SUCCESS;
    }

    protected function listPruners(InputInterface $input, OutputInterface $output): void
    {
        $list = [];
        $mode = $input->hasOption('output') ? (string) $input->getOption('output') : 'json';

        foreach ($this->resolvePruners($input) as $pruner) {
            $list[] = [
                'name' => ag($pruner, 'name'),
                'callable' => $this->stringifyCallable(ag($pruner, 'callable')),
                'cron' => ag($pruner, 'cron', 'every run'),
                'description' => ag($pruner, 'desc'),
                'enabled' => true === (bool) ag($pruner, 'enabled', true) ? 'yes' : 'no',
                'next' => $this->nextRunFor($pruner),
            ];
        }

        $this->displayContent($list, $output, $mode);
    }

    protected function isPrunerDue(array $pruner): bool
    {
        if (null === ($cron = ag($pruner, 'cron'))) {
            return true;
        }

        return new CronExpression((string) $cron)->isDue('now');
    }

    protected function nextRunFor(array $pruner): ?string
    {
        if (null === ($cron = ag($pruner, 'cron'))) {
            return null;
        }

        $displayTZ = new DateTimeZone((string) Config::get('tz', 'UTC'));

        return new CronExpression((string) $cron)
            ->getNextRunDate('now')
            ->setTimezone($displayTZ)
            ->format('Y-m-d H:i:s T');
    }

    protected function stringifyCallable(mixed $callable): string
    {
        if (is_string($callable)) {
            return $callable;
        }

        if (is_array($callable)) {
            return implode('::', array_map(static fn(mixed $part): string => is_object($part) ? $part::class : (string) $part, $callable));
        }

        if ($callable instanceof \Closure) {
            return 'Closure';
        }

        return serialize($callable);
    }

    protected function reportPrunerError(array $pruner, Throwable $e, OutputInterface $output): void
    {
        $this->logger->warning("Skipping pruner '{name}'. {error}", [
            'name' => ag($pruner, 'name', 'unknown'),
            'error' => $e->getMessage(),
            'exception' => $e,
        ]);
    }

    public function complete(CompletionInput $input, CompletionSuggestions $suggestions): void
    {
        parent::complete($input, $suggestions);

        if ($input->mustSuggestOptionValuesFor('prune')) {
            $currentValue = $input->getCompletionValue();
            $suggest = [];

            foreach ($this->getPruners() as $name => $pruner) {
                $prunerName = (string) ag($pruner, 'name', $name);
                if (!(empty($currentValue) || str_starts_with($prunerName, $currentValue))) {
                    continue;
                }

                $suggest[] = new Suggestion($prunerName);
            }

            $suggestions->suggestValues($suggest);
        }
    }
}
