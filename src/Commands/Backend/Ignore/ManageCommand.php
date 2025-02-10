<?php

declare(strict_types=1);

namespace App\Commands\Backend\Ignore;

use App\Command;
use App\Libs\Attributes\Route\Cli;
use App\Libs\Config;
use App\Libs\Entity\StateInterface as iState;
use App\Libs\Enums\Http\Status;
use App\Libs\Exceptions\InvalidArgumentException;
use App\Libs\Guid;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface as iInput;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface as iOutput;

/**
 * Class ManageCommand
 * This class is responsible for adding or removing an external id from the ignore list.
 */
#[Cli(command: self::ROUTE)]
final class ManageCommand extends Command
{
    public const string ROUTE = 'backend:ignore:manage';

    /**
     * Configure the command.
     */
    protected function configure(): void
    {
        $this->setName(self::ROUTE)
            ->setDescription('Add/remove a ignore rule.')
            ->addOption('user', 'u', InputOption::VALUE_REQUIRED, 'Select user.', 'main')
            ->addOption('remove', 'r', InputOption::VALUE_NONE, 'Remove rule from ignore list.')
            ->addArgument('rule', InputArgument::REQUIRED, 'rule')
            ->setHelp(
                r(
                    <<<HELP

                    This command allow you to ignore specific GUID from backend.
                    This helps when there is a conflict between your media servers provided GUIDs.
                    Generally this should only be used as last resort. You should try to fix the source of the problem.

                    The <notice>rule</notice> format is: <flag>type</flag>://<flag>db</flag>:<flag>id</flag>@<flag>backend</flag>[?id=<flag>backend_item_id</flag>]

                    -------------------
                    <notice>[ Expected Values ]</notice>
                    -------------------

                    <flag>type</flag>      Expects the value to be one of [{listOfTypes}]
                    <flag>db</flag>        Expects the value to be one of [{supportedGuids}]
                    <flag>backend</flag>   The User backend name.

                    -------
                    <notice>[ FAQ ]</notice>
                    -------

                    <question># Managing sub users ignore list</question>

                    To add or remove a rule from ignore list for sub-user, simply append [<flag>-u, --user</flag>] flag to the command.

                    <question># Adding GUID to ignore list</question>

                    To ignore <value>tvdb</value> id <value>320234</value> from <value>my_backend</value> you would do something like

                    {cmd} <cmd>{route}</cmd> -- <value>show</value>://<value>tvdb</value>:<value>320234</value>@<value>my_backend</value>

                    If you want to limit this rule to specific item id you would add [<flag>?id=</flag><value>backend_item_id</value>] to the rule, for example

                    {cmd} <cmd>{route}</cmd> -- <value>show</value>://<value>tvdb</value>:<value>320234</value>@<value>my_backend</value><flag>?id=</flag><value>1212111</value>

                    This will ignore the GUID [<value>tvdb://320234</value>] only when the context id = [<value>1212111</value>]

                    <question># Removing GUID from ignore list</question>

                    To Remove an external id from ignore list just append [<flag>-r, --remove</flag>] to the command. For example,

                    {cmd} <cmd>{route}</cmd> <flag>--remove</flag> -- <value>episode</value>://<value>tvdb</value>:<value>320234</value>@<value>my_backend</value>

                    The <notice>rule</notice> should match what was added.

                    HELP,
                    [
                        'cmd' => trim(commandContext()),
                        'route' => self::ROUTE,
                        'supportedGuids' => implode(
                            ', ',
                            array_map(
                                fn($val) => '<value>' . after($val, 'guid_') . '</value>',
                                array_keys(Guid::getSupported())
                            )
                        ),
                        'listOfTypes' => implode(
                            ', ',
                            array_map(fn($val) => '<value>' . after($val, 'guid_') . '</value>', iState::TYPES_LIST)
                        ),
                    ]
                )
            );
    }

    /**
     * Run the command.
     *
     * @param iInput $input The input interface.
     * @param iOutput $output The output interface.
     *
     * @return int The command status code.
     */
    protected function runCommand(iInput $input, iOutput $output): int
    {
        $rule = $input->getArgument('rule');

        if (empty($rule)) {
            $output->writeln('<error>Rule argument cannot be empty.</error>');
            return self::FAILURE;
        }

        $opts = [
            'headers' => [
                'X-User' => $input->getOption('user'),
            ],
        ];

        if ($input->getOption('remove')) {
            $response = APIRequest('DELETE', '/ignore/', ['rule' => $rule], opts: $opts);

            if (Status::OK !== $response->status) {
                $output->writeln(r("<error>{status}: {message}</error>", [
                    'key' => $rule,
                    'status' => $response->status->value,
                    'message' => ag($response->body, 'error.message', 'Unknown error.')
                ]));
                return self::FAILURE;
            }

            $output->writeln(r("<info>Rule '{rule}' removed from ignore list.</info>", ['rule' => $rule]));
            return self::SUCCESS;
        }

        $response = APIRequest('POST', '/ignore/', ['rule' => $rule], opts: $opts);

        if (Status::OK !== $response->status) {
            $output->writeln(r("<error>{status}: {message}</error>", [
                'key' => $rule,
                'status' => $response->status->value,
                'message' => ag($response->body, 'error.message', 'Unknown error.')
            ]));
            return self::FAILURE;
        }

        $output->writeln(r("<info>Rule '{rule}' added to ignore list.</info>", ['rule' => $rule]));

        return self::SUCCESS;
    }
}
