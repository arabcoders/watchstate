<?php

declare(strict_types=1);

namespace App\Backends\Plex;

use App\Backends\Common\ManageInterface;
use App\Libs\Options;
use RuntimeException;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface as iInput;
use Symfony\Component\Console\Output\OutputInterface as iOutput;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Question\Question;
use Symfony\Contracts\HttpClient\HttpClientInterface as iHttp;
use Throwable;

class PlexManage implements ManageInterface
{
    private QuestionHelper $questionHelper;

    public function __construct(
        private iHttp $http,
        private iOutput $output,
        private iInput $input,
    ) {
        $this->questionHelper = new QuestionHelper();
    }

    public function manage(array $backend, array $opts = []): array
    {
        // -- $backend.token
        (function () use (&$backend) {
            $chosen = ag($backend, 'token');

            $question = new Question(
                r(
                    <<<HELP
                    <question>Enter [<value>{name}</value>] Plex admin token</question>. {default}
                    ------------------
                    <info>Please log in your main plex account to extract the admin token.</info>
                    to find your plex token. follow the steps described in the following link.
                    <href={url}>{url}</>
                    ------------------
                    <notice>Plex uses tokens to differentiate between users. As such the token you enter not necessarily the final
                    one that ends up in the config due to user selection.</notice>
                    HELP. PHP_EOL . '> ',
                    [
                        'name' => ag($backend, 'name'),
                        'default' => null !== $chosen ? "<value>[Default: {$chosen}]</value>" : '',
                        'url' => 'https://support.plex.tv/articles/204059436',
                    ]
                ),
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

        // -- $backend.url
        (function () use (&$backend) {
            $chosen = ag($backend, 'url');

            try {
                re_select:
                if (null === $chosen || 'http://choose' === $chosen) {
                    $this->output->writeln(
                        '<info>Trying to get list of plex servers associated with the token. Please wait...</info>'
                    );

                    $response = PlexClient::discover(http: $this->http, token: ag($backend, 'token'));

                    $backends = ag($response, 'list', []);

                    if (empty($backends)) {
                        throw new RuntimeException('Plex API returned empty list of servers.');
                    }

                    $list = $map = [];

                    foreach ($backends as $server) {
                        $val = r('{name} - {uri}', [
                            'name' => ag($server, 'name'),
                            'uri' => ag($server, 'uri')
                        ]);

                        $list[] = $val;
                        $map[$val] = ag($server, 'uri');
                    }

                    $list[] = 'Other. Enter manually.';

                    $question = new ChoiceQuestion(
                        r(
                            '<question>Select [<value>{name}</value>] URL.</question>',
                            [
                                'name' => ag($backend, 'name'),
                            ]
                        ), $list
                    );

                    $question->setAutocompleterValues($list);

                    $question->setErrorMessage('Invalid value [%s] was selected.');

                    $server = $this->questionHelper->ask($this->input, $this->output, $question);

                    if (true === ag_exists($map, $server)) {
                        $backend = ag_set($backend, 'url', ag($map, $server));
                        return;
                    }
                }
            } catch (Throwable $e) {
                $this->output->writeln('<error>Failed to get list of servers associated with the token.</error>');
                $this->output->writeln(
                    r('<error>ERROR - {class}: {error}.</error>' . PHP_EOL, [
                        'class' => afterLast(get_class($e), '\\'),
                        'error' => $e->getMessage(),
                    ])
                );
            }

            $question = new Question(
                r(
                    <<<HELP
                    <question>Enter [<value>{name}</value>] URL</question>. {default}
                    ------------------
                    To see list of servers associated with this plex token, Please write the following as URL
                    <value>http://choose</value>
                    HELP. PHP_EOL . '> ',
                    [
                        'name' => ag($backend, 'name'),
                        'default' => null !== $chosen ? "[<value>Default: {$chosen}</value>]" : '',
                    ]
                ),
                $chosen
            );

            $question->setValidator(function ($answer) {
                if (!filter_var($answer, FILTER_VALIDATE_URL)) {
                    throw new RuntimeException('Invalid URL was selected/given.');
                }
                return $answer;
            });

            $url = $this->questionHelper->ask($this->input, $this->output, $question);
            if (null === $url || 'http://choose' === $url) {
                $chosen = $url;
                goto re_select;
            }

            $backend = ag_set($backend, 'url', $url);
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
                    <<<HELP
                    <question>Enter [<value>{name}</value>] backend unique identifier</question>. {default}
                    ------------------
                    The Unique identifier is randomly generated string on server setup.
                    ------------------
                    <notice>Backend unique identifier is used for two purposes.
                    1. To generate access tokens to access the server content for given user.
                    2. To deny other servers webhook events from reaching yor watch state installation if enabled.
                    ------------------
                    If you select invalid or given invalid unique identifier, the access token generation will fails.
                    and Webhooks will be non-functional.</notice>
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
        (function () use (&$backend, $opts) {
            $chosen = ag($backend, 'user');

            try {
                $this->output->writeln(
                    '<info>Trying to get users list from plex.tv api. Please wait...</info>'
                );

                $list = $map = $ids = $userInfo = [];

                $custom = array_replace_recursive($backend, [
                    'options' => [
                        'client' => [
                            'timeout' => 10
                        ],
                        Options::DEBUG_TRACE => (bool)ag($opts, Options::DEBUG_TRACE, false),
                    ]
                ]);

                try {
                    $users = makeBackend($custom, ag($backend, 'name'))->getUsersList();
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
                        $users = makeBackend($custom, ag($backend, 'name'))->getUsersList([]);
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
                    r(
                        <<<HELP
                        <question>Select [<value>{name}</value>] User</question>. {default}
                        ------------------
                        Unlike other media backends, Plex does not use user id to scope API calls, they use
                        Tokens to do so, thus the user is mainly used for webhook events.
                        HELP. PHP_EOL . '> ',
                        [
                            'name' => ag($backend, 'name'),
                            'default' => null !== $choice ? "<value>[Default: {$choice}]</value>" : ''
                        ]
                    ),
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
                            <<<HELP
                            The selected user [<value>{user}</value>] is not the <flag>main</flag> user of the server.
                            Thus syncing the user watch state using the provided token is not possible, as <value>Plex</value>
                            use tokens to identify users rather than user ids. We are going to attempt to generate access
                            token for the [<value>{user}</value>] and replace the given token with it.
                            ------------------
                            <info>This might lead to some functionality to not work as expected, like listing backend users.
                            this is expected as the managed user token is rather limited compared to the admin user token.</info>
                            HELP,
                            [
                                'user' => ag($userInfo[$map[$user]], 'name') ?? 'None',
                            ]
                        )
                    );

                    $userInfo[$map[$user]]['token'] = makeBackend($custom, ag($backend, 'name'))->getUserToken(
                        ag($userInfo[$map[$user]], 'uuid', $map[$user]),
                        $user
                    );
                    $backend = ag_set($backend, 'options.' . Options::ADMIN_TOKEN, ag($backend, 'token'));
                } else {
                    $backend = ag_delete($backend, 'options.' . Options::ADMIN_TOKEN);
                }

                if (null === ($userToken = ag($userInfo[$map[$user]], 'token'))) {
                    $this->output->writeln(
                        r('<error>Unable to get [{user}] access_token. check the logs.</error>', [
                            'user' => $user
                        ])
                    );
                    return;
                }

                $backend = ag_set($backend, 'token', $userToken);

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
                    <<<HELP
                    <question>Enter [<value>{name}</value>] User ID</question>. {default}
                    ------------------
                    If you are seeing this, This may indicate a problem with the given token as we are unable to get list of users.
                    The cause of this problem is in the following order from most likely to unlikely:
                    * Invalid token.
                    * You used a limited plex token (managed user token) instead of admin one.
                    * plex.tv api is having problems.
                    * Plex pushed an updated that break backwards compatibility and the tool need to be updated.
                    HELP. PHP_EOL . '> ',
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
