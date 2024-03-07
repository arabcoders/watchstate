<?php

declare(strict_types=1);

namespace App\Commands\Backend\Users;

use App\Backends\Plex\Commands\AccessTokenCommand;
use App\Command;
use App\Libs\Attributes\Route\Cli;
use App\Libs\Options;
use RuntimeException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;

/**
 * Class ListCommand
 *
 * This command lists the users from the backend. The configured backend token should have access to do so otherwise,
 * or an error will be thrown. This mainly concerns plex managed users as their tokens are limited.
 */
#[Cli(command: self::ROUTE)]
final class ListCommand extends Command
{
    public const ROUTE = 'backend:users:list';

    /**
     * Configures the command.
     */
    protected function configure(): void
    {
        $this->setName(self::ROUTE)
            ->setDescription('Get backend users list.')
            ->addOption(
                'with-tokens',
                't',
                InputOption::VALUE_NONE,
                'Include access tokens in response. <notice>NOTE: if you have many plex users you will be rate limited</notice>.'
            )
            ->addOption('use-token', 'u', InputOption::VALUE_REQUIRED, 'Use this given token.')
            ->addOption('include-raw-response', null, InputOption::VALUE_NONE, 'Include unfiltered raw response.')
            ->addArgument('backend', InputArgument::REQUIRED, 'Backend name.')
            ->setHelp(
                r(
                    <<<HELP

                    This command List the users from the backend. The configured backend token should have access to do so otherwise, error will be
                    thrown this mainly concern plex managed users. as managed user token is limited.

                    -------
                    <notice>[ FAQ ]</notice>
                    -------

                    <question># How to get user tokens?</question>

                    {cmd} <cmd>{route}</cmd> <flag>--with-tokens</flag> -- <value>backend_name</value>

                    <notice>Notice: If you have many plex users and request tokens for all of them you may get rate-limited by plex api,
                    you shouldn't do this unless you have good reason. In most cases you don't need to, and can use
                    <cmd>{plex_accesstoken_command}</cmd> command to generate tokens for specific user. for example:</notice>

                    {cmd} <cmd>{plex_accesstoken_command}</cmd> -- <value>backend_name plex_user_uuid</value>

                    plex_user_uuid: is what can be seen using this list command.

                    <question># How to see the raw response?</question>

                    {cmd} <cmd>{route}</cmd> <flag>--output</flag> <value>yaml</value> <flag>--include-raw-response</flag> -- <value>backend_name</value>

                    <question># My Plex backend report only one user?</question>

                    This probably because you added a managed user instead of the default admin user, to make syncing
                    work for managed user we have to use the managed user token instead of the admin user token due to
                    plex api limitation. To see list of your users you can do the following.

                    {cmd} <cmd>{route}</cmd> <flag>--use-token</flag> <value>PLEX_TOKEN</value> -- <value>backend_name</value>

                    HELP,
                    [
                        'cmd' => trim(commandContext()),
                        'route' => self::ROUTE,
                        'plex_accesstoken_command' => AccessTokenCommand::ROUTE,
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
        $name = $input->getArgument('backend');

        $opts = $backendOpts = [];

        if ($input->getOption('with-tokens')) {
            $opts['tokens'] = true;
        }

        if ($input->getOption('include-raw-response')) {
            $opts[Options::RAW_RESPONSE] = true;
        }

        if ($input->getOption('use-token')) {
            $backendOpts = ag_set($backendOpts, 'token', $input->getOption('use-token'));
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

        $users = $backend->getUsersList(opts: $opts);

        if (count($users) < 1) {
            $output->writeln(r("<error>ERROR: No users found for '{backend}'.</error>", ['backend' => $name]));
            return self::FAILURE;
        }

        if ('table' === $mode) {
            $list = [];

            foreach ($users as $item) {
                foreach ($item as $key => $val) {
                    if (false === is_bool($val)) {
                        continue;
                    }
                    $item[$key] = $val ? 'Yes' : 'No';
                }
                $list[] = $item;
            }

            $users = $list;
        }

        $this->displayContent($users, $output, $mode);

        return self::SUCCESS;
    }
}
