<?php

declare(strict_types=1);

namespace App\Commands\System;

use App\Command;
use App\Libs\Config;
use App\Libs\Database\DatabaseInterface as iDB;
use App\Libs\Entity\StateInterface as iState;
use App\Libs\Guid;
use App\Libs\Routable;
use PDO;
use Psr\Log\LoggerInterface as iLogger;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[Routable(command: self::ROUTE)]
final class IndexCommand extends Command
{
    public const ROUTE = 'system:index';

    public const TASK_NAME = 'indexes';

    protected const INDEX_IGNORE_ON = [
        iState::COLUMN_ID,
        iState::COLUMN_PARENT,
        iState::COLUMN_GUIDS,
        iState::COLUMN_EXTRA,
        iState::COLUMN_META_DATA,
    ];

    protected const BACKEND_INDEXES = [
        iState::COLUMN_ID,
        iState::COLUMN_META_SHOW,
        iState::COLUMN_META_LIBRARY,
    ];

    private PDO $db;

    public function __construct(iDB $db, private iLogger $logger)
    {
        $this->db = $db->getPdo();

        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName(self::ROUTE)
            ->setDescription('Ensure database has correct indexes.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Do not commit changes.')
            ->addOption('force-reindex', 'f', InputOption::VALUE_NONE, 'Drop existing indexes, and re-create them.')
            ->setHelp(
                <<<HELP

This command ensure that your database has the correct indexes to speed up lookups,
If you notice slowness in responses or sync, try running this command in [-f, --force-reindex]
to re-create your Indexes.

HELP
            );
    }

    protected function runCommand(InputInterface $input, OutputInterface $output): int
    {
        $inDryRunMode = $input->getOption('dry-run');

        $queries = [];

        $drop = 'DROP INDEX IF EXISTS "${name}"';
        $insert = 'CREATE INDEX IF NOT EXISTS "${name}" ON "state" (${expr});';

        $startedTransaction = false;

        if (!$inDryRunMode && !$this->db->inTransaction()) {
            $startedTransaction = true;
            $this->db->beginTransaction();
        }

        if ($input->getOption('force-reindex')) {
            $this->logger->debug('Force reindex is called.');
            $sql = "select name FROM sqlite_master WHERE tbl_name = 'state' AND type = 'index';";

            foreach ($this->db->query($sql) as $row) {
                $name = ag($row, 'name');
                $query = replacer($drop, ['name' => $name], '${', '}');
                $this->logger->debug('Dropping Index [%(index)].', [
                    'index' => $name,
                    'query' => $query,
                ]);

                $queries[] = $query;
            }
        }

        foreach (iState::ENTITY_KEYS as $column) {
            if (true === in_array($column, self::INDEX_IGNORE_ON)) {
                continue;
            }

            $query = replacer($insert, [
                'name' => sprintf('state_%s', $column),
                'expr' => sprintf('"%s"', $column),
            ],                '${', '}');

            $this->logger->debug('Generating index on [%(column)].', [
                'column' => $column,
                'query' => $query,
            ]);

            $queries[] = $query;
        }

        // -- Ensure main parent/guids sub keys are indexed.
        foreach (array_keys(Guid::getSupported()) as $subKey) {
            foreach ([iState::COLUMN_PARENT, iState::COLUMN_GUIDS] as $column) {
                $query = replacer($insert, [
                    'name' => sprintf('state_%s_%s', $column, $subKey),
                    'expr' => sprintf("JSON_EXTRACT(%s,'$.%s')", $column, $subKey),
                ],                '${', '}');

                $this->logger->debug('Generating index on %(column) column [%(key)] key.', [
                    'column' => $column,
                    'key' => $subKey,
                    'query' => $query,
                ]);

                $queries[] = $query;
            }
        }

        // -- Ensure backends metadata.id,metadata.show are indexed
        foreach (array_keys(Config::get('servers', [])) as $backend) {
            foreach (self::BACKEND_INDEXES as $subKey) {
                $query = replacer($insert, [
                    'name' => sprintf('state_%s_%s_%s', iState::COLUMN_META_DATA, $backend, $subKey),
                    'expr' => sprintf("JSON_EXTRACT(%s,'$.%s.%s')", iState::COLUMN_META_DATA, $backend, $subKey),
                ],                '${', '}');

                $this->logger->debug('Generating index on [%(backend)] metadata column [%(key)] key.', [
                    'backend' => $backend,
                    'key' => $subKey,
                    'query' => $query,
                ]);

                $queries[] = $query;
            }
        }

        if (false === $inDryRunMode) {
            foreach ($queries as $query) {
                $this->logger->debug('Running query.', [
                    'query' => $query,
                    'start' => makeDate(),
                ]);
                $this->db->query($query);
            }

            if ($startedTransaction && $this->db->inTransaction()) {
                $this->db->commit();
            }

            if ($input->getOption('force-reindex')) {
                $this->db->query('VACUUM;');
            }
        }

        return self::SUCCESS;
    }
}
