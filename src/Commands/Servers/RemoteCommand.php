<?php

declare(strict_types=1);

namespace App\Commands\Servers;

use App\Command;
use App\Libs\Config;
use App\Libs\Extends\CliLogger;
use App\Libs\Servers\ServerInterface;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Helper\TableSeparator;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Yaml;

final class RemoteCommand extends Command
{
    protected function configure(): void
    {
        $this->setName('servers:remote')
            ->setDescription('Get info from server.')
            ->addOption('redirect-logger', 'r', InputOption::VALUE_NONE, 'Redirect logger to stdout.')
            ->addOption('use-config', null, InputOption::VALUE_REQUIRED, 'Use different servers.yaml.')
            ->addOption('list-users', null, InputOption::VALUE_NONE, 'List Server users.')
            ->addOption('list-users-with-tokens', null, InputOption::VALUE_NONE, 'Show users list with tokens.')
            ->addOption('use-token', null, InputOption::VALUE_REQUIRED, 'Override server config token.')
            ->addArgument('name', InputArgument::REQUIRED, 'Server name');
    }

    protected function runCommand(InputInterface $input, OutputInterface $output): int
    {
        // -- Use Custom servers.yaml file.
        if (($config = $input->getOption('use-config'))) {
            if (!is_string($config) || !is_file($config) || !is_readable($config)) {
                $output->writeln('<error>Unable to read data given config.</error>');
            }
            Config::save('servers', Yaml::parseFile($config));
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
            $this->listUsers($input, $output, $server, $config);
        }

        return self::SUCCESS;
    }

    private function listUsers(
        InputInterface $input,
        OutputInterface $output,
        ServerInterface $server,
        array $config = []
    ): void {
        $opts = [];

        if ($input->getOption('list-users-with-tokens')) {
            $opts['tokens'] = true;
        }

        $users = $server->getUsersList($opts);

        if (null === $users) {
            $output->writeln(
                sprintf(
                    '<error>%s: \'%s\' Does not support users concept.</error>',
                    ag($config, 'type'),
                    ag($config, 'name'),
                )
            );
            return;
        }

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
