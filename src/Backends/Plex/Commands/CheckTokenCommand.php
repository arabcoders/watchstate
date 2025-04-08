<?php

declare(strict_types=1);

namespace App\Backends\Plex\Commands;

use App\Backends\Plex\PlexClient;
use App\Command;
use App\Libs\Attributes\Route\Cli;
use App\Libs\Options;
use Psr\Log\LoggerInterface as iLogger;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface as iInput;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface as iOutput;
use Symfony\Contracts\HttpClient\HttpClientInterface as iHttp;
use Throwable;

#[Cli(command: self::ROUTE)]
final class CheckTokenCommand extends Command
{
    public const string ROUTE = 'plex:check_token';

    public function __construct(private iHttp $http, protected iLogger $logger)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName(self::ROUTE)
            ->setDescription('Check if given plex token is valid.')
            ->addOption('include-raw-response', null, InputOption::VALUE_NONE, 'Include unfiltered raw response.')
            ->addArgument('token', InputArgument::REQUIRED, 'Plex token')
            ->setHelp(
                r(
                    <<<HELP

                    This command allow you to <notice>check</notice> whether the given plex <notice>token</notice> is valid or not.

                    -------
                    <notice>[ FAQ ]</notice>
                    -------

                    <question># Will this work for limited i.e. non-admin tokens?</question>

                    No

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

    protected function runCommand(iInput $input, iOutput $output): int
    {
        $mode = $input->getOption('output');
        $opts = [];

        $raw = null;
        if ($input->getOption('include-raw-response')) {
            $opts[Options::RAW_RESPONSE_CALLBACK] = function ($response) use (&$raw) {
                $raw = $response;
            };
        }

        try {
            $status = PlexClient::validate_token($this->http, $input->getArgument('token'), $opts);
            
            if (true === $status) {
                $output->writeln('<info>SUCCESS</info> <value>Token is valid</value>');
                return self::SUCCESS;
            }
        } catch (Throwable $e) {
            $output->writeln("<error>[ERROR]</error> <value>{$e->getMessage()}</value>");
            return Command::FAILURE;
        } finally {
            if (null !== $raw && 'table' !== $mode) {
                $this->displayContent($raw, $output, $mode);
            }
        }

        return self::FAILURE;
    }
}
