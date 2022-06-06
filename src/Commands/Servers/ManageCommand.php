<?php

declare(strict_types=1);

namespace App\Commands\Servers;

use App\Command;
use App\Libs\Config;
use App\Libs\Options;
use Exception;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Yaml\Yaml;
use Throwable;

final class ManageCommand extends Command
{
    protected function configure(): void
    {
        $this->setName('servers:manage')
            ->setDescription('Manage backend settings.')
            ->addOption('add', 'a', InputOption::VALUE_NONE, 'Add Backend.')
            ->addOption('config', 'c', InputOption::VALUE_REQUIRED, 'Use Alternative config file.')
            ->addArgument('backend', InputArgument::REQUIRED, 'Backend name.');
    }

    /**
     * @throws Exception
     */
    protected function runCommand(InputInterface $input, OutputInterface $output, null|array $rerun = null): int
    {
        if (function_exists('stream_isatty') && defined('STDERR')) {
            $tty = stream_isatty(STDERR);
        } else {
            $tty = true;
        }

        if (false === $tty || $input->getOption('no-interaction')) {
            $output->writeln('<error>ERROR: This command require interaction.</error>');
            $output->writeln(
                '<comment>If you are running this tool inside docker, you have to enable interaction using "-ti" flag</comment>'
            );
            $output->writeln(
                '<comment>For example: docker exec -ti watchstate console servers:manage my_home_server</comment>'
            );
            return self::FAILURE;
        }

        $custom = false;

        // -- Use Custom servers.yaml file.
        if (($config = $input->getOption('config'))) {
            try {
                $this->checkCustomServersFile($config);
                $custom = true;
                $servers = (array)Yaml::parseFile($config);
            } catch (\RuntimeException $e) {
                $output->writeln(sprintf('<error>%s</error>', $e->getMessage()));
                return self::FAILURE;
            }
        } else {
            $config = Config::get('path') . '/config/servers.yaml';
            if (!file_exists($config)) {
                touch($config);
            }
            $servers = (array)Config::get('servers', []);
        }

        $add = $input->getOption('add');
        $name = $input->getArgument('backend');

        if (!isValidName($name)) {
            $output->writeln(
                sprintf(
                    '<error>ERROR: Invalid \'%s\' name was given. Only \'A-Z, a-z, 0-9, _\' are allowed.</error>',
                    $name,
                )
            );
            return self::FAILURE;
        }

        if (false === $add && null === ag($servers, "{$name}.type", null)) {
            $output->writeln(
                sprintf(
                    '<error>ERROR: Backend \'%s\' not found. To add new backend append --add flag to the command.</error>',
                    $name,
                )
            );
            return self::FAILURE;
        }

        if (true === $add && null !== ag($servers, "{$name}.type", null)) {
            $output->writeln(
                sprintf(
                    '<error>ERROR: Backend name \'%s\' already exists in \'%s\' omit the --add flag if you want to edit the config.</error>',
                    $name,
                    $config
                )
            );
            return self::FAILURE;
        }

        $u = $rerun ?? ag($servers, $name, []);

        // -- $name.type
        (function () use ($input, $output, &$u, $name) {
            $list = array_keys(Config::get('supported', []));
            $chosen = ag($u, 'type');

            $helper = $this->getHelper('question');
            $choice = array_search($chosen, $list);

            $question = new ChoiceQuestion(
                sprintf(
                    'Select %s type. %s',
                    $name,
                    null !== $chosen ? "<comment>[Default: {$chosen}]</comment>" : ''
                ),
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
                sprintf(
                    'Enter %s URL. %s' . PHP_EOL . '> ',
                    $name,
                    null !== $chosen ? "<comment>[Default: {$chosen}]</comment>" : '',
                ),
                $chosen
            );

            $question->setValidator(function ($answer) {
                if (!filter_var($answer, FILTER_VALIDATE_URL)) {
                    throw new \RuntimeException('Invalid backend URL was given.');
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
                sprintf(
                    'Enter %s API token. %s' . PHP_EOL . '> ',
                    $name,
                    null !== $chosen ? "<comment>[Default: {$chosen}]</comment>" : '',
                ),
                $chosen
            );

            $question->setValidator(function ($answer) {
                if (empty($answer)) {
                    throw new \RuntimeException('Token value cannot be empty or null.');
                }

                if (!is_string($answer) && !is_int($answer)) {
                    throw new \RuntimeException(
                        sprintf(
                            'Token value is invalid. Was Expecting [string|integer]. but got \'%s\' instead.',
                            get_debug_type($answer)
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

                $server = array_replace_recursive($u, ['options' => ['client' => ['timeout' => 10]]]);
                $chosen = ag($u, 'uuid', fn() => makeServer($server, $name)->getServerUUID(true));
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
                sprintf(
                    'Enter %s backend unique identifier. %s' . PHP_EOL . '> ',
                    $name,
                    null !== $chosen ? "<comment>[Default: {$chosen}]</comment>" : '',
                ),
                $chosen
            );

            $question->setValidator(function ($answer) {
                if (empty($answer)) {
                    throw new \RuntimeException('Backend unique identifier cannot be empty.');
                }

                if (!is_string($answer) && !is_int($answer)) {
                    throw new \RuntimeException(
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

                $list = $map = $ids = [];
                $server = array_replace_recursive($u, ['options' => ['client' => ['timeout' => 5]]]);
                $users = makeServer($server, $name)->getUsersList();

                if (empty($users)) {
                    throw new \RuntimeException('Backend returned empty list of users.');
                }

                foreach ($users as $user) {
                    $uid = ag($user, 'id');
                    $val = ag($user, 'name', '??');
                    $list[] = $val;
                    $ids[$uid] = $val;
                    $map[$val] = $uid;
                }

                $helper = $this->getHelper('question');
                $choice = $ids[$chosen] ?? null;

                $question = new ChoiceQuestion(
                    sprintf(
                        'Select which user to associate with this backend. %s',
                        null !== $choice ? "<comment>[Default: {$choice}]</comment>" : ''
                    ),
                    $list,
                    false === $choice ? null : $choice
                );

                $question->setAutocompleterValues($list);

                $question->setErrorMessage('Invalid value [%s] was selected.');

                $user = $helper->ask($input, $output, $question);
                $u = ag_set($u, 'user', $map[$user]);

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
                    null !== $chosen ? "- <comment>[Default: {$chosen}]</comment>" : '',
                ),
                $chosen
            );

            $question->setValidator(function ($answer) {
                if (empty($answer)) {
                    throw new \RuntimeException('Backend user id cannot be empty.');
                }

                if (!is_string($answer) && !is_int($answer)) {
                    throw new \RuntimeException(
                        sprintf(
                            'Backend user id is invalid. Expecting [string|integer]. but got \'%s\' instead.',
                            get_debug_type($answer)
                        )
                    );
                }
                return $answer;
            });

            $user = $helper->ask($input, $output, $question);
            $u = ag_set($u, 'uuid', $user);
        })();
        $output->writeln('');

        // -- $name.import.enabled
        (function () use ($input, $output, &$u) {
            $chosen = (bool)ag($u, 'import.enabled', true);

            $helper = $this->getHelper('question');
            $text = 'Enable <info>Importing</info> <comment>metadata</comment> and <comment>play state</comment> from this backend? <comment>%s</comment>';

            $question = new ConfirmationQuestion(
                sprintf(
                    $text . PHP_EOL . '> ',
                    '[Y|N] [Default: ' . ($chosen ? 'Yes' : 'No') . ']',
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
            $text = 'Enable <info>Exporting</info> <comment>play state</comment> to this backend? <comment>%s</comment>';

            $question = new ConfirmationQuestion(
                sprintf(
                    $text . PHP_EOL . '> ',
                    '[Y|N] [Default: ' . ($chosen ? 'Yes' : 'No') . ']',
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
            $text =
                <<<TEXT
                Enable <info>Importing</info> <comment>metadata ONLY</comment> from this backend? <comment>%s</comment>
                ------------------
                To efficiently <info>export</info> to this backend we need relation map and this require
                us to get metadata from the backend. You have <comment>Importing</comment> disabled, as such this option
                allow us to import this backend <info>metadata</info> without altering your play state.
                ------------------
                <info>This option is SAFE and WILL NOT change your play state or add new items.</info>
                TEXT;

            $question = new ConfirmationQuestion(
                sprintf(
                    $text . PHP_EOL . '> ',
                    '[Y|N] [Default: ' . ($chosen ? 'Yes' : 'No') . ']',
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
            $text =
                <<<TEXT
                <info>Limit</info> backend webhook events to the selected <info>user</info>? <comment>%s</comment>
                ------------------
                <comment>Helpful for Plex multi user/servers setup.</comment>
                <comment>Lead sometimes to missed events for jellyfin itemAdd events. You should scope the token at jellyfin level.</comment>
                TEXT;

            $question = new ConfirmationQuestion(
                sprintf(
                    $text . PHP_EOL . '> ',
                    '[Y|N] [Default: ' . ($chosen ? 'Yes' : 'No') . ']',
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
            $text =
                <<<TEXT
                <info>Limit</info> backend webhook events to the selected <info>backend unique id</info>? <comment>%s</comment>
                ------------------
                <comment>Helpful for Plex multi user/servers setup.</comment>
                TEXT;

            $question = new ConfirmationQuestion(
                sprintf(
                    $text . PHP_EOL . '> ',
                    '[Y|N] [Default: ' . ($chosen ? 'Yes' : 'No') . ']',
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
                    sprintf('<error>Generating webhook api token has failed. %s</error>', $e->getMessage())
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
            'Is the info correct? <comment>[Y|N] [Default: Yes]</comment>' . PHP_EOL . '> ', true
        );

        if (false === $helper->ask($input, $output, $question)) {
            return $this->runCommand($input, $output, $u);
        }

        if (false === $custom) {
            copy($config, $config . '.bak');
        }

        $servers = ag_set($servers, $name, $u);

        file_put_contents($config, Yaml::dump($servers, 8, 2));

        return self::SUCCESS;
    }
}
