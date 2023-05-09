<?php

declare(strict_types=1);

namespace App\Commands\Config;

use App\Command;
use App\Libs\Config;
use App\Libs\Database\DatabaseInterface as iDB;
use App\Libs\Routable;
use RuntimeException;
use Symfony\Component\Console\Exception\ExceptionInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Yaml\Yaml;

#[Routable(command: self::ROUTE)]
final class DeleteCommand extends Command
{
    public const ROUTE = 'config:delete';
    private \PDO $pdo;

    public function __construct(private iDB $db)
    {
        $this->pdo = $this->db->getPDO();

        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName(self::ROUTE)
            ->setDescription('Delete Local backend data.')
            ->addOption('config', 'c', InputOption::VALUE_REQUIRED, 'Use Alternative config file.')
            ->addArgument('backend', InputArgument::REQUIRED, 'Backend name.')
            ->setHelp(
                r(
                    <<<HELP

                    This command will <error>REMOVE</error> db entries related to the given backend.
                    However, it will <error>NOT</error> remove data from previous backups.

                    This command will do the following:

                    1. Remove any db entries that was related to the <notice>backend</notice>.
                    2. Remove backend from <notice>servers.yaml</notice> file.

                    HELP,
                )
            );
    }

    /**
     * @throws ExceptionInterface
     */
    protected function runCommand(InputInterface $input, OutputInterface $output, null|array $rerun = null): int
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
            } catch (RuntimeException $e) {
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

        $u = $rerun ?? ag($backends, $name, []);
        $u['name'] = $name;

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

        $output->writeln('');

        if (false === $helper->ask($input, $output, $question)) {
            $output->writeln(
                r('Backend [<value>{backend}</value>] removal was cancelled.', [
                    'backend' => $name
                ])
            );
            return self::FAILURE;
        }

        $output->writeln(
            r('Removing [<value>{backend}</value>] database entries. This might take a while. Please wait...', [
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
                'Unlinked [<value>{records}</value>] records referenced by [<value>{backend}</value>].',
                [
                    'records' => number_format($stmt->rowCount()),
                    'backend' => $name,
                ]
            )
        );

        $output->writeln('Trying to delete dead records. This might take a while. Please wait...');

        $sql = "DELETE FROM
                    state
                WHERE id IN (
                    SELECT id FROM state WHERE length(metadata) < 10
                )
        ";
        $stmt = $this->pdo->query($sql);

        $output->writeln(
            r('Removed [<value>{records}</value>] records that are no longer used.', [
                'records' => number_format($stmt->rowCount()),
            ])
        );

        if (false === $custom) {
            copy($config, $config . '.bak');
        }

        $backends = ag_delete($backends, $name);

        file_put_contents($config, Yaml::dump($backends, 8, 2));

        $output->writeln('<info>Config updated.</info>');

        return self::SUCCESS;
    }
}
