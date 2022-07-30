<?php

declare(strict_types=1);

namespace App\Commands\System;

use App\Command;
use App\Libs\Routable;
use App\Libs\Server;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[Routable(command: self::ROUTE)]
final class ServerCommand extends Command
{
    public const ROUTE = 'system:server';

    public function __construct(private Server $server)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName(self::ROUTE)
            ->setDescription('Start minimal http server.')
            ->addOption('interface', 'i', InputOption::VALUE_REQUIRED, 'Bind to interface.', '0.0.0.0')
            ->addOption('port', 'p', InputOption::VALUE_REQUIRED, 'Bind to port.', 8080)
            ->addOption('threads', 't', InputOption::VALUE_REQUIRED, 'How many threads to use.', 1)
            ->setHelp(
                <<<HELP

                This server is not meant to be used in production. It is mainly for testing purposes.

                HELP
            );
    }

    protected function runCommand(InputInterface $input, OutputInterface $output): int
    {
        $host = $input->getOption('interface');
        $port = (int)$input->getOption('port');
        $threads = (int)$input->getOption('threads');

        $this->server = $this->server->withInterface($host)->withPort($port)->withThreads($threads)
            ->runInBackground(
                fn($std, $out) => $output->writeln(trim($out), OutputInterface::VERBOSITY_VERBOSE)
            );

        $output->writeln(
            r('Listening on \'http://{host}:{port}\' for webhook events.', [
                'host' => $host,
                'port' => $port,
            ])
        );

        $this->server->wait();

        return self::SUCCESS;
    }
}
