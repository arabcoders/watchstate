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
use App\Libs\ConfigFile;
use App\Backends\Common\Cache as BackendCache;

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

        if (null !== ($altName = ag($options, Options::ALT_NAME))) {
            $db = perUserDb($altName);
            $writer(Level::Info, "Using alternate user '{name}' config for this event.", ['name' => $altName]);
        } else {
            $db = $this->db;
        }

        if (null === ($item = $db->get(Container::get(iState::class)::fromArray($e->getData())))) {
            $writer(Level::Error, "Item '{id}' Is not referenced locally yet.", ['id' => ag($e->getData(), 'id', '?')]);
            return $e;
        }

        if ($item->isWatched()) {
            $writer(Level::Info, "Item '{id}: {title}' is marked as watched. Not updating watch process.", [
                'id' => $item->id,
                'title' => $item->getName()
            ]);
            return $e;
        }

        if (false === $item->hasPlayProgress()) {
            $writer(Level::Info, "Item '{title}' has no watch progress to export.", ['title' => $item->title]);
            return $e;
        }

        $list = [];

        $configFile = $altName ? perUserConfig($altName) : ConfigFile::open(Config::get('backends_file'), 'yaml');
        $configFile->setLogger($this->logger);
        $cache = Container::get(BackendCache::class);
        if (null !== $altName) {
            $perUserCache = perUserCacheAdapter($altName);
            $cache = $cache->with(adapter: $perUserCache);
        }

        $supported = Config::get('supported', []);

        foreach ($configFile->getAll() as $backendName => $backend) {
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
                $backend['class'] = getBackend(name: $name, config: $backend, configFile: $configFile, options: [
                    BackendCache::class => $cache
                ]);
                $backend['class']->progress(entities: [$item->id => $item], queue: $this->queue);
            } catch (UnexpectedVersionException | NotImplementedException $e) {
                $writer(
                    Level::Notice,
                    "This feature is not available for '{backend}'. '{error.message}' at '{error.file}:{error.line}'.",
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
                    "Exception '{error.kind}' was thrown unhandled during '{backend}' request to sync progress. '{error.message}' at '{error.file}:{error.line}'.",
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
            $writer(Level::Notice, "Backend handlers didn't queue items to be updated.");
            return $e;
        }

        $progress = formatDuration($item->getPlayProgress());

        $writer(Level::Notice, "Processing '{id}' - '{via}: {title}' watch progress '{progress}' event.", [
            'id' => $item->id,
            'via' => $item->via,
            'title' => $item->getName(),
            'progress' => $progress,
        ]);

        foreach ($this->queue->getQueue() as $response) {
            $context = ag($response->getInfo('user_data'), 'context', []);

            try {
                if (ag($options, 'trace')) {
                    $writer(Level::Debug, "Processing '{backend}: {item.title}' response.", [
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
                        "Request to change '{backend}: {item.title}' watch progress returned with unexpected '{status_code}' status code.",
                        [
                            'status_code' => $response->getStatusCode(),
                            ...$context
                        ]
                    );
                    continue;
                }

                $writer(Level::Notice, "Updated '{backend}: {item.title}' watch progress to '{progress}'.", [
                    ...$context,
                    'progress' => $progress,
                    'status_code' => $response->getStatusCode(),
                ]);
            } catch (Throwable $e) {
                $writer(
                    Level::Error,
                    "Exception '{error.kind}' was thrown unhandled during '{backend}' request to change watch progress of {item.type} '{item.title}'. '{error.message}' at '{error.file}:{error.line}'.",
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

        return $e;
    }
}
