<?php

declare(strict_types=1);

namespace App\Commands\System;

use App\Command;
use App\Libs\Attributes\Route\Cli;
use App\Libs\Config;
use App\Libs\Database\DBLayer;
use App\Model\Events\EventsTable;
use DirectoryIterator;
use Psr\Log\LoggerInterface as iLogger;
use SplFileInfo;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Class PruneCommand
 *
 * This command removes automatically generated files like logs and backups.
 * It provides an option to run in dry-run mode to see what files will be removed without actually removing them.
 */
#[Cli(command: self::ROUTE)]
final class PruneCommand extends Command
{
    public const string ROUTE = 'system:prune';

    public const string TASK_NAME = 'prune';

    /**
     * Class Constructor.
     *
     * @param iLogger $logger The logger implementation used for logging.
     */
    public function __construct(private readonly iLogger $logger, private readonly DBLayer $db)
    {
        parent::__construct();
    }

    /**
     * Configure the command.
     */
    protected function configure(): void
    {
        $this->setName(self::ROUTE)
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Do not perform any actions on files.')
            ->setDescription('Remove automatically generated files.')
            ->setHelp(
                r(
                    <<<HELP

                    This command remove automatically generated files. like logs and backups.

                    to see what files will be removed without actually removing them. run the following command.

                    {cmd} <cmd>{route}</cmd> <flag>--dry-run</flag> <flag>-vvv</flag>

                    HELP,
                    [
                        'cmd' => trim(commandContext()),
                        'route' => self::ROUTE,
                    ]
                )
            );
    }

    /**
     * Executes the command.
     *
     * @param InputInterface $input The input interface.
     * @param OutputInterface $output The output interface.
     *
     * @return int The exit status code.
     */
    protected function runCommand(InputInterface $input, OutputInterface $output): int
    {
        $time = time();

        $directories = [
            [
                'name' => 'logs_remover',
                'path' => Config::get('tmpDir') . '/logs',
                'base' => Config::get('tmpDir'),
                'filter' => '/\.log$/',
                'time' => strtotime('-7 DAYS', $time)
            ],
            [
                'name' => 'webhooks_remover',
                'path' => Config::get('tmpDir') . '/webhooks',
                'base' => Config::get('tmpDir'),
                'filter' => '/\.json$/',
                'time' => strtotime('-3 DAYS', $time)
            ],
            [
                'name' => 'profiler_remover',
                'path' => Config::get('tmpDir') . '/profiler',
                'base' => Config::get('tmpDir'),
                'filter' => '/\.json$/',
                'time' => strtotime('-3 DAYS', $time)
            ],
            [
                'name' => 'debug_remover',
                'path' => Config::get('tmpDir') . '/debug',
                'base' => Config::get('tmpDir'),
                'filter' => '/\.json$/',
                'time' => strtotime('-3 DAYS', $time)
            ],
            [
                'name' => 'backup_remover',
                'path' => Config::get('path') . '/backup',
                'base' => Config::get('path'),
                'filter' => '/\.json$|\.json.zip$/',
                'validate' => fn(SplFileInfo $f): bool => 1 === @preg_match(
                        '/\w+\.\d{8}\.json(\.zip)?$/i',
                        $f->getBasename()
                    ),
                'time' => strtotime('-90 DAYS', $time)
            ],
        ];

        $inDryRunMode = $input->getOption('dry-run');

        foreach ($directories as $item) {
            $name = ag($item, 'name');
            $path = ag($item, 'path');
            $filter = ag($item, 'filter');

            if (null === ($expiresAt = ag($item, 'time'))) {
                $this->logger->warning("No expected time to live was found for '{name}' - '{path}'.", [
                    'name' => $name,
                    'path' => $path
                ]);
                continue;
            }

            if (null === $path || !is_dir($path)) {
                if (true === (bool)ag($item, 'report', true)) {
                    $this->logger->warning("{name}: Path '{path}' not found or is inaccessible.", [
                        'name' => $name,
                        'path' => $path
                    ]);
                }
                continue;
            }

            $validate = ag($item, 'validate', null);


            foreach (new DirectoryIterator($path) as $file) {
                if ($file->isDot() || $file->isDir() || false === $file->isFile() || $file->isLink()) {
                    continue;
                }

                $file = new SplFileInfo($file->getRealPath());

                $fileName = $file->getBasename();


                if (null !== $filter && false === @preg_match($filter, $fileName)) {
                    $this->logger->debug("{name}: File '{file}' did not pass filter checks.", [
                        'name' => $name,
                        'file' => after($file->getRealPath(), ag($item, 'base') . '/'),
                    ]);
                    continue;
                }

                if (null !== $validate && false === $validate($file)) {
                    $this->logger->debug("{name}: File '{file}' did not pass validation checks.", [
                        'name' => $name,
                        'file' => after($file->getRealPath(), ag($item, 'base') . '/'),
                    ]);
                    continue;
                }

                if ($file->getMTime() > $expiresAt) {
                    $this->logger->debug("{name}: File '{file}' Not yet expired. '{ttl}' seconds left.", [
                        'name' => $name,
                        'file' => after($file->getRealPath(), ag($item, 'base') . '/'),
                        'ttl' => number_format($file->getMTime() - $expiresAt),
                    ]);
                    continue;
                }

                $this->logger->notice("{name}: Removing '{file}'. expired TTL.", [
                    'name' => $name,
                    'file' => after($file->getRealPath(), ag($item, 'base') . '/')
                ]);

                if (false === $inDryRunMode) {
                    #unlink($file->getRealPath());
                }
            }
        }

        $this->cleanUp();
        return self::SUCCESS;
    }

    private function cleanUp(): void
    {
        $before = makeDate(strtotime('-7 DAYS'));

        $sql = "DELETE FROM
                " . EventsTable::TABLE_NAME . "
                WHERE
                " . EventsTable::COLUMN_CREATED_AT . " < datetime(:before)
        ";
        $stmt = $this->db->query($sql, [
            'before' => $before->format('Y-m-d'),
        ]);

        $count = $stmt->rowCount();
        if ($count > 1) {
            $this->logger->info("Pruned '{count}' events.", ['count' => $count]);
        }
    }
}
