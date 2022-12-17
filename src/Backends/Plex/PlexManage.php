<?php

declare(strict_types=1);

namespace App\Backends\Plex;

use App\Backends\Common\ManageInterface;
use App\Libs\Options;
use RuntimeException;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Question\Question;
use Throwable;

class PlexManage implements ManageInterface
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
                r('<question>Enter [<value>{name}</value>] Plex admin token</question>. {default}' . PHP_EOL . '> ', [
                    'name' => ag($backend, 'name'),
                    'default' => null !== $chosen ? "<value>[Default: {$chosen}]</value>" : '',
                ]),
                $chosen
            );

            $question->setValidator(function ($answer) {
                if (empty($answer)) {
                    throw new RuntimeException('Plex admin token cannot be empty.');
                }

                if (!is_string($answer) && !is_int($answer)) {
                    throw new RuntimeException(
                        r(
                            'Invalid Plex token was given. Expecting string or integer, but got \'{type}\' instead.',
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
                    '<info>Getting backend unique identifier. Please wait...</info>'
                );

                $custom = array_replace_recursive($backend, [
                    'options' => [
                        'client' => [
                            'timeout' => 10
                        ]
                    ]
                ]);

                $chosen = ag($backend, 'uuid', fn() => makeBackend($custom, ag($custom, 'name'))->getIdentifier(true));
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
                    '<question>Enter [<value>{name}</value>] backend unique identifier</question>. {default}' . PHP_EOL . '> ',
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

        // -- $name.user
        (function () use (&$backend) {
            $chosen = ag($backend, 'user');

            try {
                $this->output->writeln(
                    '<info>Trying to get users list from backend. Please wait...</info>'
                );

                $list = $map = $ids = $userInfo = [];

                $custom = array_replace_recursive($backend, [
                    'options' => [
                        'client' => [
                            'timeout' => 5
                        ]
                    ]
                ]);

                try {
                    $users = makeBackend($custom, ag($backend, 'name'))->getUsersList(['tokens' => true]);
                } catch (Throwable $e) {
                    // -- Check admin token.
                    $adminToken = ag($backend, 'options.' . Options::ADMIN_TOKEN);
                    if (null !== $adminToken && $adminToken !== ag($backend, 'token')) {
                        $this->output->writeln(
                            r(
                                '<notice>Backend returned an error \'{error}\'. Attempting to use admin token.</notice>',
                                [
                                    'error' => $e->getMessage()
                                ]
                            )
                        );

                        $backend['token'] = $adminToken;
                        $custom['token'] = $adminToken;
                        $users = makeBackend($custom, ag($backend, 'name'))->getUsersList(['tokens' => true]);
                    } else {
                        throw $e;
                    }
                }

                if (empty($users)) {
                    throw new RuntimeException('Backend returned empty list of users.');
                }

                foreach ($users as $user) {
                    $uid = ag($user, 'id');
                    $val = ag($user, 'name', '??');
                    $list[] = $val;
                    $ids[$uid] = $val;
                    $map[$val] = $uid;
                    $userInfo[$uid] = $user;
                }

                $choice = $ids[$chosen] ?? null;

                $question = new ChoiceQuestion(
                    r('<question>Select which user to associate with this backend?</question>. {default}', [
                        'default' => null !== $choice ? "<value>[Default: {$choice}]</value>" : ''
                    ]),
                    $list,
                    false === $choice ? null : $choice
                );

                $question->setAutocompleterValues($list);

                $question->setErrorMessage('Invalid value [%s] was selected.');

                $user = $this->questionHelper->ask($this->input, $this->output, $question);
                $backend = ag_set($backend, 'user', $map[$user]);

                if (false === (bool)ag($userInfo[$map[$user]], 'admin')) {
                    $this->output->writeln(
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
                    $backend = ag_set($backend, 'options.' . Options::ADMIN_TOKEN, ag($backend, 'token'));
                } else {
                    $backend = ag_delete($backend, 'options.' . Options::ADMIN_TOKEN);
                }

                $backend = ag_set($backend, 'token', ag($userInfo[$map[$user]], 'token'));

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
                        sprintf(
                            'Backend user id is invalid. Expecting [string|integer]. but got \'%s\' instead.',
                            get_debug_type($answer)
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
