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
            ->addArgument('id', InputArgument::REQUIRED, 'id.')
            ->setHelp(
                r(
                    <<<HELP

                    This command allow you to ignore specific external id from backend.
                    This helps when there is a conflict between your media servers provided external ids.
                    Generally this should only be used as last resort. You should try to fix the source of the problem.

                    The <notice>id</notice> format is: <flag>type</flag>://<flag>db</flag>:<flag>id</flag>@<flag>backend</flag>[?id=<flag>backend_item_id</flag>]

                    -------------------
                    <notice>[ Expected Values ]</notice>
                    -------------------

                    <flag>type</flag>      expects the value to be one of [{listOfTypes}]
                    <flag>db</flag>        expects the value to be one of [{supportedGuids}]
                    <flag>backend</flag>   expects the value to be one of [{listOfBackends}]

                    -------
                    <notice>[ FAQ ]</notice>
                    -------

                    <question># Adding external id to ignore list</question>

                    To ignore <value>tvdb</value> id <value>320234</value> from <value>my_backend</value> you would do something like

                    {cmd} <cmd>{route}</cmd> -- <value>show</value>://<value>tvdb</value>:<value>320234</value>@<value>my_backend</value>

                    If you want to limit this rule to specific item id you would add [<flag>?id=</flag><value>backend_item_id</value>] to the rule, for example

                    {cmd} <cmd>{route}</cmd> -- <value>show</value>://<value>tvdb</value>:<value>320234</value>@<value>my_backend</value><flag>?id=</flag><value>1212111</value>

                    This will ignore the external id [<value>tvdb://320234</value>] only when the context id = [<value>1212111</value>]

                    <question># Removing external id from ignore list</question>

                    To Remove an external id from ignore list just append [<flag>-r, --remove</flag>] to the command. For example,

                    {cmd} <cmd>{route}</cmd> <flag>--remove</flag> -- <value>episode</value>://<value>tvdb</value>:<value>320234</value>@<value>my_backend</value>

                    The <notice>id</notice> should match what was added.

                    <question># ignore.yaml file location</question>

                    By default, it should be at [<value>{ignoreListFile}</value>]

                    HELP,
                    [
                        'cmd' => trim(commandContext()),
                        'route' => self::ROUTE,
                        'ignoreListFile' => Config::get('path') . '/config/ignore.yaml',
                        'supportedGuids' => implode(
                            ', ',
                            array_map(fn($val) => '<value>' . after($val, 'guid_') . '</value>',
                                array_keys(Guid::getSupported(includeVirtual: false)))
                        ),
                        'listOfTypes' => implode(
                            ', ',
                            array_map(fn($val) => '<value>' . after($val, 'guid_') . '</value>', iState::TYPES_LIST)
                        ),
                        'listOfBackends' => implode(
                            ', ',
                            array_map(fn($val) => '<value>' . after($val, 'guid_') . '</value>',
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
