<?php

declare(strict_types=1);

namespace App\Commands\Config;

use App\Command;
use App\Commands\System\IndexCommand;
use App\Libs\Attributes\Route\Cli;
use App\Libs\Config;
use App\Libs\ConfigFile;
use App\Libs\Exceptions\Backends\InvalidContextException;
use App\Libs\Options;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Exception\ExceptionInterface;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Yaml\Yaml;
use Throwable;

/**
 * Class ManageCommand
 *
 * This class allows the user to manage backend settings interactively.
 */
#[Cli(command: self::ROUTE)]
final class ManageCommand extends Command
{
    public const string ROUTE = 'config:manage';

    public function __construct(private LoggerInterface $logger)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName(self::ROUTE)
            ->setDescription('Manage backend settings.')
            ->addOption('add', 'a', InputOption::VALUE_NONE, 'Add Backend.')
            ->addOption('select-backend', 's', InputOption::VALUE_REQUIRED, 'Select backend.')
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
     * Execute the command.
     *
     * @param InputInterface $input The input interface for the command.
     * @param OutputInterface $output The output interface for the command.
     * @param null|array $rerun An optional rerun parameter.
     *
     * @return int The exit code for the command.
     * @throws ExceptionInterface When an error occurs while running the subcommands.
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

                    {cmd} <cmd>{route}</cmd> -- <value>{backend}</value>

                    ERROR,
                    [
                        'cmd' => trim(commandContext()),
                        'route' => self::ROUTE,
                    ]
                )

            );
            return self::FAILURE;
        }

        $name = $input->getOption('select-backend');

        if (empty($name)) {
            $output->writeln(r('<error>ERROR: Backend not specified. Please use [-s, --select-backend].</error>'));
            return self::FAILURE;
        }

        $configFile = ConfigFile::open(Config::get('backends_file'), 'yaml', autoCreate: true);
        $configFile->setLogger($this->logger);

        $add = $input->getOption('add');

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

        go_start:

        if (true === $add) {
            if (null !== $configFile->get("{$name}.type", null)) {
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
        } elseif (null === $configFile->get("{$name}.type", null)) {
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

        $u = $rerun ?? $configFile->get($name, []);
        $u['name'] = $name;

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
            $u = ag(Config::get('supported'), $type)::manage($u, [
                Options::DEBUG_TRACE => $input->getOption('trace'),
            ]);
        })();

        // -- $name.import.enabled
        (function () use ($input, $output, &$u, $name) {
            $chosen = (bool)ag($u, 'import.enabled', true);

            $helper = $this->getHelper('question');

            $question = new ConfirmationQuestion(
                r(
                    <<<HELP
                    <question>Enable [<value>{name}</value>] <flag>watch/play state</flag> import</question>? {default}
                    ------------------
                    <notice>WARNING:</notice> If this backend is new and does not have your correct watch/play state, then <error>YOU MUST</error>
                    answer with <value>no</value>. If the date on movies/episodes is newer than your recorded watch/play date, it will
                    override that. Select <value>no</value>, and export your current watch/play state, and then you can re-enable this option.
                    ------------------
                    <notice>For more information please read the FAQ.</notice>
                    HELP. PHP_EOL . '> ',
                    [
                        'name' => $name,
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
        (function () use ($input, $output, &$u, $name) {
            $chosen = (bool)ag($u, 'export.enabled', true);

            $helper = $this->getHelper('question');

            $question = new ConfirmationQuestion(
                r(
                    <<<HELP
                    <question>Enable <value>watch/play state</value> export to [<value>{name}</value>]</question>? {default}
                    ------------------
                    If the backend has <value>newer date on movies/episodes</value>, For example <notice>new server setup</notice>,
                    You're going to need to <notice>do forced full sync</notice> for the first time, as the <cmd>export</cmd> command normally checks the date
                    on objects before changing the play state. and forced full sync override that check.
                    After that you can do normal export. The command to do forced full export is:
                    ---
                    {cmd} <cmd>state:export</cmd> <flag>-vvifs</flag> <value>{name}</value>
                    HELP. PHP_EOL . '> ',
                    [
                        'name' => $name,
                        'cmd' => trim(commandContext()),
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
        (function () use ($input, $output, &$u, $name) {
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
                    <question>Enable [<value>{name}</value>] <value>metadata</value> only import</question>? {default}
                    ------------------
                    To efficiently <cmd>export</cmd> watch/play state to this backend we need relation map and this require
                    us to get metadata from the backend. You have <cmd>Importing</cmd> disabled, as such this option
                    allow us to import this backend <info>metadata</info> without altering your play state.
                    ------------------
                    <notice>This option will not alter your play state or add new items to the database.</notice>
                    HELP. PHP_EOL . '> ',
                    [
                        'name' => $name,
                        'default' => '[<value>Y|N</value>] [<value>Default: ' . ($chosen ? 'Yes' : 'No') . '</value>]',
                    ]
                ),
                $chosen
            );

            $response = $helper->ask($input, $output, $question);
            $u = ag_set($u, 'options.' . Options::IMPORT_METADATA_ONLY, (bool)$response);
        })();
        $output->writeln('');

        // -- $name.webhook.match.user
        (function () use ($input, $output, &$u, $name) {
            $chosen = (bool)ag($u, 'webhook.match.user', false);

            $helper = $this->getHelper('question');

            $question = new ConfirmationQuestion(
                r(
                    <<<HELP
                    <question>Limit [<value>{type}</value>:<value>{name}</value>] webhook events to the selected <flag>user</flag></question>? {default}
                    ------------------
                    For <flag>Plex</flag>, if you have managed users, <error>YOU MUST</error> enable this
                    option to <notice>prevent</notice> other users from <notice>diluting your watch/play state</notice>.
                    As plex uses webhook associated with the account.

                    For <flag>Jellyfin/Emby</flag>, You <error>SHOULD NOT</error> enable this option, instead rely on the server selected user,
                    As sometimes their webhook events does not include user information, and you will be missing events.
                    ------------------
                    Please refers to the FAQ file "What are the webhook limitations?" section to know more
                    ------------------
                    <notice>This option only relevant if you're going to use webhook related functionalities.</notice>
                    HELP. PHP_EOL . '> ',
                    [
                        'name' => $name,
                        'type' => ag($u, 'type'),
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
        (function () use ($input, $output, &$u, $name) {
            $chosen = (bool)ag($u, 'webhook.match.uuid', false);

            $helper = $this->getHelper('question');

            $question = new ConfirmationQuestion(
                r(
                    <<<HELP
                    <question>Limit [<value>{type}</value>:<value>{name}</value>] webhook events to the specified <flag>backend unique id</flag></question>? {default}
                    ------------------
                    for <info>Plex</info>, This option <notice>MUST BE</notice> enabled if you have multi plex servers associated with your account.
                    As Plex uses Plex account to store webhook URLs not the server itself. If you do not enable this option,
                    then any events from any servers associated with your plex account will be processed.

                    for <info>Jellyfin/Emby</info>, this option optional and can be enabled if you unified your API key.
                    ------------------
                    <notice>This option only relevant if you're going to use webhook related functionalities.</notice>
                    HELP. PHP_EOL . '> ',
                    [
                        'name' => $name,
                        'type' => ag($u, 'type'),
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

        if (true === ag_exists($u, 'name')) {
            $u = ag_delete($u, 'name');
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
            goto go_start;
        }

        try {
            $output->writeln('<info>Validating backend context. Please wait...</info>');
            $client = makeBackend($u, $name);
            $client->setLogger($this->logger);
            if (false === $client->validateContext($client->getContext())) {
                $output->writeln('<error>ERROR:</error> Backend context is invalid.');
                goto go_start;
            }
        } catch (InvalidContextException $e) {
            $output->writeln(
                r('<error>ERROR:</error> Backend context validation has failed. {error}', [
                    'error' => $e->getMessage()
                ])
            );
            goto go_start;
        }

        $configFile->set($name, $u)->persist();

        $output->writeln('<info>Config updated.</info>');

        if ($input->getOption('add')) {
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
        }

        return self::SUCCESS;
    }
}
