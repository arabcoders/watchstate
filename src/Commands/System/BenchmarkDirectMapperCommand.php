<?php

declare(strict_types=1);

namespace App\Commands\System;

use App\Command;
use App\Libs\Attributes\DI\Inject;
use App\Libs\Attributes\Route\Cli;
use App\Libs\Config;
use App\Libs\Mappers\Import\DirectMapper;
use App\Libs\Mappers\ImportInterface as iImport;
use App\Libs\Options;
use Psr\Log\LoggerInterface as iLogger;
use Symfony\Component\Console\Input\InputInterface as iInput;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface as iOutput;

#[Cli(command: self::ROUTE)]
final class BenchmarkDirectMapperCommand extends Command
{
    public const string ROUTE = 'benchmark:direct-mapper';

    private const array COMPARE_METRICS = [
        [
            'id' => 'records',
            'label' => 'Records',
            'decimals' => 0,
            'unit' => '',
            'direction' => 'equal',
        ],
        [
            'id' => 'preload.pointers',
            'label' => 'Preload pointers',
            'decimals' => 0,
            'unit' => '',
            'direction' => 'equal',
        ],
        [
            'id' => 'preload.time_s',
            'label' => 'Preload time',
            'decimals' => 4,
            'unit' => 's',
            'direction' => 'lower',
        ],
        [
            'id' => 'preload.mem_mb_delta',
            'label' => 'Preload mem delta',
            'decimals' => 2,
            'unit' => 'MB',
            'direction' => 'lower',
        ],
        [
            'id' => 'preload.peak_mb_delta',
            'label' => 'Preload peak delta',
            'decimals' => 2,
            'unit' => 'MB',
            'direction' => 'lower',
        ],
        [
            'id' => 'add.time_s',
            'label' => 'Add time',
            'decimals' => 4,
            'unit' => 's',
            'direction' => 'lower',
        ],
        [
            'id' => 'add.rate_per_s',
            'label' => 'Add rate',
            'decimals' => 2,
            'unit' => '/s',
            'direction' => 'higher',
        ],
        [
            'id' => 'final.mem_mb',
            'label' => 'Final mem',
            'decimals' => 2,
            'unit' => 'MB',
            'direction' => 'lower',
        ],
        [
            'id' => 'final.peak_mb',
            'label' => 'Final peak',
            'decimals' => 2,
            'unit' => 'MB',
            'direction' => 'lower',
        ],
    ];

    /**
     * Class constructor.
     *
     * @param iImport $mapper
     * @param iLogger $logger
     */
    public function __construct(
        #[Inject(DirectMapper::class)]
        private readonly iImport $mapper,
        private readonly iLogger $logger,
    ) {
        parent::__construct();
    }

    /**
     * Configure the command.
     */
    protected function configure(): void
    {
        $this
            ->setName(self::ROUTE)
            ->setDescription('Benchmark DirectMapper performance for current database.')
            ->addOption('user', 'u', InputOption::VALUE_REQUIRED, 'User to benchmark.', 'main')
            ->addOption('all-users', null, InputOption::VALUE_NONE, 'Benchmark all users.')
            ->addOption(
                'memory-limit',
                null,
                InputOption::VALUE_OPTIONAL,
                'Set PHP memory_limit for this run (for example: 512M, -1).',
            )
            ->addOption('skip-preload', null, InputOption::VALUE_NONE, 'Skip DirectMapper loadData pass.')
            ->addOption('skip-add', null, InputOption::VALUE_NONE, 'Skip add pass over db fetch.')
            ->addOption('dry-run', null, InputOption::VALUE_NEGATABLE, 'Use mapper dry-run mode.', true)
            ->addOption('desc', null, InputOption::VALUE_REQUIRED, 'Add description to the benchmark run.')
            ->addOption(
                'compare',
                null,
                InputOption::VALUE_OPTIONAL,
                'Compare to the latest saved report or to a specific report path.',
            )
            ->setHelp(
                r(
                    <<<HELP

                        Benchmark DirectMapper implementation.

                        Examples:
                          {cmd} <cmd>{route}</cmd>
                          {cmd} <cmd>{route}</cmd> <flag>--memory-limit=-1</flag>
                          {cmd} <cmd>{route}</cmd> <flag>--skip-add</flag>
                          {cmd} <cmd>{route}</cmd> <flag>--all-users</flag>
                          {cmd} <cmd>{route}</cmd> <flag>--desc</flag> <value>baseline</value>
                          {cmd} <cmd>{route}</cmd> <flag>--compare</flag>
                          {cmd} <cmd>{route}</cmd> <flag>--compare</flag> <value>./benchmarks/direct_mapper_20260203_175533-baseline.txt</value>

                        HELP,
                    [
                        'cmd' => trim(command_context()),
                        'route' => self::ROUTE,
                    ],
                ),
            );
    }

    /**
     * Run a command.
     *
     * @param iInput $input An instance of the InputInterface interface.
     * @param iOutput $output An instance of the OutputInterface interface.
     *
     * @return int The status code indicating the success or failure of the command execution.
     */
    protected function runCommand(iInput $input, iOutput $output): int
    {
        $memoryLimitOpt = $input->getOption('memory-limit');

        if (null !== $memoryLimitOpt && '' !== (string) $memoryLimitOpt) {
            ini_set('memory_limit', (string) $memoryLimitOpt);
        }

        $dryRun = (bool) $input->getOption('dry-run');
        $skipPreload = (bool) $input->getOption('skip-preload');
        $skipAdd = (bool) $input->getOption('skip-add');

        $desc = $input->getOption('desc');
        $desc = is_string($desc) ? trim($desc) : '';

        $baseOptions = array_replace_recursive($this->mapper->getOptions(), [
            Options::DRY_RUN => $dryRun,
        ]);

        $baseMapper = $this->mapper->withOptions($baseOptions);

        if (true === (bool) $input->getOption('all-users')) {
            $users = get_users_context(mapper: $baseMapper, logger: $this->logger);
        } else {
            $userName = (string) $input->getOption('user');
            $users = [$userName => get_user_context($userName, $baseMapper, $this->logger)];
        }

        $runId = gmdate('c');
        // @mago-expect lint:no-shorthand-ternary make more sense like this.
        $memoryLimit = (string) (ini_get('memory_limit') ?: 'unknown');

        $report = [
            'run_id' => $runId,
            'desc' => $desc,
            'memory_limit' => $memoryLimit,
            'dry_run' => $dryRun,
            'skip_preload' => $skipPreload,
            'skip_add' => $skipAdd,
            'users' => [],
        ];

        $benchDir = fix_path(r('{path}/benchmarks', ['path' => Config::get('path')]));
        $compareRequested = $input->hasParameterOption('--compare');
        $comparePath = $this->resolveComparePath($input, $benchDir);

        $lines = [
            'DirectMapper benchmark',
            'Run: ' . $runId,
        ];

        if ('' !== $desc) {
            $lines[] = 'Description: ' . $desc;
        }

        $lines[] = 'Data path: ' . Config::get('path');
        $lines[] = 'Memory limit: ' . $memoryLimit;
        $lines[] = 'Dry run: ' . ($dryRun ? 'yes' : 'no');
        $lines[] = 'Users: ' . implode(', ', array_keys($users));
        $lines[] = '';

        foreach ($users as $userName => $userContext) {
            $mapper = $userContext->mapper->withOptions(array_replace_recursive(
                $userContext->mapper->getOptions(),
                [Options::DRY_RUN => $dryRun],
            ));

            $mapper->reset();

            $total = $userContext->db->getTotal();
            $dbFile = 'main' === $userName
                ? Config::get('database.file', 'unknown')
                : fix_path(r('{path}/users/{user}/user.db', ['path' => Config::get('path'), 'user' => $userName]));

            $loadTime = 0.0;
            $loadMem = 0.0;
            $loadPeak = 0.0;
            $pointers = 0;

            if (false === $skipPreload) {
                $start = microtime(true);
                $mem0 = memory_get_usage();
                $peak0 = memory_get_peak_usage();
                $mapper->loadData();
                $loadTime = microtime(true) - $start;
                $loadMem = memory_get_usage() - $mem0;
                $loadPeak = memory_get_peak_usage() - $peak0;
                $pointers = count($mapper->getPointersList());
            }

            $processed = 0;
            $addTime = 0.0;
            $addRate = 0.0;

            if (false === $skipAdd) {
                $start = microtime(true);
                foreach ($userContext->db->fetch() as $entity) {
                    $mapper->add($entity);
                    $processed++;
                }
                $addTime = microtime(true) - $start;
                $addRate = $addTime > 0 ? $processed / $addTime : 0.0;
            }

            $mem1 = memory_get_usage();
            $peak1 = memory_get_peak_usage();

            $toMb = static fn(float $bytes): float => round(($bytes / 1024) / 1024, 2);

            $report['users'][$userName] = [
                'db_file' => $dbFile,
                'records' => $total,
                'preload' => [
                    'time_s' => round($loadTime, 4),
                    'pointers' => $pointers,
                    'mem_mb_delta' => $toMb((float) $loadMem),
                    'peak_mb_delta' => $toMb((float) $loadPeak),
                ],
                'add' => [
                    'time_s' => round($addTime, 4),
                    'rate_per_s' => round($addRate, 2),
                    'processed' => $processed,
                ],
                'final' => [
                    'mem_mb' => $toMb((float) $mem1),
                    'peak_mb' => $toMb((float) $peak1),
                ],
            ];

            $lines[] = 'User: ' . $userName;
            $lines[] = 'DB file: ' . $dbFile;
            $lines[] = 'Records: ' . number_format((int) $total);

            if (true === $skipPreload) {
                $lines[] = 'Preload: skipped';
            } else {
                $lines[] =
                    'Preload: time='
                    . round($loadTime, 4)
                    . 's, pointers='
                    . number_format((int) $pointers)
                    . ', mem_delta='
                    . $toMb((float) $loadMem)
                    . 'MB, peak_delta='
                    . $toMb((float) $loadPeak)
                    . 'MB';
            }

            if (true === $skipAdd) {
                $lines[] = 'Add pass: skipped';
            } else {
                $lines[] =
                    'Add pass: time='
                    . round($addTime, 4)
                    . 's, rate='
                    . round($addRate, 2)
                    . '/s, processed='
                    . number_format((int) $processed);
            }

            $lines[] = 'Final: mem=' . $toMb((float) $mem1) . 'MB, peak=' . $toMb((float) $peak1) . 'MB';
            $lines[] = '';
        }

        $compareLines = [];

        if (null !== $comparePath) {
            $baseline = $this->loadReportFromFile($comparePath);
            if (null !== $baseline) {
                $compare = $this->compareReports($baseline, $report);
                $report['compare'] = $compare;
                $compareLines = $this->formatCompareLines($comparePath, $compare);
            } else {
                $compareLines[] = 'Compare: unable to parse baseline report.';
            }
        } elseif (true === $compareRequested) {
            $compareLines[] = 'Compare: no baseline report found.';
        }

        $json = json_encode(
            $report,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_IGNORE,
        );

        $lines[] = 'JSON:';
        $lines[] = $json;

        if (count($compareLines) > 0) {
            $lines[] = '';
            $lines[] = 'Compare:';
            foreach ($compareLines as $line) {
                $lines[] = $line;
            }
        }

        if (null !== ($reportPath = $this->storeReport($report, $lines, $benchDir))) {
            $lines[] = '';
            $lines[] = 'Saved: ' . $reportPath;
        }

        foreach ($lines as $line) {
            $output->writeln($line);
        }

        return self::SUCCESS;
    }

    private function storeReport(array $report, array $lines, string $benchDir): ?string
    {
        if (false === is_dir($benchDir)) {
            if (false === @mkdir($benchDir, 0o755, true) && false === is_dir($benchDir)) {
                $this->logger->warning("BenchmarkDirectMapperCommand: Unable to create '{path}' directory.", [
                    'path' => $benchDir,
                ]);
                return null;
            }
        }

        $stamp = gmdate('Ymd_His');
        $suffix = '';

        if (!empty($report['desc'])) {
            $suffix = '-' . $this->normalizeDesc((string) $report['desc']);
        }

        $file = r('direct_mapper_{stamp}{suffix}.txt', ['stamp' => $stamp, 'suffix' => $suffix]);
        $path = fix_path($benchDir . '/' . $file);
        $content = implode(PHP_EOL, $lines) . PHP_EOL;

        if (false === @file_put_contents($path, $content)) {
            $this->logger->warning("BenchmarkDirectMapperCommand: Unable to write report '{path}'.", [
                'path' => $path,
            ]);
            return null;
        }

        return $path;
    }

    private function resolveComparePath(iInput $input, string $benchDir): ?string
    {
        $compareOpt = $input->getOption('compare');
        if (null === $compareOpt && false === $input->hasParameterOption('--compare')) {
            return null;
        }

        if (is_string($compareOpt) && '' !== trim($compareOpt)) {
            $path = fix_path(trim($compareOpt));
            if (true === file_exists($path)) {
                return $path;
            }

            $this->logger->warning("BenchmarkDirectMapperCommand: Compare path '{path}' does not exist.", [
                'path' => $path,
            ]);
            return null;
        }

        $latest = $this->findLatestReport($benchDir);
        if (null === $latest) {
            $this->logger->notice("BenchmarkDirectMapperCommand: No prior reports found in '{path}'.", [
                'path' => $benchDir,
            ]);
        }

        return $latest;
    }

    private function findLatestReport(string $benchDir): ?string
    {
        if (false === is_dir($benchDir)) {
            return null;
        }

        $files = glob($benchDir . '/direct_mapper_*.txt');
        if (empty($files)) {
            return null;
        }

        usort($files, static fn(string $a, string $b): int => filemtime($b) <=> filemtime($a));

        return $files[0] ?? null;
    }

    private function loadReportFromFile(string $path): ?array
    {
        $contents = file_get_contents($path);
        if (false === $contents || '' === $contents) {
            return null;
        }

        $lines = preg_split('/\r\n|\n/', $contents);
        if (false === $lines) {
            return null;
        }

        $jsonLines = [];
        $inJson = false;

        foreach ($lines as $line) {
            if (false === $inJson) {
                if ('JSON:' === trim($line)) {
                    $inJson = true;
                }
                continue;
            }

            if (str_starts_with($line, 'Saved:')) {
                break;
            }

            $jsonLines[] = $line;
        }

        $json = trim(implode("\n", $jsonLines));
        if ('' === $json) {
            return null;
        }

        $data = json_decode($json, true);
        if (false === is_array($data)) {
            return null;
        }

        return $data;
    }

    private function compareReports(array $baseline, array $current): array
    {
        $compare = [
            'baseline' => [
                'run_id' => ag($baseline, 'run_id'),
                'desc' => ag($baseline, 'desc', ''),
            ],
            'users' => [],
        ];

        foreach (ag($current, 'users', []) as $userName => $currentUser) {
            $baselineUser = ag($baseline, "users.{$userName}", null);
            if (false === is_array($baselineUser)) {
                $compare['users'][$userName] = ['missing_baseline' => true];
                continue;
            }

            $compare['users'][$userName] = [
                'missing_baseline' => false,
                'metrics' => $this->compareUserMetrics($baselineUser, $currentUser),
            ];
        }

        return $compare;
    }

    private function compareUserMetrics(array $baseline, array $current): array
    {
        $metrics = [];

        foreach (self::COMPARE_METRICS as $metric) {
            $id = $metric['id'];
            $baselineValue = ag($baseline, $id, null);
            $currentValue = ag($current, $id, null);
            if (null === $baselineValue || null === $currentValue) {
                continue;
            }

            $delta = $currentValue - $baselineValue;
            $percent = null;
            if (0 !== (float) $baselineValue) {
                $percent = ($delta / $baselineValue) * 100;
            }

            $trend = $this->classifyTrend($metric['direction'], $delta);

            $metrics[$id] = [
                'baseline' => $baselineValue,
                'current' => $currentValue,
                'delta' => $delta,
                'percent' => $percent,
                'trend' => $trend,
            ];
        }

        return $metrics;
    }

    private function classifyTrend(string $direction, float $delta): string
    {
        if ('equal' === $direction) {
            return 0.0 === $delta ? 'even' : 'changed';
        }

        if ('lower' === $direction) {
            // @mago-expect lint:no-nested-ternary more readable like this.
            return $delta < 0.0 ? 'gain' : ($delta > 0.0 ? 'loss' : 'even');
        }

        // @mago-expect lint:no-nested-ternary more readable like this.
        return $delta > 0.0 ? 'gain' : ($delta < 0.0 ? 'loss' : 'even');
    }

    private function formatCompareLines(string $path, array $compare): array
    {
        $lines = [
            'Baseline: ' . $path,
            'Baseline run: ' . (string) ag($compare, 'baseline.run_id', 'unknown'),
        ];

        $desc = (string) ag($compare, 'baseline.desc', '');
        if ('' !== $desc) {
            $lines[] = 'Baseline desc: ' . $desc;
        }

        foreach (ag($compare, 'users', []) as $userName => $userCompare) {
            if (true === (bool) ag($userCompare, 'missing_baseline', false)) {
                $lines[] = "User: {$userName} (no baseline data)";
                continue;
            }

            $lines[] = "User: {$userName}";
            foreach (self::COMPARE_METRICS as $metric) {
                $id = $metric['id'];
                $entry = $userCompare['metrics'][$id] ?? null;
                if (false === is_array($entry)) {
                    continue;
                }

                $baselineValue = $entry['baseline'];
                $currentValue = $entry['current'];
                $delta = $entry['delta'];
                $percent = $entry['percent'];
                $trend = (string) $entry['trend'];

                $baselineText = $this->formatValue($baselineValue, $metric['decimals'], $metric['unit']);
                $currentText = $this->formatValue($currentValue, $metric['decimals'], $metric['unit']);
                $deltaText = $this->formatDelta($delta, $metric['decimals'], $metric['unit']);
                $percentText = null === $percent ? 'n/a' : sprintf('%+.2f%%', $percent);

                $lines[] = sprintf(
                    '%s: %s -> %s (Δ %s, %s) %s',
                    $metric['label'],
                    $baselineText,
                    $currentText,
                    $deltaText,
                    $percentText,
                    $trend,
                );
            }
        }

        return $lines;
    }

    private function formatValue(float|int $value, int $decimals, string $unit): string
    {
        $formatted = 0 === $decimals
            ? number_format((float) $value, 0, '.', ',')
            : number_format((float) $value, $decimals, '.', ',');

        if ('' !== $unit) {
            return $formatted . $unit;
        }

        return $formatted;
    }

    private function formatDelta(float $delta, int $decimals, string $unit): string
    {
        if (0 === $decimals) {
            $sign = 0 > $delta ? '-' : '+';
            $formatted = $sign . number_format(abs($delta), 0, '.', ',');
        } else {
            $formatted = sprintf('%+.' . $decimals . 'f', $delta);
        }

        if ('' !== $unit) {
            return $formatted . $unit;
        }

        return $formatted;
    }

    private function normalizeDesc(string $desc): string
    {
        $normalized = preg_replace('/[^a-zA-Z0-9._-]+/', '_', $desc) ?? '';
        $normalized = trim($normalized, '_');
        if ('' === $normalized) {
            return 'run';
        }
        if (strlen($normalized) > 48) {
            $normalized = substr($normalized, 0, 48);
        }
        return strtolower($normalized);
    }
}
