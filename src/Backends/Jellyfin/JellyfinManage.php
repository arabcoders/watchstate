<?php

declare(strict_types=1);

namespace App\Backends\Jellyfin;

use App\Backends\Common\ManageInterface;
use RuntimeException;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Question\Question;
use Throwable;

class JellyfinManage implements ManageInterface
{
    private QuestionHelper $questionHelper;

    public function __construct(private OutputInterface $output, private InputInterface $input)
    {
        $this->questionHelper = new QuestionHelper();
    }

    public function manage(array $backend, array $opts = []): array
    {
        // -- $backend.url
        (function () use (&$backend) {
            $chosen = ag($backend, 'url');

            $question = new Question(
                r('<question>Enter [<value>{name}</value>] URL</question>. {default}' . PHP_EOL . '> ', [
                    'name' => ag($backend, 'name'),
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

            $url = $this->questionHelper->ask($this->input, $this->output, $question);

            $backend = ag_set($backend, 'url', $url);
        })();
        $this->output->writeln('');

        // -- $backend.token
        (function () use (&$backend) {
            $chosen = ag($backend, 'token');

            $question = new Question(
                r('<question>Enter [<value>{name}</value>] API token</question>. {default}' . PHP_EOL . '> ', [
                    'name' => ag($backend, 'name'),
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
                            [
                                'type' => get_debug_type($answer)
                            ]
                        )
                    );
                }
                return $answer;
            });

            $token = $this->questionHelper->ask($this->input, $this->output, $question);
            $backend = ag_set($backend, 'token', $token);
        })();
        $this->output->writeln('');

        // -- $backend.uuid
        (function () use (&$backend) {
            try {
                $this->output->writeln(
                    '<info>Attempting to automatically get the server unique identifier from API. Please wait...</info>'
                );

                $custom = array_replace_recursive($backend, [
                    'options' => [
                        'client' => [
                            'timeout' => 10
                        ]
                    ]
                ]);

                $chosen = ag($backend, 'uuid', fn() => makeBackend($custom, ag($custom, 'name'))->getIdentifier(true));
                $this->output->writeln(
                    r(
                        '<notice>Backend responded with [{id}] as it\'s unique identifier. setting it as default value.</notice>',
                        [
                            'id' => $chosen
                        ]
                    )
                );
            } catch (Throwable $e) {
                $this->output->writeln('<error>Failed to get the backend unique identifier.</error>');
                $this->output->writeln(
                    sprintf(
                        '<error>ERROR - %s: %s.</error>' . PHP_EOL,
                        afterLast(get_class($e), '\\'),
                        $e->getMessage()
                    )
                );
                $chosen = null;
            }

            $question = new Question(
                r(
                    <<<HELP
                    <question>Enter [<value>{name}</value>] Unique identifier</question>. {default}
                    ------------------
                    The Server Unique identifier is randomly generated string on server setup.
                    ------------------
                    <notice>If you select invalid or give incorrect server unique identifier, Some checks will
                    fail And you may not be able to sync your backend.</notice>
                    ------------------
                    <error>DO NOT CHANGE the default value unless you know what you are doing, or was told by devs.</error>
                    HELP. PHP_EOL . '> ',
                    [
                        'name' => ag($backend, 'name'),
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
                        r(
                            'Backend unique identifier is invalid. Expecting string or integer, but got \'{type}\' instead.',
                            [
                                'type' => get_debug_type($answer)
                            ]
                        )
                    );
                }
                return $answer;
            });

            $uuid = $this->questionHelper->ask($this->input, $this->output, $question);
            $backend = ag_set($backend, 'uuid', $uuid);
        })();
        $this->output->writeln('');

        // -- $backend.user
        (function () use (&$backend) {
            $chosen = ag($backend, 'user');

            try {
                $this->output->writeln(
                    '<info>Trying to get users list from backend. Please wait...</info>'
                );

                $list = $map = $ids = [];
                $custom = array_replace_recursive($backend, ['options' => ['client' => ['timeout' => 5]]]);

                $users = makeBackend($custom, ag($custom, 'name'))->getUsersList();

                if (empty($users)) {
                    throw new RuntimeException('Backend returned empty list of users.');
                }

                foreach ($users as $user) {
                    $uid = ag($user, 'id');
                    $val = ag($user, 'name', '??');
                    $list[] = $val;
                    $ids[$uid] = $val;
                    $map[$val] = $uid;
                }

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

                $user = $this->questionHelper->ask($this->input, $this->output, $question);
                $backend = ag_set($backend, 'user', $map[$user]);
                return;
            } catch (Throwable $e) {
                $this->output->writeln('<error>Failed to get the users list from backend.</error>');
                $this->output->writeln(
                    sprintf(
                        '<error>ERROR - %s: %s.</error>' . PHP_EOL,
                        afterLast(get_class($e), '\\'),
                        $e->getMessage()
                    )
                );
            }

            $question = new Question(
                r(
                    '<question>Please enter [<value>{name}</value>] user id to associate this config to</question>. {default}' . PHP_EOL . '> ',
                    [
                        'name' => ag($backend, 'name'),
                        'default' => null !== $chosen ? "- <value>[Default: {$chosen}]</value>" : '',
                    ]
                ),
                $chosen
            );
            $question->setValidator(function ($answer) {
                if (empty($answer)) {
                    throw new RuntimeException('Backend user id cannot be empty.');
                }

                if (!is_string($answer) && !is_int($answer)) {
                    throw new RuntimeException(
                        r(
                            'Backend user id is invalid. Expecting string or integer, but got \'{type}\' instead.',
                            [
                                'type' => get_debug_type($answer)
                            ]
                        )
                    );
                }
                return $answer;
            });

            $user = $this->questionHelper->ask($this->input, $this->output, $question);
            $backend = ag_set($backend, 'user', $user);
        })();
        $this->output->writeln('');

        return $backend;
    }
}
