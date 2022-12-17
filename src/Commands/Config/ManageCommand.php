<?php

declare(strict_types=1);

namespace App\Commands\Config;

use App\Command;
use App\Commands\State\ImportCommand;
use App\Commands\System\IndexCommand;
use App\Libs\Config;
use App\Libs\Options;
use App\Libs\Routable;
use RuntimeException;
use Symfony\Component\Console\Exception\ExceptionInterface;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Yaml\Yaml;
use Throwable;

#[Routable(command: self::ROUTE)]
final class ManageCommand extends Command
{
    public const ROUTE = 'config:manage';

    protected function configure(): void
    {
        $this->setName(self::ROUTE)
            ->setDescription('Manage backend settings.')
            ->addOption('add', 'a', InputOption::VALUE_NONE, 'Add Backend.')
            ->addOption('config', 'c', InputOption::VALUE_REQUIRED, 'Use Alternative config file.')
            ->addArgument('backend', InputArgument::REQUIRED, 'Backend name.')
            ->setHelp(
                r(
                    <<<HELP

                    This command allows you to manage backend settings.
                    This command require <notice>interaction</notice> to work.

                    HELP,
                )
            );
    }

    /**
     * @throws ExceptionInterface
     */
    protected function runCommand(InputInterface $input, OutputInterface $output, null|array $rerun = null): int
    {
        if (function_exists('stream_isatty') && defined('STDERR')) {
            $tty = stream_isatty(STDERR);
        } else {
            $tty = true;
        }

        if (false === $tty || $input->getOption('no-interaction')) {
            $output->writeln(
                r(
                    <<<ERROR

                    <error>ERROR:</error> This command require <notice>interaction</notice>. For example:

                    {cmd} <cmd>{route}</cmd> -- <value>{backend_name}</value>

                    ERROR,
                    [
                        'cmd' => trim(commandContext()),
                        'route' => self::ROUTE,
                        'backend' => $input->getArgument('backend'),
                    ]
                )

            );
            return self::FAILURE;
        }

        $custom = false;

        // -- Use Custom servers.yaml file.
        if (($config = $input->getOption('config'))) {
            try {
                $custom = true;
                $backends = (array)Yaml::parseFile($this->checkCustomBackendsFile($config));
            } catch (RuntimeException $e) {
                $output->writeln(r('<error>ERROR:</error> {error}', ['error' => $e->getMessage()]));
                return self::FAILURE;
            }
        } else {
            $config = Config::get('path') . '/config/servers.yaml';
            if (!file_exists($config)) {
                touch($config);
            }
            $backends = (array)Config::get('servers', []);
        }

        $add = $input->getOption('add');
        $name = $input->getArgument('backend');

        if (!isValidName($name) || strtolower($name) !== $name) {
            $output->writeln(
                r(
                    '<error>ERROR:</error> Invalid [<value>{name}</value>] name was given. Only [<value>a-z, 0-9, _</value>] are allowed.',
                    [
                        'name' => $name
                    ],
                )
            );
            return self::FAILURE;
        }

        if (true === $add) {
            if (null !== ag($backends, "{$name}.type", null)) {
                $output->writeln(
                    r(
                        '<error>ERROR:</error> Backend with [<value>{backend}</value>] name already exists. Omit the [<flag>--add</flag>] flag if you want to edit the backend settings.',
                        [
                            'backend' => $name,
                        ],
                    )
                );
                return self::FAILURE;
            }
        } elseif (null === ag($backends, "{$name}.type", null)) {
            $output->writeln(
                r(
                    '<error>ERROR:</error> No backend named [<value>{backend}</value>] was found. Append [<flag>--add</flag>] to add as new backend.',
                    [
                        'backend' => $name,
                    ]
                )
            );
            return self::FAILURE;
        }

        $u = $rerun ?? ag($backends, $name, []);

        $output->writeln('');

        // -- $name.type
        (function () use ($input, $output, &$u, $name) {
            $list = array_keys(Config::get('supported', []));
            $chosen = ag($u, 'type');

            $helper = $this->getHelper('question');
            $choice = array_search($chosen, $list);

            $question = new ChoiceQuestion(
                r('<question>Select [<value>{name}</value>] type</question>. {default}', [
                    'name' => $name,
                    'default' => null !== $chosen ? "[<value>Default: {$chosen}</value>]" : '',
                ]),
                $list,
                false === $choice ? null : $choice
            );

            $question->setNormalizer(fn($answer) => is_string($answer) ? strtolower($answer) : $answer);

            $question->setAutocompleterValues($list);

            $question->setErrorMessage('Invalid value [%s] was selected.');

            $type = $helper->ask($input, $output, $question);

            $u = ag_set($u, 'type', $type);
        })();
        $output->writeln('');

        // -- $name.url
        (function () use ($input, $output, &$u, $name) {
            $helper = $this->getHelper('question');
            $chosen = ag($u, 'url');
            $question = new Question(
                r('<question>Enter [<value>{name}</value>] URL</question>. {default}' . PHP_EOL . '> ', [
                    'name' => $name,
                    'default' => null !== $chosen ? "[<value>Default: {$chosen}</value>]" : '',
                ]),
                $chosen
            );

            $question->setValidator(function ($answer) {
                if (!filter_var($answer, FILTER_VALIDATE_URL)) {
                    throw new RuntimeException('Invalid backend URL was given.');
                }
                return $answer;
            });

            $url = $helper->ask($input, $output, $question);
            $u = ag_set($u, 'url', $url);
        })();
        $output->writeln('');

        // -- $name.token
        (function () use ($input, $output, &$u, $name) {
            $helper = $this->getHelper('question');
            $chosen = ag($u, 'token');
            $question = new Question(
                r('<question>Enter [<value>{name}</value>] API token</question>. {default}' . PHP_EOL . '> ', [
                    'name' => $name,
                    'default' => null !== $chosen ? "<value>[Default: {$chosen}]</value>" : '',
                ]),
                $chosen
            );

            $question->setValidator(function ($answer) {
                if (empty($answer)) {
                    throw new RuntimeException('Token value cannot be empty or null.');
                }

                if (!is_string($answer) && !is_int($answer)) {
                    throw new RuntimeException(
                        r(
                            'Invalid token was given. Expecting string or integer, but got \'{type}\' instead.',
                            ['type' => get_debug_type($answer)]
                        )
                    );
                }
                return $answer;
            });

            $token = $helper->ask($input, $output, $question);
            $u = ag_set($u, 'token', $token);
        })();
        $output->writeln('');

        // -- $name.uuid
        (function () use ($input, $output, &$u, $name) {
            try {
                $output->writeln(
                    '<info>Getting backend unique identifier. Please wait...</info>'
                );

                $backend = array_replace_recursive($u, ['options' => ['client' => ['timeout' => 10]]]);
                $chosen = ag($u, 'uuid', fn() => makeBackend($backend, $name)->getIdentifier(true));
            } catch (Throwable $e) {
                $output->writeln('<error>Failed to get the backend unique identifier.</error>');
                $output->writeln(
                    sprintf(
                        '<error>ERROR - %s: %s.</error>' . PHP_EOL,
                        afterLast(get_class($e), '\\'),
                        $e->getMessage()
                    )
                );
                $chosen = null;
            }

            $helper = $this->getHelper('question');
            $question = new Question(
                r(
                    '<question>Enter [<value>{name}</value>] backend unique identifier</question>. {default}' . PHP_EOL . '> ',
                    [
                        'name' => $name,
                        'default' => null !== $chosen ? "<value>[Default: {$chosen}]</value>" : '',
                    ]
                ),
                $chosen
            );

            $question->setValidator(function ($answer) {
                if (empty($answer)) {
                    throw new RuntimeException('Backend unique identifier cannot be empty.');
                }

                if (!is_string($answer) && !is_int($answer)) {
                    throw new RuntimeException(
                        sprintf(
                            'Backend unique identifier is invalid. Expecting [string|integer]. but got \'%s\' instead.',
                            get_debug_type($answer)
                        )
                    );
                }
                return $answer;
            });

            $uuid = $helper->ask($input, $output, $question);
            $u = ag_set($u, 'uuid', $uuid);
        })();
        $output->writeln('');

        // -- $name.user
        (function () use ($input, $output, &$u, $name) {
            $chosen = ag($u, 'user');

            try {
                $output->writeln(
                    '<info>Trying to get users list from backend. Please wait...</info>'
                );

                $list = $map = $ids = $userInfo = [];
                $backend = array_replace_recursive($u, ['options' => ['client' => ['timeout' => 5]]]);

                try {
                    $users = makeBackend($backend, $name)->getUsersList(['tokens' => true]);
                    if (empty($users)) {
                        throw new RuntimeException('Backend returned empty list of users.');
                    }
                } catch (Throwable $e) {
                    // -- Check admin token.
                    $adminToken = ag($u, 'options.' . Options::ADMIN_TOKEN);
                    if (null !== $adminToken && $adminToken !== ag($u, 'token')) {
                        $output->writeln(
                            r(
                                '<notice>Backend returned an error \'{error}\'. Attempting to use admin token.</notice>',
                                [
                                    'error' => $e->getMessage()
                                ]
                            )
                        );

                        $backend['token'] = $adminToken;
                        $users = makeBackend($backend, $name)->getUsersList(['tokens' => true]);
                        if (empty($users)) {
                            throw new RuntimeException('Backend returned empty list of users.');
                        }
                    } else {
                        throw $e;
                    }
                }

                foreach ($users as $user) {
                    $uid = ag($user, 'id');
                    $val = ag($user, 'name', '??');
                    $list[] = $val;
                    $ids[$uid] = $val;
                    $map[$val] = $uid;
                    $userInfo[$uid] = $user;
                }

                $helper = $this->getHelper('question');
                $choice = $ids[$chosen] ?? null;

                $question = new ChoiceQuestion(
                    r('<question>Select which user to associate with this backend</question>. {default}', [
                        'default' => null !== $choice ? "<value>[Default: {$choice}]</value>" : ''
                    ]),
                    $list,
                    false === $choice ? null : $choice
                );

                $question->setAutocompleterValues($list);

                $question->setErrorMessage('Invalid value [%s] was selected.');

                $user = $helper->ask($input, $output, $question);
                $u = ag_set($u, 'user', $map[$user]);

                if ('plex' === ag($u, 'type')) {
                    if (false === (bool)ag($userInfo[$map[$user]], 'admin')) {
                        $output->writeln(
                            r(
                                <<<INFO

                            The selected user [<value>{user}</value>] is not the <flag>admin</flag> of the backend.
                            Thus syncing the user watch state using the provided token is not possible, as <value>Plex</value>
                            use tokens to identify users rather than user ids. We <value>replaced</value> the token with the one reported from the server.
                            ------------------
                            <info>This might lead to some functionality to not work as expected, like listing backend users.
                            this is expected as the managed user token is rather limited compared to the admin user token.</info>

                            INFO,
                                [
                                    'user' => ag($userInfo[$map[$user]], 'name') ?? 'None',
                                ]
                            )
                        );
                        $u = ag_set($u, 'options.' . Options::ADMIN_TOKEN, ag($u, 'token'));
                    } else {
                        $u = ag_delete($u, 'options.' . Options::ADMIN_TOKEN);
                    }

                    $u = ag_set($u, 'token', ag($userInfo[$map[$user]], 'token'));
                }
                return;
            } catch (Throwable $e) {
                $output->writeln('<error>Failed to get the users list from backend.</error>');
                $output->writeln(
                    sprintf(
                        '<error>ERROR - %s: %s.</error>' . PHP_EOL,
                        afterLast(get_class($e), '\\'),
                        $e->getMessage()
                    )
                );
            }

            $helper = $this->getHelper('question');
            $question = new Question(
                sprintf(
                    'Please enter %s user id to associate this config to %s' . PHP_EOL . '> ',
                    ucfirst(ag($u, 'type')),
                    null !== $chosen ? "- <value>[Default: {$chosen}]</value>" : '',
                ),
                $chosen
            );

            $question->setValidator(function ($answer) {
                if (empty($answer)) {
                    throw new RuntimeException('Backend user id cannot be empty.');
                }

                if (!is_string($answer) && !is_int($answer)) {
                    throw new RuntimeException(
                        sprintf(
                            'Backend user id is invalid. Expecting [string|integer]. but got \'%s\' instead.',
                            get_debug_type($answer)
                        )
                    );
                }
                return $answer;
            });

            $user = $helper->ask($input, $output, $question);
            $u = ag_set($u, 'user', $user);
        })();
        $output->writeln('');

        // -- $name.import.enabled
        (function () use ($input, $output, &$u) {
            $chosen = (bool)ag($u, 'import.enabled', true);

            $helper = $this->getHelper('question');

            $question = new ConfirmationQuestion(
                r(
                    '<question>Enable importing of <flag>metadata</flag> and <flag>play state</flag> from this backend</question>? {default}' . PHP_EOL . '> ',
                    [
                        'default' => '[<value>Y|N</value>] [<value>Default: ' . ($chosen ? 'Yes' : 'No') . '</value>]',
                    ]
                ),
                $chosen
            );

            $response = $helper->ask($input, $output, $question);
            $u = ag_set($u, 'import.enabled', (bool)$response);
        })();
        $output->writeln('');

        // -- $name.export.enabled
        (function () use ($input, $output, &$u) {
            $chosen = (bool)ag($u, 'export.enabled', true);

            $helper = $this->getHelper('question');

            $question = new ConfirmationQuestion(
                r(
                    '<question>Enable exporting <value>play state</value> to this backend</question>? {default}' . PHP_EOL . '> ',
                    [
                        'default' => '[<value>Y|N</value>] [<value>Default: ' . ($chosen ? 'Yes' : 'No') . '</value>]',
                    ]
                ),
                $chosen
            );

            $response = $helper->ask($input, $output, $question);
            $u = ag_set($u, 'export.enabled', (bool)$response);
        })();
        $output->writeln('');

        // -- $name.options.IMPORT_METADATA_ONLY
        (function () use ($input, $output, &$u) {
            if (true === (bool)ag($u, 'import.enabled')) {
                return;
            }

            if (false === (bool)ag($u, 'export.enabled')) {
                return;
            }

            $chosen = (bool)ag($u, 'options.' . Options::IMPORT_METADATA_ONLY, true);

            $helper = $this->getHelper('question');

            $question = new ConfirmationQuestion(
                r(
                    <<<HELP
                    <question>Enable Importing <info>metadata ONLY</info> from this backend</question>? {default}
                    ------------------
                    To efficiently <cmd>export</cmd> to this backend we need relation map and this require
                    us to get metadata from the backend. You have <cmd>Importing</cmd> disabled, as such this option
                    allow us to import this backend <info>metadata</info> without altering your play state.
                    ------------------
                    <value>This option will not alter your play state or add new items to the database.</value>
                    HELP. PHP_EOL . '> ',
                    [
                        'default' => '[Y|N] [Default: ' . ($chosen ? 'Yes' : 'No') . ']',
                    ]
                ),
                $chosen
            );

            $response = $helper->ask($input, $output, $question);
            $u = ag_set($u, 'options.' . Options::IMPORT_METADATA_ONLY, (bool)$response);
        })();
        $output->writeln('');

        // -- $name.webhook.match.user
        (function () use ($input, $output, &$u) {
            $chosen = (bool)ag($u, 'webhook.match.user', false);

            $helper = $this->getHelper('question');

            $question = new ConfirmationQuestion(
                r(
                    <<<HELP
                    <question>Limit backend webhook events to the selected <flag>user</flag></question>? {default}
                    HELP. PHP_EOL . '> ',
                    [
                        'default' => '[<value>Y|N</value>] [<value>Default: ' . ($chosen ? 'Yes' : 'No') . '</value>]',
                    ]
                ),
                $chosen
            );

            $response = $helper->ask($input, $output, $question);
            $u = ag_set($u, 'webhook.match.user', (bool)$response);
        })();
        $output->writeln('');

        // -- $name.webhook.match.uuid
        (function () use ($input, $output, &$u) {
            $chosen = (bool)ag($u, 'webhook.match.uuid', false);

            $helper = $this->getHelper('question');

            $question = new ConfirmationQuestion(
                r(
                    <<<HELP
                    <question>Limit this backend webhook events to the specified <flag>backend unique id</flag></question>? {default}
                    ------------------
                    <comment>This option MUST be enabled for multi plex servers setup.</comment>
                    HELP. PHP_EOL . '> ',
                    [
                        'default' => '[<value>Y|N</value>] [<value>Default: ' . ($chosen ? 'Yes' : 'No') . '</value>]',
                    ]
                ),
                $chosen
            );

            $response = $helper->ask($input, $output, $question);
            $u = ag_set($u, 'webhook.match.uuid', (bool)$response);
        })();
        $output->writeln('');

        if (null === ag($u, 'webhook.token')) {
            try {
                $u = ag_set($u, 'webhook.token', bin2hex(random_bytes(Config::get('webhook.tokenLength'))));
            } catch (Throwable $e) {
                $output->writeln(
                    r('<error>ERROR</error>: Generating webhook token has failed. {error}', [
                        'error' => $e->getMessage()
                    ])
                );
                return self::FAILURE;
            }
        }

        // -- sanity check in case user has both import.enabled and options.IMPORT_METADATA_ONLY enabled.
        if (true === (bool)ag($u, 'import.enabled')) {
            if (true === ag_exists($u, 'options.' . Options::IMPORT_METADATA_ONLY)) {
                $u = ag_delete($u, 'options.' . Options::IMPORT_METADATA_ONLY);
            }
        }

        $output->writeln('-----------');
        $output->writeln('');
        $output->writeln(Yaml::dump([$name => $u], 8, 2));
        $output->writeln('');
        $output->writeln('-----------');
        $output->writeln('');

        $helper = $this->getHelper('question');
        $question = new ConfirmationQuestion(
            '<question>Is the info correct? [<value>Y|N</value>] [<value>Default: Yes</value>]</question>' . PHP_EOL . '> ',
            true
        );

        if (false === $helper->ask($input, $output, $question)) {
            return $this->runCommand($input, $output, $u);
        }

        if (false === $custom) {
            copy($config, $config . '.bak');
        }

        $backends = ag_set($backends, $name, $u);

        file_put_contents($config, Yaml::dump($backends, 8, 2));

        $output->writeln('<info>Config updated.</info>');

        if (false === $custom && $input->getOption('add')) {
            $helper = $this->getHelper('question');
            $text =
                <<<TEXT

                <question>Create database indexes now</question>? [<value>Y|N</value>] [<value>Default: Yes</value>]
                -----------------
                This is necessary action to ensure speedy operations on database,
                If you do not run this now, you have to manually run the system:index command, or restart the container
                which will trigger index check to make sure your database data is fully indexed.
                -----------------
                <value>P.S: this could take few minutes to execute.</value>

                TEXT;

            $question = new ConfirmationQuestion($text . PHP_EOL . '> ', true);

            if (true === $helper->ask($input, $output, $question)) {
                $this->getApplication()?->find(IndexCommand::ROUTE)->run(new ArrayInput([]), $output);
            }

            $importEnabled = (bool)ag($u, 'import.enabled');
            $metaEnabled = (bool)ag($u, 'options.' . Options::IMPORT_METADATA_ONLY);

            if (true === $importEnabled || true === $metaEnabled) {
                $importType = $importEnabled ? 'play state & metadata' : 'metadata only.';

                $helper = $this->getHelper('question');
                $text =
                    <<<TEXT

                <question>Would you like to import <flag>{type}</flag> from the backend now</question>? [<value>Y|N</value>] [<value>Default: No</value>]
                -----------------
                <value>P.S: this could take few minutes to execute.</value>

                TEXT;

                $text = r($text, ['type' => $importType]);

                $question = new ConfirmationQuestion($text . PHP_EOL . '> ', false);

                if (true === $helper->ask($input, $output, $question)) {
                    $output->writeln(
                        r('<info>Importing {type} from {name}</info>', [
                            'name' => $name,
                            'type' => $importType
                        ])
                    );
                    $cmd = $this->getApplication()?->find(ImportCommand::ROUTE);
                    $cmd->run(new ArrayInput(['--quiet', '--select-backends' => $name]), $output);
                }

                $output->writeln('<info>Import complete</info>');
            }
        }

        return self::SUCCESS;
    }
}
