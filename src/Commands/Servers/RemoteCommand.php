<?php

declare(strict_types=1);

namespace App\Commands\Servers;

use App\Command;
use App\Libs\Config;
use App\Libs\Servers\ServerInterface;
use JsonException;
use RuntimeException;
use Symfony\Component\Console\Completion\CompletionInput;
use Symfony\Component\Console\Completion\CompletionSuggestions;
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
            ->addOption('list-libraries', null, InputOption::VALUE_NONE, 'List Server Libraries.')
            ->addOption('list-users', null, InputOption::VALUE_NONE, 'List Server users.')
            ->addOption('list-users-with-tokens', null, InputOption::VALUE_NONE, 'Show users list with tokens.')
            ->addOption('use-token', null, InputOption::VALUE_REQUIRED, 'Override server config token.')
            ->addOption('search', null, InputOption::VALUE_REQUIRED, 'Search query')
            ->addOption('search-id', null, InputOption::VALUE_REQUIRED, 'Get metadata related to given id')
            ->addOption('search-limit', null, InputOption::VALUE_REQUIRED, 'Search limit', 25)
            ->addOption('search-output', null, InputOption::VALUE_REQUIRED, 'Search output style [json,yaml]', 'json')
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

        if ($input->getOption('list-users') || $input->getOption('list-users-with-tokens')) {
            $this->listUsers($input, $output, $server);
        }

        if ($input->getOption('list-libraries')) {
            $this->listLibraries($output, $server);
        }

        if ($input->getOption('search') && $input->getOption('search-limit')) {
            $this->search($server, $output, $input);
        }

        if ($input->getOption('search') && $input->getOption('search-limit')) {
            $this->search($server, $output, $input);
        }

        if ($input->getOption('search-id')) {
            $this->searchId($server, $output, $input);
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

    private function listLibraries(
        OutputInterface $output,
        ServerInterface $server,
    ): void {
        $libraries = $server->listLibraries();

        if (count($libraries) < 1) {
            $output->writeln('<comment>No users reported by server.</comment>');
            return;
        }

        $list = [];
        $x = 0;
        $count = count($libraries);

        foreach ($libraries as $user) {
            $x++;
            $values = array_values($user);
            $list[] = $values;
            if ($x < $count) {
                $list[] = new TableSeparator();
            }
        }

        (new Table($output))->setStyle('box')->setHeaders(array_keys($libraries[0]))->setRows($list)->render();
    }

    private function search(ServerInterface $server, OutputInterface $output, InputInterface $input): void
    {
        $result = $server->search($input->getOption('search'), (int)$input->getOption('search-limit'));

        if (empty($result)) {
            $output->writeln(sprintf('<error>No results found for \'%s\'.</error>', $input->getOption('search')));
            exit(1);
        }

        if ('json' === $input->getOption('search-output')) {
            $output->writeln(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $output->writeln(Yaml::dump($result, 8, 2));
        }
    }

    private function searchId(ServerInterface $server, OutputInterface $output, InputInterface $input): void
    {
        $result = $server->searchId($input->getOption('search-id'));

        if (empty($result)) {
            $output->writeln(
                sprintf('<error>No meta data found for id \'%s\'.</error>', $input->getOption('search-id'))
            );
            exit(1);
        }

        if ('json' === $input->getOption('search-output')) {
            $output->writeln(
                json_encode(
                    value: $result,
                    flags: JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_IGNORE
                )
            );
        } else {
            $output->writeln(Yaml::dump($result, 8, 2));
        }
    }

    public function complete(CompletionInput $input, CompletionSuggestions $suggestions): void
    {
        if ($input->mustSuggestOptionValuesFor('search-output')) {
            $currentValue = $input->getCompletionValue();

            $suggest = [];

            foreach (['yaml', 'json'] as $name) {
                if (empty($currentValue) || str_starts_with($name, $currentValue)) {
                    $suggest[] = $name;
                }
            }

            $suggestions->suggestValues($suggest);
        }

        if ($input->mustSuggestArgumentValuesFor('name')) {
            $currentValue = $input->getCompletionValue();

            $suggest = [];

            foreach (array_keys(Config::get('servers', [])) as $name) {
                if (empty($currentValue) || str_starts_with($name, $currentValue)) {
                    $suggest[] = $name;
                }
            }

            $suggestions->suggestValues($suggest);
        }
    }
}
