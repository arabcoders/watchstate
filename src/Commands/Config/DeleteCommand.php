<?php

declare(strict_types=1);

namespace App\Commands\Config;

use App\Command;
use App\Libs\Config;
use App\Libs\Database\DatabaseInterface as iDB;
use App\Libs\Routable;
use App\Libs\Stream;
use PDO;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Yaml\Yaml;

/**
 * Class DeleteCommand
 *
 * This command allows you to delete local backend data from the database.
 *
 * @package App\Command
 */
#[Routable(command: self::ROUTE)]
final class DeleteCommand extends Command
{
    public const ROUTE = 'config:delete';
    private PDO $pdo;

    /**
     * Class constructor.
     *
     * @param iDB $db The iDB instance used for database interaction.
     *
     * @return void
     */
    public function __construct(private iDB $db)
    {
        $this->pdo = $this->db->getPDO();

        parent::__construct();
    }

    /**
     * Configure the command.
     */
    protected function configure(): void
    {
        $this->setName(self::ROUTE)
            ->setDescription('Delete Local backend data.')
            ->addOption('config', 'c', InputOption::VALUE_REQUIRED, 'Use Alternative config file.')
            ->addArgument('backend', InputArgument::REQUIRED, 'Backend name.')
            ->setHelp(
                r(
                    <<<HELP

                    This command allows you to delete local backend data from the database.

                    This command require <notice>interaction</notice> to work.

                    This command will do the following:

                    1. Remove records metadata that references the given <notice>backend</notice>.
                    2. Run data integrity check to remove no longer used records.
                    2. Update <value>servers.yaml</value> file and remove <notice>backend</notice> configuration.

                    ------------------
                    <notice>WARNING:</notice> This command works on the current active database. it will <error>NOT</error> delete
                    data from the backend itself or the backups stored at [<value>{backupDir}</value>].
                    ------------------

                    HELP,
                    [
                        'backupDir' => after(Config::get('path') . '/backup', ROOT_PATH),
                    ]
                )
            );
    }

    /**
     * Runs the command to remove a backend from the database.
     *
     * @param InputInterface $input The input interface instance for retrieving command input.
     * @param OutputInterface $output The output interface instance for displaying command output.
     *
     * @return int The status code indicating the success or failure of the command execution.
     */
    protected function runCommand(InputInterface $input, OutputInterface $output): int
    {
        if (function_exists('stream_isatty') && defined('STDERR')) {
            $tty = stream_isatty(STDERR);
        } else {
            $tty = true;
        }

        if (false === $tty || $input->getOption('no-interaction')) {
            $output->writeln(
                r(
                    <<<ERROR

                    <error>ERROR:</error> This command require <notice>interaction</notice>. For example:

                    {cmd} <cmd>{route}</cmd> -- <value>{backend}</value>

                    ERROR,
                    [
                        'cmd' => trim(commandContext()),
                        'route' => self::ROUTE,
                        'backend' => $input->getArgument('backend'),
                    ]
                )

            );
            return self::FAILURE;
        }

        $custom = false;

        // -- Use Custom servers.yaml file.
        if (($config = $input->getOption('config'))) {
            try {
                $custom = true;
                $backends = (array)Yaml::parseFile($this->checkCustomBackendsFile($config));
            } catch (\App\Libs\Exceptions\RuntimeException $e) {
                $output->writeln(r('<error>ERROR:</error> {error}', ['error' => $e->getMessage()]));
                return self::FAILURE;
            }
        } else {
            $config = Config::get('path') . '/config/servers.yaml';
            if (!file_exists($config)) {
                touch($config);
            }
            $backends = (array)Config::get('servers', []);
        }

        $name = $input->getArgument('backend');

        if (!isValidName($name) || strtolower($name) !== $name) {
            $output->writeln(
                r(
                    '<error>ERROR:</error> Invalid [<value>{name}</value>] name was given. Only [<value>a-z, 0-9, _</value>] are allowed.',
                    [
                        'name' => $name
                    ],
                )
            );
            return self::FAILURE;
        }

        if (null === ag($backends, "{$name}.type", null)) {
            $output->writeln(
                r('<error>ERROR:</error> No backend named [<value>{backend}</value>] was found.', [
                    'backend' => $name,
                ])
            );
            return self::FAILURE;
        }

        $helper = $this->getHelper('question');

        $question = new ConfirmationQuestion(
            r(
                <<<HELP
                    <question>Are you sure you want to remove [<value>{name}</value>] data?</question>? {default}
                    ------------------
                    <notice>WARNING:</notice> This command will remove entries from database related to the backend.
                    Database records will be removed if [<value>{name}</value>] was the only backend referencing them.
                    Otherwise, they will be kept and only reference to the backend will be removed.
                    ------------------
                    <notice>For more information please read the FAQ.</notice>
                    HELP. PHP_EOL . '> ',
                [
                    'name' => $name,
                    'default' => '[<value>Y|N</value>] [<value>Default: No</value>]',
                ]
            ),
            false
        );

        $response = $helper->ask($input, $output, $question);
        $output->writeln('');

        if (false === $response) {
            $output->writeln(
                r('Backend [<value>{backend}</value>] removal was cancelled.', [
                    'backend' => $name
                ])
            );
            return self::FAILURE;
        }

        $output->writeln(
            r('Removing [<value>{backend}</value>] database references. This might take a while. Please wait...', [
                'backend' => $name
            ])
        );

        $sql = "UPDATE
                    state
                SET
                    metadata = json_remove(metadata, '$.{$name}'),
                    extra = json_remove(extra, '$.{$name}')
                WHERE
                    json_extract(metadata,'$.{$name}.via') = :name
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['name' => $name]);

        $output->writeln(
            r(
                'Removed [<value>{records}</value>] metadata references related to [<value>{backend}</value>].',
                [
                    'records' => number_format($stmt->rowCount()),
                    'backend' => $name,
                ]
            )
        );

        $output->writeln('Checking data integrity, this might take a while. Please wait...');

        $sql = "DELETE FROM
                    state
                WHERE id IN (
                    SELECT id FROM state WHERE length(metadata) < 10
                )
        ";
        $stmt = $this->pdo->query($sql);

        $deleteCount = $stmt->rowCount();

        if ($deleteCount > 1) {
            $output->writeln(
                r('Removed [<value>{records}</value>] records that are no longer valid.', [
                    'records' => number_format($stmt->rowCount()),
                ])
            );
        }

        if (false === $custom) {
            copy($config, $config . '.bak');
        }

        $backends = ag_delete($backends, $name);

        $stream = new Stream($config, 'w');
        $stream->write(Yaml::dump($backends, 8, 2));
        $stream->close();

        $output->writeln('<info>Config updated.</info>');

        return self::SUCCESS;
    }
}
