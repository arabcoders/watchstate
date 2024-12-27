<?php

declare(strict_types=1);

namespace App\Backends\Plex\Commands;

use App\Backends\Plex\PlexClient;
use App\Command;
use App\Libs\Attributes\Route\Cli;
use App\Libs\Exceptions\Backends\RuntimeException;
use App\Libs\Options;
use Psr\Log\LoggerInterface as iLogger;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface as iHttp;

#[Cli(command: self::ROUTE)]
final class DiscoverCommand extends Command
{
    public const string ROUTE = 'plex:discover';

    public function __construct(private iHttp $http, protected iLogger $logger)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName(self::ROUTE)
            ->setDescription('Discover servers linked to plex token.')
            ->addOption('with-tokens', 't', InputOption::VALUE_NONE, 'Include access tokens in response.')
            ->addOption('include-raw-response', null, InputOption::VALUE_NONE, 'Include unfiltered raw response.')
            ->addArgument('token', InputArgument::REQUIRED, 'Plex token')
            ->setHelp(
                r(
                    <<<HELP

                    This command allow you to <notice>discover</notice> servers associated with plex <notice>token</notice>.

                    -------
                    <notice>[ FAQ ]</notice>
                    -------

                    <question># How to get list servers associated with token?</question>

                    {cmd} <cmd>{route}</cmd> <flag>--with-tokens</flag> -- <value>plex_token</value>

                    <question># How to see the raw response?</question>

                    {cmd} <cmd>{route}</cmd> <flag>--output</flag> <value>yaml</value> <flag>--include-raw-response</flag> -- <value>plex_token</value>

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
     * @throws RuntimeException
     */
    protected function runCommand(InputInterface $input, OutputInterface $output, null|array $rerun = null): int
    {
        $opts = [];

        if ($input->getOption('with-tokens')) {
            $opts['with-tokens'] = true;
        }

        if ($input->getOption('include-raw-response')) {
            $opts[Options::RAW_RESPONSE] = true;
        }

        $list = PlexClient::discover($this->http, $input->getArgument('token'), $opts);

        if ('table' !== $input->getOption('output') && $input->getOption('include-raw-response')) {
            $list[Options::RAW_RESPONSE] = json_decode(json_encode((array)ag($list, Options::RAW_RESPONSE)), true);
        } else {
            $list = $list['list'];
        }

        $this->displayContent($list, $output, $input->getOption('output'));

        return self::SUCCESS;
    }
}
