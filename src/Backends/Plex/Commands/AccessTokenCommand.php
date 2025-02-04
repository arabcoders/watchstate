<?php

declare(strict_types=1);

namespace App\Backends\Plex\Commands;

use App\Command;
use App\Commands\Backend\Users\ListCommand;
use App\Libs\Attributes\Route\Cli;
use App\Libs\Config;
use App\Libs\Options;
use Psr\Log\LoggerInterface as iLogger;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;

#[Cli(command: self::ROUTE)]
final class AccessTokenCommand extends Command
{
    public const string ROUTE = 'plex:accesstoken';

    public function __construct(protected iLogger $logger)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName(self::ROUTE)
            ->setDescription('Generate Access tokens for plex backend users.')
            ->addOption('select-backend', 's', InputOption::VALUE_REQUIRED, 'Select backend.')
            ->addArgument(
                'uuid',
                InputArgument::REQUIRED,
                'User UUID as seen via [<cmd>' . ListCommand::ROUTE . '</cmd>] command.'
            )
            ->addOption('include-raw-response', null, InputOption::VALUE_NONE, 'Include unfiltered raw response.')
            ->addOption('use-token', 'u', InputOption::VALUE_REQUIRED, 'Override backend token with this one.')
            ->setHelp(
                r(
                    <<<HELP

                    This command allow you to <notice>generate</notice> limited tokens for users.

                    -------
                    <notice>[ FAQ ]</notice>
                    -------
                    <question># How to see the raw response?</question>

                    {cmd} <cmd>{route}</cmd> <flag>--output</flag> <value>yaml</value> <flag>--include-raw-response -s</flag> <value>backend_name</value> -- <value>plex_user_uuid</value>

                    HELP,
                    [
                        'cmd' => trim(commandContext()),
                        'route' => self::ROUTE,
                    ]
                )
            );
    }

    /**
     * @throws RedirectionExceptionInterface
     * @throws ClientExceptionInterface
     * @throws ServerExceptionInterface
     */
    protected function runCommand(InputInterface $input, OutputInterface $output, null|array $rerun = null): int
    {
        $uuid = $input->getArgument('uuid');
        $backend = $input->getOption('select-backend');

        if (null === ag(Config::get('servers', []), $backend, null)) {
            $output->writeln(r("<error>ERROR: Backend '{backend}' not found.</error>", ['backend' => $backend]));
            return self::FAILURE;
        }

        $opts = $backendOpts = [];

        if ($input->getOption('include-raw-response')) {
            $opts[Options::RAW_RESPONSE] = true;
        }

        if ($input->getOption('use-token')) {
            $backendOpts = ag_set($backendOpts, 'token', $input->getOption('use-token'));
        }

        if ($input->getOption('trace')) {
            $backendOpts = ag_set($opts, 'options.' . Options::DEBUG_TRACE, true);
        }

        $client = $this->getBackend($backend, $backendOpts);

        $token = $client->getUserToken(userId: $uuid, username: $client->getContext()->backendName . '_user');

        $output->writeln(
            r(
                '<info>The access token for (<value>{backend}</value>) user id (<value>{uuid}</value>) is [<value>{token}</value>].</info>',
                [
                    'uuid' => $uuid,
                    'backend' => $client->getContext()->backendName,
                    'token' => $token,
                ]
            )
        );

        return self::SUCCESS;
    }
}
