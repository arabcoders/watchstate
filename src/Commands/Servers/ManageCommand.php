<?php

declare(strict_types=1);

namespace App\Commands\Servers;

use App\Command;
use App\Libs\Config;
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
            ->setDescription('Manage Server settings.')
            ->addOption('add', 'a', InputOption::VALUE_NONE, 'Add Server')
            ->addOption('regenerate-api-key', 'g', InputOption::VALUE_NONE, 'Re-generate webhook apikey')
            ->addOption('config', 'c', InputOption::VALUE_REQUIRED, 'Use Alternative config file.')
            ->addArgument('name', InputArgument::REQUIRED, 'Server name');
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
        $name = $input->getArgument('name');

        if (false === $add && null === ag($servers, "{$name}.type", null)) {
            $output->writeln(
                sprintf(
                    '<error>ERROR: Server \'%s\' not found. To add new server append --add flag to the command.</error>',
                    $name,
                )
            );
            return self::FAILURE;
        }

        if (true === $add && null !== ag($servers, "{$name}.type", null)) {
            $output->writeln(
                sprintf('<error>ERROR: Server name \'%s\' already exists in \'%s\'.</error>', $name, $config)
            );
            return self::FAILURE;
        }

        $u = $rerun ?? ag($servers, $name, []);

        // -- $name.type
        (function () use ($input, $output, &$u) {
            $list = array_keys(Config::get('supported', []));
            $chosen = ag($u, 'type');

            $helper = $this->getHelper('question');
            $choice = array_search($chosen, $list);

            $question = new ChoiceQuestion(
                sprintf(
                    'Select Server Type %s',
                    null !== $chosen ? "- <comment>[Default: {$chosen}]</comment>" : ''
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
        (function () use ($input, $output, &$u) {
            $helper = $this->getHelper('question');
            $chosen = ag($u, 'url');
            $question = new Question(
                sprintf(
                    'Please enter %s server url %s' . PHP_EOL . '> ',
                    ucfirst(ag($u, 'type')),

                    null !== $chosen ? "- <comment>[Default: {$chosen}]</comment>" : '',
                ),
                $chosen
            );

            $question->setValidator(function ($answer) {
                if (!filter_var($answer, FILTER_VALIDATE_URL)) {
                    throw new \RuntimeException('Invalid server url was given.');
                }
                return $answer;
            });

            $url = $helper->ask($input, $output, $question);
            $u = ag_set($u, 'url', $url);
        })();
        $output->writeln('');

        // -- $name.token
        (function () use ($input, $output, &$u) {
            $helper = $this->getHelper('question');
            $chosen = ag($u, 'token');
            $question = new Question(
                sprintf(
                    'Please enter %s server API token %s' . PHP_EOL . '> ',
                    ucfirst(ag($u, 'type')),
                    null !== $chosen ? "- <comment>[Default: {$chosen}]</comment>" : '',
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
                    '<info>Trying to get server unique identifier from given information... Please wait</info>'
                );

                $server = array_replace_recursive($u, ['options' => ['client' => ['timeout' => 5]]]);
                $chosen = ag($u, 'uuid', fn() => makeServer($server, $name)->getServerUUID(true));
            } catch (Throwable $e) {
                $output->writeln('<error>Failed to get the server unique identifier.</error>');
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
                    'Please enter %s server unique identifier %s' . PHP_EOL . '> ',
                    ucfirst(ag($u, 'type')),
                    null !== $chosen ? "- <comment>[Default: {$chosen}]</comment>" : '',
                ),
                $chosen
            );

            $question->setValidator(function ($answer) {
                if (empty($answer)) {
                    throw new \RuntimeException('server unique identifier cannot be empty or null.');
                }

                if (!is_string($answer) && !is_int($answer)) {
                    throw new \RuntimeException(
                        sprintf(
                            'server unique identifier is invalid. Expecting [string|integer]. but got \'%s\' instead.',
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

        // -- if the backend is plex we may need to skip user validation
        $chooseUser = (function () use ($input, $output, &$u) {
            $chosen = ag($u, 'type');

            if ('plex' !== $chosen || null !== ag($u, 'user')) {
                return true;
            }

            $helper = $this->getHelper('question');
            $text =
                <<<TEXT
                Do you share your Plex server with other people? <comment>[Y|N]</comment>
                <info>it's important to know otherwise their watch history might end up diluting yours if you use webhooks</info>
                TEXT;

            $question = new ConfirmationQuestion($text . PHP_EOL . '> ', false);

            return $helper->ask($input, $output, $question);
        })();

        // -- $name.user
        (function () use ($input, $output, &$u, $name, $chooseUser) {
            if (false === $chooseUser) {
                return;
            }

            $chosen = ag($u, 'user');

            try {
                $output->writeln(
                    '<info>Trying to get users list from server Please wait</info>'
                );

                $list = $map = $ids = [];
                $server = array_replace_recursive($u, ['options' => ['client' => ['timeout' => 5]]]);
                $users = makeServer($server, $name)->getUsersList();

                if (empty($users)) {
                    throw new \RuntimeException('Empty users list returned');
                }

                foreach ($users as $user) {
                    $uid = ag($user, 'user_id');
                    $val = ag($user, 'username', '??');
                    $list[] = $val;
                    $ids[$uid] = $val;
                    $map[$val] = $uid;
                }

                $helper = $this->getHelper('question');
                $choice = $ids[$chosen] ?? null;

                $question = new ChoiceQuestion(
                    sprintf(
                        'Select which user to associate with this server %s',
                        null !== $choice ? "- <comment>[Default: {$choice}]</comment>" : ''
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
                $output->writeln('<error>Failed to get the users list from server.</error>');
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
                    throw new \RuntimeException('Server user id cannot be empty or null.');
                }

                if (!is_string($answer) && !is_int($answer)) {
                    throw new \RuntimeException(
                        sprintf(
                            'Server user id is invalid. Expecting [string|integer]. but got \'%s\' instead.',
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
            $text =
                <<<TEXT
                Do you want to enable <info>"Import"</info> from this server via scheduled task? <comment>%s</comment>
                TEXT;

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
            $text =
                <<<TEXT
                Do you want to enable <info>"Export"</info> to this server via scheduled task? <comment>%s</comment>
                TEXT;

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

        // -- $name.webhook.import
        (function () use ($input, $output, &$u) {
            $chosen = (bool)ag($u, 'webhook.import', true);

            $helper = $this->getHelper('question');
            $text =
                <<<TEXT
                Do you want to enable <info>"Import"</info> via <info>webhooks</info> for this server? <comment>%s</comment>
                -----------------
                <info>Do not forget to add the webhook end point to the server if you enabled this option.</info>
                TEXT;

            $question = new ConfirmationQuestion(
                sprintf(
                    $text . PHP_EOL . '> ',
                    '[Y|N] [Default: ' . ($chosen ? 'Yes' : 'No') . ']',
                ),
                $chosen
            );

            $response = $helper->ask($input, $output, $question);
            $u = ag_set($u, 'webhook.import', (bool)$response);
        })();
        $output->writeln('');

        // -- $name.webhook.push
        (function () use ($input, $output, &$u) {
            $chosen = (bool)ag($u, 'webhook.push', true);

            $helper = $this->getHelper('question');
            $text =
                <<<TEXT
                Do you want to enable <info>"Push"</info> to this server on received <info>webhooks</info> events? <comment>%s</comment>
                -----------------
                <info>The way push works is to queue watched state and then push them to the server.</info>
                -----------------
                This approach has both strength and limitations, please refer to docs for more info on webhooks.
                TEXT;

            $question = new ConfirmationQuestion(
                sprintf(
                    $text . PHP_EOL . '> ',
                    '[Y|N] [Default: ' . ($chosen ? 'Yes' : 'No') . ']',
                ),
                $chosen
            );

            $response = $helper->ask($input, $output, $question);
            $u = ag_set($u, 'webhook.push', (bool)$response);
        })();
        $output->writeln('');

        // -- $name.webhook.match.user
        (function () use ($input, $output, &$u) {
            $chosen = (bool)ag($u, 'webhook.match.user', false);

            $helper = $this->getHelper('question');
            $text =
                <<<TEXT
                Do you want to limit this <info>server</info> webhook events to <info>the specified user</info>? <comment>%s</comment>
                ------------------
                Helpful for Plex Multi user/servers setup.
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
                Do you want to limit this <info>server</info> webhook events to <info>the specified server unique id</info>? <comment>%s</comment>
                ------------------
                Helpful for Plex Multi user/servers setup.
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

        // -- $name.webhook.token
        (function () use ($input, $output, &$u, $name) {
            $cond = true === ag($u, 'webhook.import') && null === ag($u, 'webhook.token');

            if (true === $cond || $input->getOption('regenerate-api-key')) {
                try {
                    $apiToken = bin2hex(random_bytes(Config::get('webhook.tokenLength')));

                    $output->writeln(
                        sprintf('<info>The API key for \'%s\' webhook endpoint is: %s</info>', $name, $apiToken)
                    );

                    $u = ag_set($u, 'webhook.token', $apiToken);
                } catch (Throwable $e) {
                    $output->writeln(sprintf('<error>%s</error>', $e->getMessage()));
                    exit(self::FAILURE);
                }
            }
        })();
        $output->writeln('');

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
