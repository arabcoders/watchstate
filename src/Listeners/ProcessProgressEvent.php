<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Libs\Config;
use App\Libs\Container;
use App\Libs\Database\DatabaseInterface as iDB;
use App\Libs\Entity\StateInterface as iState;
use App\Libs\Enums\Http\Status;
use App\libs\Events\DataEvent;
use App\Libs\Exceptions\Backends\NotImplementedException;
use App\Libs\Exceptions\Backends\UnexpectedVersionException;
use App\Libs\Options;
use App\Libs\QueueRequests;
use App\Model\Events\EventListener;
use Monolog\Level;
use Psr\Log\LoggerInterface as iLogger;
use Throwable;

#[EventListener(self::NAME)]
final readonly class ProcessProgressEvent
{
    public const string NAME = 'on_progress';

    /**
     * Class constructor.
     *
     * @param iLogger $logger The logger object.
     */
    public function __construct(private iLogger $logger, private iDB $db, private QueueRequests $queue)
    {
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

        $options = $e->getOptions();
        $entity = Container::get(iState::class)::fromArray($e->getData());

        if (null === ($item = $this->db->get($entity))) {
            $writer(Level::Error, "Item '{title}' Is not referenced locally yet.", ['title' => $entity->getName()]);
            return $e;
        }

        if ($item->isWatched() || $entity->isWatched()) {
            $writer(Level::Info, "Item '{id}: {title}' is marked as watched. Not updating watch process.", [
                'id' => $item->id,
                'title' => $item->getName()
            ]);
            return $e;
        }

        $item = $item->apply($entity);

        if (!$item->hasPlayProgress()) {
            $writer(Level::Notice, "Item '{title}' has no watch progress to export.", ['title' => $item->title]);
            return $e;
        }

        $list = [];

        $supported = Config::get('supported', []);

        foreach ((array)Config::get('servers', []) as $backendName => $backend) {
            $type = strtolower(ag($backend, 'type', 'unknown'));

            if (true !== (bool)ag($backend, 'export.enabled')) {
                $writer(Level::Notice, "SYSTEM: Export to '{backend}' is disabled by user.", [
                    'backend' => $backendName
                ]);
                continue;
            }

            if (!isset($supported[$type])) {
                $writer(Level::Error, "SYSTEM: '{backend}' Invalid type.", [
                    'backend' => $backendName,
                    'condition' => [
                        'expected' => implode(', ', array_keys($supported)),
                        'given' => $type,
                    ],
                ]);
                continue;
            }

            if (null === ($url = ag($backend, 'url')) || false === isValidURL($url)) {
                $writer(Level::Error, "SYSTEM: '{backend}' Invalid url.", [
                    'backend' => $backendName,
                    'url' => $url ?? 'None',
                ]);
                continue;
            }

            $backend['name'] = $backendName;
            $list[$backendName] = $backend;
        }

        if (empty($list)) {
            $writer(Level::Info, 'SYSTEM: There are no backends with export enabled.');
            return $e;
        }

        foreach ($list as $name => &$backend) {
            try {
                $opts = ag($backend, 'options', []);

                if (ag($options, 'ignore-date')) {
                    $opts[Options::IGNORE_DATE] = true;
                }

                if (ag($options, 'dry-run')) {
                    $opts[Options::DRY_RUN] = true;
                }

                if (ag($options, 'trace')) {
                    $opts[Options::DEBUG_TRACE] = true;
                }

                $backend['options'] = $opts;
                $backend['class'] = getBackend(name: $name, config: $backend);
                $backend['class']->progress(entities: [$item->id => $item], queue: $this->queue);
            } catch (UnexpectedVersionException|NotImplementedException $e) {
                $writer(
                    Level::Notice,
                    "SYSTEM: This feature is not available for '{backend}'. '{error.message}' at '{error.file}:{error.line}'.",
                    [
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
            } catch (Throwable $e) {
                $writer(
                    Level::Error,
                    "SYSTEM: Exception '{error.kind}' was thrown unhandled during '{backend}' request to sync progress. '{error.message}' at '{error.file}:{error.line}'.",
                    [
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

        $total = count($this->queue);

        if ($total >= 1) {
            $start = makeDate();

            $writer(Level::Notice, "SYSTEM: Sending '{total}' progress update requests.", [
                'total' => $total,
                'time' => [
                    'start' => $start,
                ],
            ]);

            foreach ($this->queue->getQueue() as $response) {
                $context = ag($response->getInfo('user_data'), 'context', []);

                try {
                    if (ag($options, 'trace')) {
                        $writer(Level::Debug, "Processing '{backend}' - '{item.title}' response.", [
                            'url' => ag($context, 'remote.url', '??'),
                            'status_code' => $response->getStatusCode(),
                            'headers' => $response->getHeaders(false),
                            'response' => $response->getContent(false),
                            ...$context
                        ]);
                    }

                    if (!in_array($response->getStatusCode(), [Status::OK->value, Status::NO_CONTENT->value])) {
                        $writer(
                            Level::Error,
                            "SYSTEM: Request to change '{backend}' '{item.title}' watch progress returned with unexpected '{status_code}' status code.",
                            [
                                'status_code' => $response->getStatusCode(),
                                ...$context
                            ]
                        );
                        continue;
                    }

                    $writer(Level::Notice, "SYSTEM: Updated '{backend}' '{item.title}' watch progress.", [
                        ...$context,
                        'status_code' => $response->getStatusCode(),
                    ]);
                } catch (Throwable $e) {
                    $writer(
                        Level::Error,
                        "SYSTEM: Exception '{error.kind}' was thrown unhandled during '{backend}' request to change watch progress of {item.type} '{item.title}'. '{error.message}' at '{error.file}:{error.line}'.",
                        [
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
                            ...$context,
                        ]
                    );
                }
            }

            $end = makeDate();
            $writer(Level::Notice, "SYSTEM: Sent '{total}' watch progress requests.", [
                'total' => $total,
                'time' => [
                    'start' => $start,
                    'end' => $end,
                    'duration' => $end->getTimestamp() - $start->getTimestamp(),
                ],
            ]);
        } else {
            $writer(Level::Notice, 'SYSTEM: No watch progress changes detected.');
        }

        return $e;
    }
}
