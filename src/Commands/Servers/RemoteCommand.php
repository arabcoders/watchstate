<?php

declare(strict_types=1);

namespace App\Commands\Servers;

use App\Command;
use App\Libs\Config;
use App\Libs\Options;
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
use Throwable;

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
            ->addOption('search', null, InputOption::VALUE_REQUIRED, 'Search query.')
            ->addOption('search-id', null, InputOption::VALUE_REQUIRED, 'Get metadata related to given id.')
            ->addOption('search-raw', null, InputOption::VALUE_NONE, 'Return Unfiltered results.')
            ->addOption('search-limit', null, InputOption::VALUE_REQUIRED, 'Search limit.', 25)
            ->addOption('search-output', null, InputOption::VALUE_REQUIRED, 'Search output style [json,yaml].', 'json')
            ->addOption('search-mismatch', null, InputOption::VALUE_REQUIRED, 'Search library for possible mismatch.')
            ->addOption('search-coef', null, InputOption::VALUE_OPTIONAL, 'Mismatch similar text percentage.', 50.0)
            ->addOption(
                'timeout',
                null,
                InputOption::VALUE_OPTIONAL,
                'Request timeout in seconds.',
                Config::get('http.default.options.timeout')
            )
            ->addOption('config', 'c', InputOption::VALUE_REQUIRED, 'Use Alternative config file.')
            ->addArgument('server', InputArgument::REQUIRED, 'Server name');
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

        $name = $input->getArgument('server');
        $ref = "servers.{$name}";

        if (null === Config::get("{$ref}.type", null)) {
            $output->writeln(
                sprintf('<error>No server named \'%s\' was found in %s.</error>', $name, $config)
            );
            return self::FAILURE;
        }

        $config = Config::get($ref);

        $opts = ag($config, 'options', []);

        if ($input->getOption('use-token')) {
            $config['token'] = $input->getOption('use-token');
        }

        if ($input->getOption('timeout')) {
            $opts['client']['timeout'] = (float)$input->getOption('timeout');
        }

        if ($input->getOption('use-token')) {
            $config['token'] = $input->getOption('use-token');
        }

        $config['options'] = $opts ?? [];
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

        if ($input->getOption('search-mismatch')) {
            return $this->searchMismatch($server, $output, $input);
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
        $result = $server->search(
            query: $input->getOption('search'),
            limit: (int)$input->getOption('search-limit'),
            opts:  [
                       Options::RAW_RESPONSE => (bool)$input->getOption('search-raw')
                   ]
        );

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
        $result = $server->searchId(id: $input->getOption('search-id'), opts: [
            Options::RAW_RESPONSE => (bool)$input->getOption('search-raw'),
        ]);

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

    private function searchMismatch(ServerInterface $server, OutputInterface $output, InputInterface $input): int
    {
        $id = $input->getOption('search-mismatch');
        $percentage = (float)$input->getOption('search-coef');
        $mode = $input->getOption('search-output');

        try {
            $result = $server->searchMismatch(id: $id, opts: ['coef' => $percentage]);
        } catch (Throwable $e) {
            $this->setOutputContent(['error' => $e->getMessage()], $output, $mode);
            return self::FAILURE;
        }

        if (empty($result)) {
            $this->setOutputContent(
                [
                    'info' => sprintf(
                        'We are %1$02.2f%3$s sure there are no mis-identified items in library \'%2$s\'.',
                        $percentage,
                        $id,
                        '%',
                    )
                ],
                $output,
                $mode
            );
            return self::SUCCESS;
        }

        $this->setOutputContent($result, $output, $mode);

        return self::SUCCESS;
    }

    private function setOutputContent(array $content, OutputInterface $output, string $mode = 'json'): void
    {
        if ('json' === $mode) {
            $output->writeln(
                json_encode(
                    value: $content,
                    flags: JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_IGNORE
                )
            );
        } elseif ('table' === $mode) {
            $list = [];
            $x = 0;
            $count = count($content);

            foreach ($content as $_ => $item) {
                if (false === is_array($item)) {
                    $item = [$_ => $item];
                }

                $subItem = [];

                foreach ($item as $key => $leaf) {
                    if (true === is_array($leaf)) {
                        continue;
                    }
                    $subItem[$key] = $leaf;
                }

                $x++;
                $list[] = $subItem;
                if ($x < $count) {
                    $list[] = new TableSeparator();
                }
            }

            if (!empty($list)) {
                (new Table($output))->setStyle('box')->setHeaders(
                    array_map(fn($title) => is_string($title) ? ucfirst($title) : $title, array_keys($list[0]))
                )->setRows($list)->render();
            }
        } else {
            $output->writeln(Yaml::dump($content, 8, 2));
        }
    }

    public function complete(CompletionInput $input, CompletionSuggestions $suggestions): void
    {
        parent::complete($input, $suggestions);

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
    }
}
