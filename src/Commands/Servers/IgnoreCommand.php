<?php

declare(strict_types=1);

namespace App\Commands\Servers;

use App\Command;
use App\Libs\Config;
use App\Libs\Entity\StateInterface as iFace;
use App\Libs\Guid;
use RuntimeException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Yaml;

final class IgnoreCommand extends Command
{
    private const ID_FORMAT = '(type)://(db):(id)@(backend)';

    protected function configure(): void
    {
        $this->setName('servers:ignore')
            ->setDescription('Ignore external id for specific backend.')
            ->addOption('remove', 'r', InputOption::VALUE_NONE, 'Remove id from ignore list.')
            ->addArgument('id', InputArgument::REQUIRED, 'Specify the id in this format ' . self::ID_FORMAT)
            ->setHelp(
                <<<HELP
This command allow you to ignore specific external id from backend.
This helps when there is a conflict between your media servers provided external ids.
Generally this should only be used as last resort. You should try to fix the source of the problem.

The <info>id</info> format is: <info>type</info>://<info>db</info>:<info>id</info>@<info>backend_name</info>

For example, To ignore <info>tvdb</info> id <info>320234</info> from <info>my_home</info> backend you would do something like

For <comment>shows</comment>:
docker exec -ti watchstate console servers:ignore <comment>show</comment>://<info>tvdb</info>:<info>320234</info>@<info>my_home</info>

For <comment>movies</comment>:
docker exec -ti watchstate console servers:ignore <comment>movie</comment>://<info>tvdb</info>:<info>320234</info>@<info>my_home</info>

For <comment>episodes</comment>:
docker exec -ti watchstate console servers:ignore <comment>episode</comment>://<info>tvdb</info>:<info>320234</info>@<info>my_home</info>

<comment>
------------------------------------
** WARNING **
------------------------------------
</comment><info>
This feature is incomplete.
Only core functionality to add/remove ids from ignore list are implemented.
</info><comment>Checking ignore list against ids from backends is not implemented yet.</comment>

HELP
            );
    }

    protected function runCommand(InputInterface $input, OutputInterface $output): int
    {
        $path = Config::get('path') . '/config/ignore.yaml';

        if (false === file_exists($path)) {
            touch($path);
        }

        $id = $input->getArgument('id');
        $list = Config::get('ignore', []);

        if ($input->getOption('remove')) {
            if (false === ag_exists($list, $id)) {
                $output->writeln(sprintf('<error>Error: id \'%s\' is not ignored.</error>', $id));
                return self::FAILURE;
            }
            $list = ag_delete($list, $id);

            $output->writeln(sprintf('<info>Removed: id \'%s\' from ignore list.</info>', $id));
        } else {
            $this->checkGuid($id);
            if (true === ag_exists($list, $id)) {
                $output->writeln(
                    sprintf(
                        '<comment>Id \'%s\' already exists in the ignore list. added at \'%s\'.</comment>',
                        $id,
                        makeDate(ag($list, $id))->format('Y-m-d H:i:s T')
                    )
                );
                return self::FAILURE;
            }

            $list = ag_set($list, $id, makeDate());
            $output->writeln(sprintf('<info>Added: id \'%s\' to ignore list.</info>', $id));
        }

        @copy($path, $path . '.bak');
        @file_put_contents($path, Yaml::dump($list, 8, 2));

        return self::SUCCESS;
    }

    private function checkGuid(string $guid): void
    {
        $urlParts = parse_url($guid);

        if (null === ($db = ag($urlParts, 'user'))) {
            throw new RuntimeException('Invalid db. Expected format is ' . self::ID_FORMAT);
        }

        $sources = array_keys(Guid::getSupported(includeVirtual: false));

        if (false === in_array('guid_' . $db, $sources)) {
            throw new RuntimeException(
                sprintf(
                    'Invalid db source name \'%s\' was given. Expected values are \'%s\'.',
                    $db,
                    implode(', ', array_map(fn($f) => after($f, 'guid_'), $sources))
                )
            );
        }

        if (null === ($id = ag($urlParts, 'pass'))) {
            throw new RuntimeException('Invalid Id. Expected format is ' . self::ID_FORMAT);
        }

        if (false === Guid::validate($db, $id)) {
            throw new RuntimeException(sprintf('Id value validation for db source \'%s\' failed.', $db));
        }

        if (null === ($type = ag($urlParts, 'scheme'))) {
            throw new RuntimeException('Invalid type. Expected format is ' . self::ID_FORMAT);
        }

        $types = [iFace::TYPE_MOVIE, iFace::TYPE_SHOW, iFace::TYPE_EPISODE];

        if (false === in_array($type, $types)) {
            throw new RuntimeException(
                sprintf(
                    'Invalid type \'%s\' was given. Expected values are \'%s\'.',
                    $type,
                    implode(', ', $types)
                )
            );
        }

        if (null === ($backend = ag($urlParts, 'host'))) {
            throw new RuntimeException('Invalid backend. Expected format is ' . self::ID_FORMAT);
        }

        $backends = array_keys(Config::get('servers', []));

        if (false === in_array($backend, $backends)) {
            throw new RuntimeException(
                sprintf(
                    'Invalid backend name \'%s\' was given. Expected values are \'%s\'.',
                    $backend,
                    implode(', ', $backends)
                )
            );
        }
    }
}
