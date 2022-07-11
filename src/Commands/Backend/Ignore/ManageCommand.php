<?php

declare(strict_types=1);

namespace App\Commands\Backend\Ignore;

use App\Command;
use App\Libs\Config;
use App\Libs\Entity\StateInterface as iState;
use App\Libs\Guid;
use App\Libs\Routable;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Yaml;

#[Routable(command: self::ROUTE)]
final class ManageCommand extends Command
{
    public const ROUTE = 'backend:ignore:manage';

    protected function configure(): void
    {
        $this->setName(self::ROUTE)
            ->setDescription('Add/Remove external id from ignore list.')
            ->addOption('remove', 'r', InputOption::VALUE_NONE, 'Remove id from ignore list.')
            ->addArgument('id', InputArgument::REQUIRED, 'Id to ignore.')
            ->setHelp(
                r(
                    <<<HELP

This command allow you to ignore specific external id from backend.
This helps when there is a conflict between your media servers provided external ids.
Generally this should only be used as last resort. You should try to fix the source of the problem.

The <info>id</info> format is: <info>type</info>://<info>db</info>:<info>id</info>@<info>backend</info>[<info>?id=backend_id</info>]

-------------------
<comment>[ Expected Values ]</comment>
-------------------

<info>type</info>      expects the value to be one of [{listOfTypes}]
<info>db</info>        expects the value to be one of [{supportedGuids}]
<info>backend</info>   expects the value to be one of [{listOfBackends}]

-------
<comment>[ FAQ ]</comment>
-------

<comment># Adding external id to ignore list</comment>

To ignore <info>tvdb</info> id <info>320234</info> from <info>my_backend</info> backend you would do something like

{cmd} {route} <comment>show</comment>://<info>tvdb</info>:<info>320234</info>@<info>my_backend</info>

If you want to limit this rule to specific item id you would add [<info>?id=</info><comment>backend_id</comment>] to the rule, for example

{cmd} {route} <comment>show</comment>://<info>tvdb</info>:<info>320234</info>@<info>my_backend</info>?id=<info>1212111</info>

This will ignore [<info>tvdb://320234</info>] id only when the context id = [<info>1212111</info>]

<comment># Removing external id from ignore list</comment>

To Remove an external id from ignore list just append <info>[-r, --remove]</info> to the command. For example,

{cmd} {route} <info>--remove</info> <comment>episode</comment>://<info>tvdb</info>:<info>320234</info>@<info>my_backend</info>

The <info>id</info> should match what was added exactly.

<comment># ignore.yaml file location</comment>

By default, it should be at [<info>{ignoreListFile}</info>]

HELP,
                    [
                        'cmd' => trim(commandContext()),
                        'route' => self::ROUTE,
                        'ignoreListFile' => Config::get('path') . '/config/ignore.yaml',
                        'supportedGuids' => implode(
                            ', ',
                            array_map(fn($val) => '<comment>' . after($val, 'guid_') . '</comment>',
                                array_keys(Guid::getSupported(includeVirtual: false)))
                        ),
                        'listOfTypes' => implode(
                            ', ',
                            array_map(fn($val) => '<comment>' . after($val, 'guid_') . '</comment>', iState::TYPES_LIST)
                        ),
                        'listOfBackends' => implode(
                            ', ',
                            array_map(fn($val) => '<comment>' . after($val, 'guid_') . '</comment>',
                                array_keys(Config::get('servers', [])))
                        ),
                    ]
                )
            );
    }

    protected function runCommand(InputInterface $input, OutputInterface $output): int
    {
        $path = Config::get('path') . '/config/ignore.yaml';

        if (false === file_exists($path)) {
            touch($path);
        }

        $id = $input->getArgument('id');

        if (empty($id)) {
            throw new InvalidArgumentException('Not enough arguments (missing: "id").');
        }

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

            $id = makeIgnoreId($id);

            if (true === ag_exists($list, (string)$id)) {
                $output->writeln(
                    r(
                        '<comment>ERROR: Cannot add [{id}] as it\'s already exists. added at [{date}].</comment>',
                        [
                            'id' => $id,
                            'date' => makeDate(ag($list, (string)$id))->format('Y-m-d H:i:s T'),
                        ],
                    )
                );
                return self::FAILURE;
            }

            if (true === ag_exists($list, (string)$id->withQuery(''))) {
                $output->writeln(
                    r(
                        '<comment>ERROR: Cannot add [{id}] as [{global}] already exists. added at [{date}].</comment>',
                        [
                            'id' => (string)$id,
                            'global' => (string)$id->withQuery(''),
                            'date' => makeDate(ag($list, (string)$id->withQuery('')))->format('Y-m-d H:i:s T')
                        ]
                    )
                );
                return self::FAILURE;
            }

            $list = ag_set($list, (string)$id, time());
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
            throw new RuntimeException('No db source was given.');
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
            throw new RuntimeException('No external id was given.');
        }

        if (false === Guid::validate($db, $id)) {
            throw new RuntimeException(sprintf('Id value validation for db source \'%s\' failed.', $db));
        }

        if (null === ($type = ag($urlParts, 'scheme'))) {
            throw new RuntimeException('No type was given.');
        }

        if (false === in_array($type, iState::TYPES_LIST)) {
            throw new RuntimeException(
                sprintf(
                    'Invalid type \'%s\' was given. Expected values are \'%s\'.',
                    $type,
                    implode(', ', iState::TYPES_LIST)
                )
            );
        }

        if (null === ($backend = ag($urlParts, 'host'))) {
            throw new RuntimeException('No backend was given.');
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
