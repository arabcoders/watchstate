<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Libs\Config;
use App\Libs\Container;
use App\Libs\Database\DatabaseInterface as iDB;
use App\Libs\Entity\StateInterface as iState;
use App\Libs\Enums\Http\Status;
use App\libs\Events\DataEvent;
use App\Libs\Options;
use App\Libs\QueueRequests;
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

        if (null === ($item = $this->db->get(Container::get(iState::class)::fromArray($e->getData())))) {
            $writer(Level::Error, "Item '{id}' is not found or has been deleted.", [
                'id' => ag($e->getData(), 'id', '?')
            ]);
            return $e;
        }

        $options = $e->getOptions();
        $list = [];
        $supported = Config::get('supported', []);

        foreach ((array)Config::get('servers', []) as $backendName => $backend) {
            $type = strtolower(ag($backend, 'type', 'unknown'));

            if (true !== (bool)ag($backend, 'export.enabled')) {
                $writer(Level::Notice, "Export to '{backend}' is disabled by user.", [
                    'backend' => $backendName
                ]);
                continue;
            }

            if (!isset($supported[$type])) {
                $writer(Level::Error, "The backend '{backend}' is using invalid type '{type}'.", [
                    'type' => $type,
                    'backend' => $backendName,
                    'condition' => [
                        'expected' => implode(', ', array_keys($supported)),
                        'given' => $type,
                    ],
                ]);
                continue;
            }

            if (null === ($url = ag($backend, 'url')) || false === isValidURL($url)) {
                $writer(Level::Error, "The backend '{backend}' URL is invalid.", [
                    'backend' => $backendName,
                    'url' => $url ?? 'None',
                ]);
                continue;
            }

            $backend['name'] = $backendName;
            $list[$backendName] = $backend;
        }

        if (empty($list)) {
            $writer(Level::Error, 'There are no backends with export enabled.');
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
                $backend['class'] = getBackend(name: $name, config: $backend);
                $backend['class']->push(entities: [$item->id => $item], queue: $this->queue);
            } catch (Throwable $e) {
                $writer(
                    Level::Error,
                    "Exception '{error.kind}' was thrown unhandled during '{backend}' push events. '{error.message}' at '{error.file}:{error.line}'.",
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

        if (count($this->queue) < 1) {
            $writer(Level::Notice, 'SYSTEM: No play state changes detected.');
            return $e;
        }

        $writer(Level::Notice, "Processing '{id}' -  '{via}: {title}' '{state}' push event.", [
            'id' => $item->id,
            'via' => $item->via,
            'title' => $item->getName(),
            'state' => $item->isWatched() ? 'played' : 'unplayed',
        ]);

        foreach ($this->queue->getQueue() as $response) {
            $context = ag($response->getInfo('user_data'), 'context', []);

            try {
                if (Status::OK !== Status::from($response->getStatusCode())) {
                    $writer(
                        Level::Error,
                        "Request to change '{backend}: {item.title}' play state returned with unexpected '{status_code}' status code.",
                        $context
                    );
                    continue;
                }

                $writer(Level::Notice, "Updated '{backend}: {item.title}' watch state to '{play_state}'.", $context);
            } catch (Throwable $e) {
                $writer(
                    Level::Error,
                    "Exception '{error.kind}' was thrown unhandled during '{backend}' request to change play state of {item.type} '{item.title}'. '{error.message}' at '{error.file}:{error.line}'.",
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
