<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Libs\Attributes\DI\Inject;
use App\Libs\Config;
use App\Libs\Container;
use App\Libs\Entity\StateInterface as iState;
use App\Libs\Enums\Http\Status;
use App\libs\Events\DataEvent;
use App\Libs\Exceptions\RuntimeException;
use App\Libs\Mappers\Import\DirectMapper;
use App\Libs\Mappers\ImportInterface as iImport;
use App\Libs\Options;
use App\Libs\QueueRequests;
use App\Libs\UserContext;
use App\Model\Events\EventListener;
use Monolog\Level;
use Psr\Log\LoggerInterface as iLogger;
use Throwable;

#[EventListener(self::NAME)]
final readonly class ProcessPushEvent
{
    public const string NAME = 'on_push';

    /**
     * Class constructor.
     *
     * @param iLogger $logger The logger object.
     */
    public function __construct(
        #[Inject(DirectMapper::class)] private iImport $mapper,
        private iLogger $logger,
        private QueueRequests $queue
    ) {
        set_time_limit(0);
        ini_set('memory_limit', '-1');
    }

    public function __invoke(DataEvent $e): DataEvent
    {
        $writer = function (Level $level, string $message, array $context = []) use ($e) {
            $e->addLog($level->getName() . ': ' . r($message, $context));
            $this->logger->log($level, $message, $context);
        };

        $e->stopPropagation();

        $user = ag($e->getOptions(), Options::CONTEXT_USER, 'main');

        try {
            $userContext = getUserContext(user: $user, mapper: $this->mapper, logger: $this->logger);
        } catch (RuntimeException $ex) {
            $writer(Level::Error, $ex->getMessage());
            return $e;
        }

        if (null === ($item = $userContext->db->get(Container::get(iState::class)::fromArray($e->getData())))) {
            $writer(Level::Error, "Item '{user}: {id}' is not found or has been deleted.", [
                'user' => $user,
                'id' => ag($e->getData(), 'id', '?')
            ]);
            return $e;
        }

        $options = $e->getOptions();
        $list = [];
        $supported = Config::get('supported', []);

        foreach ($userContext->config->getAll() as $backendName => $backend) {
            $type = strtolower(ag($backend, 'type', 'unknown'));

            if (true !== (bool)ag($backend, 'export.enabled')) {
                $writer(Level::Notice, "Export to '{user}@{backend}' is disabled by user.", [
                    'user' => $user,
                    'backend' => $backendName
                ]);
                continue;
            }

            if (!isset($supported[$type])) {
                $writer(Level::Error, "Ignoring '{user}@{backend}'. Invalid type '{type}'.", [
                    'type' => $type,
                    'user' => $user,
                    'backend' => $backendName,
                    'condition' => [
                        'expected' => implode(', ', array_keys($supported)),
                        'given' => $type,
                    ],
                ]);
                continue;
            }

            if (null === ($url = ag($backend, 'url')) || false === isValidURL($url)) {
                $writer(Level::Error, "Ignoring '{user}@{backend}'. Invalid URL '{url}'.", [
                    'user' => $user,
                    'backend' => $backendName,
                    'url' => $url ?? 'None',
                ]);
                continue;
            }

            $backend['name'] = $backendName;
            $list[$backendName] = $backend;
        }

        if (empty($list)) {
            $writer(Level::Error, 'There are no eligible backends receive the event.');
            return $e;
        }

        foreach ($list as $name => &$backend) {
            try {
                $opts = ag($backend, 'options', []);

                if (ag($options, Options::IGNORE_DATE)) {
                    $opts[Options::IGNORE_DATE] = true;
                }

                if (ag($options, Options::DRY_RUN)) {
                    $opts[Options::DRY_RUN] = true;
                }

                if (ag($options, Options::DEBUG_TRACE)) {
                    $opts[Options::DEBUG_TRACE] = true;
                }

                $backend['options'] = $opts;
                $backend['class'] = makeBackend(backend: $backend, name: $name, options: [
                    UserContext::class => $userContext,
                ]);
                $backend['class']->push(entities: [$item->id => $item], queue: $this->queue);
            } catch (Throwable $e) {
                $writer(
                    Level::Error,
                    "Exception '{error.kind}' was thrown unhandled during '{user}@{backend}' push events. '{error.message}' at '{error.file}:{error.line}'.",
                    [
                        'user' => $user,
                        'backend' => $name,
                        'error' => [
                            'kind' => $e::class,
                            'line' => $e->getLine(),
                            'message' => $e->getMessage(),
                            'file' => after($e->getFile(), ROOT_PATH),
                        ],
                        'exception' => [
                            'file' => $e->getFile(),
                            'line' => $e->getLine(),
                            'kind' => get_class($e),
                            'message' => $e->getMessage(),
                            'trace' => $e->getTrace(),
                        ],
                    ]
                );
            }
        }
        unset($backend);

        if (count($this->queue) < 1) {
            $writer(Level::Notice, 'SYSTEM: No play state changes detected.');
            return $e;
        }

        $writer(Level::Notice, "Processing '{user}: {id}' - '{via}: {title}' '{state}' push event.", [
            'user' => $user,
            'id' => $item->id,
            'via' => $item->via,
            'title' => $item->getName(),
            'state' => $item->isWatched() ? 'played' : 'unplayed',
        ]);

        foreach ($this->queue->getQueue() as $response) {
            $context = ag($response->getInfo('user_data'), 'context', []);
            $context['user'] = $user;

            try {
                if (Status::OK !== Status::from($response->getStatusCode())) {
                    $writer(
                        Level::Error,
                        "Request to change '{user}@{backend}: {item.title}' play state returned with unexpected '{status_code}' status code.",
                        $context
                    );
                    continue;
                }

                $writer(
                    Level::Notice,
                    "Updated '{user}@{backend}: {item.title}' watch state to '{play_state}'.",
                    $context
                );
            } catch (Throwable $e) {
                $writer(
                    Level::Error,
                    "Exception '{error.kind}' was thrown unhandled during '{user}@{backend}' request to change play state of {item.type} '{item.title}'. '{error.message}' at '{error.file}:{error.line}'.",
                    [
                        'error' => [
                            'kind' => $e::class,
                            'line' => $e->getLine(),
                            'message' => $e->getMessage(),
                            'file' => after($e->getFile(), ROOT_PATH),
                        ],
                        ...$context,
                        'exception' => [
                            'file' => $e->getFile(),
                            'line' => $e->getLine(),
                            'kind' => get_class($e),
                            'message' => $e->getMessage(),
                            'trace' => $e->getTrace(),
                        ],
                    ]
                );
            }
        }
        return $e;
    }
}
