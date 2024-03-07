<?php

declare(strict_types=1);

namespace App\Commands\Backend\Users;

use App\Command;
use App\Libs\Attributes\Route\Cli;
use App\Libs\Database\DatabaseInterface as iDB;
use App\Libs\Entity\StateInterface as iState;
use App\Libs\Options;
use DateTimeInterface;
use RuntimeException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Get backend active sessions.
 */
#[Cli(command: self::ROUTE)]
final class SessionsCommand extends Command
{
    public const ROUTE = 'backend:users:sessions';

    private const REMAP_FIELDS = [
        'user_name' => 'User',
        'item_title' => 'Title',
        'item_type' => 'Type',
        'item_offset_at' => 'Progress',
        'session_updated_at' => 'Last Activity',
        'session_state' => 'State',
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
            ->addOption('select-backend', 's', InputOption::VALUE_REQUIRED, 'Select backend.')
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
     */
    protected function runCommand(InputInterface $input, OutputInterface $output): int
    {
        $mode = $input->getOption('output');

        if (null === ($name = $input->getOption('select-backend'))) {
            $output->writeln(r('<error>ERROR: Backend not specified. Please use [-s, --select-backends].</error>'));
            return self::FAILURE;
        }

        $name = explode(',', $name, 2)[0];

        $opts = $backendOpts = [];

        if ($input->getOption('include-raw-response')) {
            $opts[Options::RAW_RESPONSE] = true;
        }

        if ($input->getOption('trace')) {
            $backendOpts = ag_set($opts, 'options.' . Options::DEBUG_TRACE, true);
        }

        try {
            $backend = $this->getBackend($name, $backendOpts);
        } catch (RuntimeException) {
            $output->writeln(r("<error>ERROR: Backend '{backend}' not found.</error>", ['backend' => $name]));
            return self::FAILURE;
        }

        $sessions = $backend->getSessions(opts: $opts);

        if (count($sessions) < 1) {
            $output->writeln(
                r("<notice>No active sessions were found for '{backend}'.</notice>", ['backend' => $name])
            );
            return self::FAILURE;
        }

        if ('table' === $mode) {
            $items = [];

            foreach (ag($sessions, 'sessions', []) as $item) {
                $item['item_offset_at'] = formatDuration($item['item_offset_at']);
                $item['item_type'] = ucfirst($item['item_type']);

                $entity = $this->db->findByBackendId(
                    backend: $name,
                    id: $item['item_id'],
                    type: 'Episode' === $item['item_type'] ? iState::TYPE_EPISODE : iState::TYPE_MOVIE
                );

                if (null !== $entity) {
                    $item['item_title'] = $entity->getName();
                }

                $i = [];

                foreach ($item as $key => $val) {
                    if (!array_key_exists($key, self::REMAP_FIELDS)) {
                        continue;
                    }

                    if ('session_updated_at' === $key) {
                        $val = $this->format_date($val);
                    }

                    $i[self::REMAP_FIELDS[$key]] = $val;
                }

                $items[] = $i;
            }

            $sessions = $items;
        }

        $this->displayContent($sessions, $output, $mode);

        return self::SUCCESS;
    }

    private function format_date(DateTimeInterface $date): string
    {
        $seconds = time() - $date->getTimestamp();

        if ($seconds < 1) {
            return '0s';
        }

        $string = "";

        $years = (int)($seconds / 31536000);
        $months = (int)($seconds / 2678400);
        $days = (int)($seconds / (3600 * 24));
        $hours = (int)($seconds / 3600) % 24;
        $minutes = (int)($seconds / 60) % 60;
        $seconds = $seconds % 60;

        if ($years > 0) {
            $string .= "{$days}y ";
        }

        if ($months > 0) {
            $string .= "{$days}d ";
        }

        if ($hours > 0) {
            $string .= "{$hours}h ";
        }

        if ($days < 1 && $minutes > 0) {
            $string .= "{$minutes}m ";
        }

        if ($minutes < 1 && $seconds > 0) {
            $string .= "{$seconds}s";
        }

        return $string;
    }

}
