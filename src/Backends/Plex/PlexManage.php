<?php

declare(strict_types=1);

namespace App\Backends\Plex;

use App\Backends\Common\ManageInterface;
use App\Libs\Exceptions\Backends\RuntimeException;
use App\Libs\Options;
use Psr\Log\LoggerInterface as iLogger;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface as iInput;
use Symfony\Component\Console\Output\OutputInterface as iOutput;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Question\Question;
use Symfony\Contracts\HttpClient\HttpClientInterface as iHttp;
use Throwable;

/**
 * Class PlexManage
 *
 * This class is responsible for creating and managing Plex backends configuration.
 *
 * @implements ManageInterface.
 */
class PlexManage implements ManageInterface
{
    /**
     * @var QuestionHelper The QuestionHelper object used for asking questions.
     */
    private QuestionHelper $questionHelper;

    /**
     * Constructor for the class.
     *
     * @param iHttp $http The iHttp object used for HTTP requests.
     * @param iOutput $output The iOutput object used for outputting data.
     * @param iInput $input The iInput object used for retrieving user input.
     * @param iLogger $logger The iLogger object used for logging.
     */
    public function __construct(
        private iHttp $http,
        private iOutput $output,
        private iInput $input,
        protected iLogger $logger
    ) {
        $this->questionHelper = new QuestionHelper();
    }

    /**
     * @inheritdoc
     */
    public function manage(array $backend, array $opts = []): array
    {
        // -- $backend.token
        (function () use (&$backend) {
            $chosen = ag($backend, 'token');

            $question = new Question(
                r(
                    <<<HELP

                    <question>Enter [<value>{name}</value>] X-Plex-Token</question>. {default}
                    ------------------
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
                        '<info>Trying to get list of servers associated with the token from plex.tv API. Please wait...</info>'
                    );

                    $response = PlexClient::discover(http: $this->http, token: ag($backend, 'token'));

                    $backends = ag($response, 'list', []);

                    if (empty($backends)) {
                        throw new RuntimeException('Plex API returned empty list of servers.');
                    }

                    $list = $map = $uuid = [];

                    foreach ($backends as $server) {
                        $val = r('{name} - {uri}', [
                            'name' => ag($server, 'name'),
                            'uri' => ag($server, 'uri')
                        ]);

                        $list[] = $val;
                        $map[$val] = ag($server, 'uri');
                        $uuid[$val] = ag($server, 'identifier');
                    }

                    $list[] = 'Other. Enter manually.';

                    $question = new ChoiceQuestion(
                        r('<question>Select [<value>{name}</value>] URL.</question>', [
                            'name' => ag($backend, 'name'),
                        ]), $list
                    );

                    $question->setAutocompleterValues($list);

                    $question->setErrorMessage('Invalid value [%s] was selected.');

                    $server = $this->questionHelper->ask($this->input, $this->output, $question);

                    if (true === ag_exists($map, $server)) {
                        $backend = ag_set($backend, 'url', ag($map, $server));
                        $backend = ag_set($backend, 'uuid', ag($uuid, $server));

                        try {
                            $this->output->writeln(
                                r(
                                    '<info>Successfully contacted the Backed. It responded with [{version}] as it\'s version.</info>',
                                    [
                                        'version' => makeBackend(ag_set($backend, 'user', '1'))->getVersion()
                                    ]
                                )
                            );
                        } catch (Throwable $e) {
                            $this->output->writeln(r('<error>Unable to contact [{url}]. {error}</error>', [
                                'url' => ag($backend, 'url'),
                                'error' => $e->getMessage()
                            ]));
                            goto re_select;
                        }
                        return;
                    }
                }
            } catch (Throwable $e) {
                $this->output->writeln(
                    '<error>Failed to get list of servers associated with the token from plex.tv api.</error>'
                );
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

            $question->setValidator(function ($answer) use ($backend) {
                if (false === isValidURL($answer)) {
                    throw new RuntimeException(
                        'Invalid URL was selected/given. Expecting something like http://plex:32400.'
                    );
                }

                return $answer;
            });

            $url = $this->questionHelper->ask($this->input, $this->output, $question);
            if (null === $url || 'http://choose' === $url) {
                $chosen = $url;
                goto re_select;
            }

            try {
                $this->output->writeln(
                    r(
                        '<info>Successfully contacted the Backed. It responded with [{version}] as it\'s version.</info>',
                        [
                            'version' => makeBackend(ag_set(ag_set($backend, 'url', $url), 'user', '1'))->getVersion()
                        ]
                    )
                );
            } catch (Throwable $e) {
                $this->output->writeln(r('<error>Unable to contact {url}. {error}</error>', [
                    'url' => $url,
                    'error' => $e->getMessage()
                ]));
                goto re_select;
            }

            $backend = ag_set($backend, 'url', $url);
        })();
        $this->output->writeln('');

        // -- $backend.uuid
        (function () use (&$backend, $opts) {
            try {
                $this->output->writeln(
                    '<info>Attempting to automatically get the server unique identifier from API. Please wait...</info>'
                );

                $custom = array_replace_recursive($backend, [
                    'options' => [
                        'client' => [
                            'timeout' => 20
                        ],
                        Options::DEBUG_TRACE => (bool)ag($opts, Options::DEBUG_TRACE, false),
                    ]
                ]);

                $chosen = ag($backend, 'uuid', fn() => makeBackend($custom, ag($custom, 'name'))->getIdentifier(true));
                if (null !== $chosen) {
                    $this->output->writeln(
                        r(
                            '<notice>Backend responded with [{id}] as it\'s unique identifier. setting it as default value.</notice>',
                            [
                                'id' => $chosen
                            ]
                        )
                    );
                } else {
                    throw new RuntimeException('Empty backend unique identifier was returned.');
                }
            } catch (Throwable $e) {
                $this->output->writeln(
                    r(
                        <<<ERROR
                        <error>Failed to automatically get server unique identifier.</error>
                        ------------------
                        <notice>This most likely means the token that was given doesn't have access to the selected server.</notice>
                        ------------------
                        {class}: {error}
                        ERROR,
                        [
                            'class' => afterLast(get_class($e), '\\'),
                            'error' => $e->getMessage(),
                        ]
                    ),
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
                    <notice>If you select invalid or give incorrect server unique identifier, the access token generation will
                    fail. and Webhooks receiver will most likely be non-functional as well.</notice>
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
        (function () use (&$backend, $opts) {
            $chosen = ag($backend, 'user');
            $errorType = 'none';

            try {
                $this->output->writeln(
                    '<info>Attempting to get users list from plex.tv API. Please wait...</info>'
                );

                $list = $map = $ids = $uuid = $userInfo = [];

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
                                '<notice>The API returned an error \'{error}\'. Attempting to use admin token.</notice>',
                                [
                                    'error' => $e->getMessage()
                                ]
                            )
                        );

                        $backend['token'] = $adminToken;
                        $custom['token'] = $adminToken;
                        $users = makeBackend($custom, ag($backend, 'name'))->getUsersList([]);
                    } else {
                        $errorType = 'users_list';
                        throw $e;
                    }
                }

                if (empty($users)) {
                    throw new RuntimeException('plex.tv API returned empty list of users.');
                }

                foreach ($users as $user) {
                    $uid = ag($user, 'id');
                    $val = ag($user, 'name', '??');
                    $list[] = $val;
                    $ids[$uid] = $val;
                    $map[$val] = $uid;
                    $uuid[$val] = ag($user, 'uuid', null);
                    $userInfo[$uid] = $user;
                }

                $choice = $ids[$chosen] ?? null;

                $question = new ChoiceQuestion(
                    r(
                        <<<HELP
                        <question>Select [<value>{name}</value>] User</question>. {default}
                        ------------------
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
                $backend = ag_set($backend, 'options.plex_user_uuid', $uuid[$user]);

                $question = new Question(
                    r(
                        <<<HELP
                        <question>[<value>{name}</value>] User PIN</question>. {default}
                        ------------------
                        <notice>Leave empty if the user doesn't have a PIN.</notice>
                        ------------------
                        HELP. PHP_EOL . '> ',
                        [
                            'name' => ag($backend, 'name'),
                            'default' => null !== $choice ? "<value>[Default: {$choice}]</value>" : ''
                        ]
                    ),
                    ag($backend, 'options.' . Options::PLEX_USER_PIN)
                );

                $pin = $this->questionHelper->ask($this->input, $this->output, $question);
                if (!empty($pin)) {
                    $backend = ag_set($backend, 'options.' . Options::PLEX_USER_PIN, $pin);
                    $custom = ag_set($custom, 'options.' . Options::PLEX_USER_PIN, $pin);
                }

                $this->output->writeln(
                    r("<info>Requesting plex token for '{user}'{pin} from plex.tv API.</info>", [
                        'user' => ag($userInfo[$map[$user]], 'name') ?? 'None',
                        'pin' => empty($pin) ? '' : ' with PIN'
                    ])
                );

                try {
                    $userInfo[$map[$user]]['token'] = makeBackend($custom, ag($backend, 'name'))->getUserToken(
                        ag($userInfo[$map[$user]], 'uuid', $map[$user]),
                        $user
                    );

                    if (null === ag($backend, 'options.' . Options::ADMIN_TOKEN)) {
                        $backend = ag_set($backend, 'options.' . Options::ADMIN_TOKEN, ag($backend, 'token'));
                    }
                } catch (RuntimeException $e) {
                    $errorType = 'token';
                    throw $e;
                }

                if (null === ($userToken = ag($userInfo[$map[$user]], 'token'))) {
                    $this->output->writeln(
                        r(
                            '<error>Unable to get [{user}] access token. rerun the command with [--debug] flags for more info or check logs.</error>',
                            [
                                'user' => $user
                            ]
                        )
                    );
                    return;
                }

                $backend = ag_set($backend, 'token', $userToken);

                return;
            } catch (Throwable $e) {
                $this->output->writeln(
                    r(
                        <<<ERROR
                        <error>Failed to get list of users from plex.tv API.</error>
                        ------------------
                        This most likely means the token is not a valid admin token.
                        ------------------
                        {class}: {error}
                        ERROR,
                        [
                            'class' => afterLast(get_class($e), '\\'),
                            'error' => $e->getMessage(),
                        ]
                    )
                );
            }

            $question = new Question(
                r(
                    <<<HELP
                    <question>Enter [<value>{name}</value>] User ID</question>. {default}
                    ------------------
                    The error occurred at [<notice>{errorType}</notice>] stage.

                    If you are seeing this, The reason is in the following order from most likely to unlikely:

                    * Invalid token was given. (<notice>users_list</notice>)
                    * You used a limited plex token instead of admin one. (<notice>token, users_list</notice>)
                    * the selected user doesn't have access to the selected server. (<notice>token</notice>)
                    * plex.tv API is having problems. (<notice>none</notice>)
                    * Plex.tv API made breaking changes, and thus the tool need to be updated. (<notice>none</notice>)
                    HELP. PHP_EOL . '> ',
                    [
                        'name' => ag($backend, 'name'),
                        'default' => null !== $chosen ? "- <value>[Default: {$chosen}]</value>" : '',
                        'reason' => $errorType,
                    ]
                ),
                $chosen
            );

            $question->setValidator(function ($answer) {
                if (empty($answer)) {
                    throw new RuntimeException('User id cannot be empty.');
                }

                if (!is_string($answer) && !is_int($answer)) {
                    throw new RuntimeException(
                        r(
                            'Invalid user id type was given. Expecting a string or integer. but got \'{type}\' instead.',
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
