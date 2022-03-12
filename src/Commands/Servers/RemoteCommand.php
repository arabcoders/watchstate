<?php

declare(strict_types=1);

namespace App\Commands\Servers;

use App\Command;
use App\Libs\Config;
use App\Libs\Extends\CliLogger;
use App\Libs\Servers\ServerInterface;
use JsonException;
use RuntimeException;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Helper\TableSeparator;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Yaml;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;

final class RemoteCommand extends Command
{
    protected function configure(): void
    {
        $this->setName('servers:remote')
            ->setDescription('Get info from the remote server.')
            ->addOption('redirect-logger', 'r', InputOption::VALUE_NONE, 'Redirect logger to stdout.')
            ->addOption('list-users', null, InputOption::VALUE_NONE, 'List Server users.')
            ->addOption('list-users-with-tokens', null, InputOption::VALUE_NONE, 'Show users list with tokens.')
            ->addOption('use-token', null, InputOption::VALUE_REQUIRED, 'Override server config token.')
            ->addOption('config', 'c', InputOption::VALUE_REQUIRED, 'Use Alternative config file.')
            ->addArgument('name', InputArgument::REQUIRED, 'Server name');
    }

    /**
     * @throws ExceptionInterface
     * @throws JsonException
     */
    protected function runCommand(InputInterface $input, OutputInterface $output): int
    {
        // -- Use Custom servers.yaml file.
        if (($config = $input->getOption('config'))) {
            try {
                Config::save('servers', Yaml::parseFile($this->checkCustomServersFile($config)));
            } catch (RuntimeException $e) {
                $output->writeln(sprintf('<error>%s</error>', $e->getMessage()));
                return self::FAILURE;
            }
        }

        $name = $input->getArgument('name');
        $ref = "servers.{$name}";

        if (null === Config::get("{$ref}.type", null)) {
            $output->writeln(
                sprintf('<error>No server named \'%s\' was found in %s.</error>', $name, $config)
            );
            return self::FAILURE;
        }

        $config = Config::get($ref);

        if ($input->getOption('use-token')) {
            $config['token'] = $input->getOption('use-token');
        }

        $config['name'] = $name;

        $server = makeServer($config, $name);

        if ($input->getOption('redirect-logger')) {
            $server->setLogger(new CliLogger($output));
        }

        if ($input->getOption('list-users') || $input->getOption('list-users-with-tokens')) {
            $this->listUsers($input, $output, $server);
        }

        return self::SUCCESS;
    }

    /**
     * @throws JsonException
     * @throws ExceptionInterface
     */
    private function listUsers(
        InputInterface $input,
        OutputInterface $output,
        ServerInterface $server,
    ): void {
        $opts = [];

        if ($input->getOption('list-users-with-tokens')) {
            $opts['tokens'] = true;
        }

        $users = $server->getUsersList($opts);

        if (count($users) < 1) {
            $output->writeln('<comment>No users reported by server.</comment>');
            return;
        }

        $list = [];
        $x = 0;
        $count = count($users);

        foreach ($users as $user) {
            $x++;
            $values = array_values($user);
            $list[] = $values;
            if ($x < $count) {
                $list[] = new TableSeparator();
            }
        }

        (new Table($output))->setStyle('box')->setHeaders(array_keys($users[0]))->setRows($list)->render();
    }
}
