<?php

declare(strict_types=1);

namespace App\Commands\Backend\Users;

use App\Command;
use App\Libs\Config;
use App\Libs\Database\DatabaseInterface as iDB;
use App\Libs\Entity\StateInterface as iState;
use App\Libs\Options;
use App\Libs\Routable;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Yaml;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;

/**
 * Class ListCommand
 *
 */
#[Routable(command: self::ROUTE)]
final class SessionsCommand extends Command
{
    public const ROUTE = 'backend:users:sessions';

    private const REMAP_FIELDS = [
        'user_uid' => 'User ID',
        'user_uuid' => 'User UUID',
        'item_id' => 'Item ID',
        'item_title' => 'Title',
        'item_type' => 'Type',
        'item_offset_at' => 'Progress',
        'session_id' => 'Session ID',
        'session_updated_at' => 'Session Activity',
        'session_state' => 'Play State',
    ];

    public function __construct(private iDB $db)
    {
        parent::__construct();
    }

    /**
     * Configures the command.
     */
    protected function configure(): void
    {
        $this->setName(self::ROUTE)
            ->setDescription('Get backend active sessions.')
            ->addOption('include-raw-response', null, InputOption::VALUE_NONE, 'Include unfiltered raw response.')
            ->addOption('config', 'c', InputOption::VALUE_REQUIRED, 'Use Alternative config file.')
            ->addOption('select-backends', 's', InputOption::VALUE_REQUIRED, 'Select backends.')
            ->setHelp(
                r(
                    <<<HELP

                    Get active sessions for all users in a backend.

                    -------
                    <notice>[ FAQ ]</notice>
                    -------

                    <question># How to see the raw response?</question>

                    {cmd} <cmd>{route}</cmd> <flag>--output</flag> <value>yaml</value> <flag>--include-raw-response</flag> <flag>-s</flag> <value>backend_name</value>

                    HELP,
                    [
                        'cmd' => trim(commandContext()),
                        'route' => self::ROUTE,
                    ]
                )
            );
    }

    /**
     * Runs the command.
     *
     * @param InputInterface $input The input interface.
     * @param OutputInterface $output The output interface.
     *
     * @return int The exit status code.
     * @throws ExceptionInterface When the request fails.
     * @throws \JsonException When the response cannot be parsed.
     */
    protected function runCommand(InputInterface $input, OutputInterface $output): int
    {
        $mode = $input->getOption('output');
        $backend = $input->getOption('select-backends');

        if (null === $backend) {
            $output->writeln(r('<error>ERROR: Backend not specified. Please use [-s, --select-backends].</error>'));
            return self::FAILURE;
        }

        $backend = explode(',', $backend, 1)[0];

        // -- Use Custom servers.yaml file.
        if (($config = $input->getOption('config'))) {
            try {
                Config::save('servers', Yaml::parseFile($this->checkCustomBackendsFile($config)));
            } catch (\App\Libs\Exceptions\RuntimeException $e) {
                $output->writeln(r('<error>{message}</error>', ['message' => $e->getMessage()]));
                return self::FAILURE;
            }
        }

        if (null === ag(Config::get('servers', []), $backend, null)) {
            $output->writeln(r("<error>ERROR: Backend '{backend}' not found.</error>", ['backend' => $backend]));
            return self::FAILURE;
        }

        $opts = $backendOpts = [];

        if ($input->getOption('include-raw-response')) {
            $opts[Options::RAW_RESPONSE] = true;
        }

        if ($input->getOption('trace')) {
            $backendOpts = ag_set($opts, 'options.' . Options::DEBUG_TRACE, true);
        }

        $sessions = $this->getBackend($backend, $backendOpts)->getSessions(opts: $opts);

        if (count($sessions) < 1) {
            $output->writeln(
                r("<notice>No active sessions were found for '{backend}'.</notice>", ['backend' => $backend])
            );
            return self::FAILURE;
        }

        if ('table' === $mode) {
            $items = [];

            foreach (ag($sessions, 'sessions', []) as $item) {
                $item['item_offset_at'] = formatDuration($item['item_offset_at']);
                $item['item_type'] = ucfirst($item['item_type']);

                $entity = $this->db->findByBackendId(
                    backend: $backend,
                    id: $item['item_id'],
                    type: 'Episode' === $item['item_type'] ? iState::TYPE_EPISODE : iState::TYPE_MOVIE
                );

                if (null !== $entity) {
                    $item['item_title'] = $entity->getName();
                }

                $i = [];

                foreach ($item as $key => $val) {
                    $i[self::REMAP_FIELDS[$key] ?? $key] = $val;
                }

                $items[] = $i;
            }

            $sessions = $items;
        }

        $this->displayContent($sessions, $output, $mode);

        return self::SUCCESS;
    }

}
