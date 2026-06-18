<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Backends\Common\Request;
use App\Libs\Attributes\DI\Inject;
use App\Libs\Config;
use App\Libs\Container;
use App\Libs\Entity\StateInterface as iState;
use App\Libs\Enums\Http\Status;
use App\libs\Events\DataEvent;
use App\Libs\Exceptions\RuntimeException;
use App\Libs\Extends\LoggerProxy;
use App\Libs\Mappers\Import\DirectMapper;
use App\Libs\Mappers\ImportInterface as iImport;
use App\Libs\Options;
use App\Libs\QueueRequests;
use App\Libs\UserContext;
use App\Model\Events\EventListener;
use Monolog\Level;
use Psr\Log\LoggerInterface as iLogger;
use Symfony\Contracts\HttpClient\HttpClientInterface as iHttp;
use Symfony\Contracts\HttpClient\ResponseInterface;
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
        #[Inject(DirectMapper::class)]
        private iImport $mapper,
        private iLogger $logger,
        private QueueRequests $queue,
    ) {
        set_time_limit(0);
        ini_set('memory_limit', '-1');
    }

    public function __invoke(DataEvent $e): DataEvent
    {
        $writer = function (Level $level, string $message, array $context = []) use ($e) {
            $e->addLog($level, $message, $context);
            $this->logger->log($level, $message, $context);
        };

        $e->stopPropagation();
        $this->queue->reset();

        $user = ag($e->getOptions(), Options::CONTEXT_USER, 'main');

        try {
            $userContext = get_user_context(user: $user, mapper: $this->mapper, logger: $this->logger);
        } catch (RuntimeException $ex) {
            $writer(Level::Error, $ex->getMessage());
            return $e;
        }

        if (null === ($item = $userContext->db->get(Container::get(iState::class)::fromArray($e->getData())))) {
            $writer(Level::Error, "Item '{identity.user}: {item_id}' is not found or has been deleted.", [
                'identity' => [
                    'user' => $user,
                ],
                'item_id' => ag($e->getData(), 'id', '?'),
            ]);
            return $e;
        }

        $writer(Level::Notice, "Received '{identity.user}@{identity.backend}' - '#{item_id}: {title}' push event.", [
            'identity' => [
                'user' => $user,
                'backend' => $item->via,
            ],
            'item_id' => $item->id,
            'title' => $item->getName(),
        ]);

        $options = $e->getOptions();
        $list = [];
        $supported = Config::get('supported', []);

        foreach ($userContext->config->getAll() as $backendName => $backend) {
            $type = strtolower(ag($backend, 'type', 'unknown'));

            if (true !== (bool) ag($backend, 'export.enabled')) {
                $writer(Level::Info, "Export to '{identity.user}@{identity.backend}' is disabled by user.", [
                    'identity' => [
                        'user' => $user,
                        'backend' => $backendName,
                    ],
                ]);
                continue;
            }

            if (!isset($supported[$type])) {
                $writer(Level::Error, "Ignoring '{identity.user}@{identity.backend}'. Invalid type '{type}'.", [
                    'type' => $type,
                    'identity' => [
                        'user' => $user,
                        'backend' => $backendName,
                    ],
                    'condition' => [
                        'expected' => implode(', ', array_keys($supported)),
                        'given' => $type,
                    ],
                ]);
                continue;
            }

            if (null === ($url = ag($backend, 'url')) || false === is_valid_url($url)) {
                $writer(Level::Error, "Ignoring '{identity.user}@{identity.backend}'. Invalid URL '{url}'.", [
                    'url' => $url ?? 'None',
                    'identity' => [
                        'user' => $user,
                        'backend' => $backendName,
                    ],
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
                $backendLogger = LoggerProxy::create($writer);

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
                $backend['class'] = make_backend(backend: $backend, name: $name, options: [
                    iLogger::class => $backendLogger,
                    UserContext::class => $userContext,
                ])->setLogger($backendLogger);
                $backend['class']->push(entities: [$item->id => $item], queue: $this->queue);
            } catch (Throwable $e) {
                $writer(
                    Level::Error,
                    "Failed during '{identity.user}@{identity.backend}' - '#{item.id}: {item.title}' push event handling. {exception.message}",
                    [
                        'identity' => [
                            'user' => $user,
                            'backend' => $name,
                        ],
                        'item' => [
                            'id' => $item->id,
                            'title' => $item->getName(),
                        ],
                        ...exception_log($e),
                    ],
                );
            }
        }
        unset($backend);

        if (count($this->queue) < 1) {
            $writer(Level::Notice, 'No play state changes detected.');
            return $e;
        }

        $writer(Level::Notice, "Dispatching '{identity.user}@{identity.backend}' - '#{item_id}: {title}' push event. {data}", [
            'identity' => [
                'user' => $user,
                'backend' => $item->via,
            ],
            'item_id' => $item->id,
            'title' => $item->getName(),
            'data' => array_to_string([
                'played' => $item->isWatched(),
            ]),
        ]);

        $http = Container::get(iHttp::class);
        assert($http instanceof iHttp, 'Expected HTTP client for push event queue dispatch.');

        send_requests(
            requests: $this->queue->getQueue(),
            client: $http,
            opts: [
                'ok' => static function (Request $request, ResponseInterface $response) use ($writer, $user): array {
                    if (true === (bool) ag($request->options, 'user_data.' . Options::NO_LOGGING, false)) {
                        return [];
                    }

                    $context = ag($request->extras, 'context', []);
                    $context['identity']['user'] = $user;
                    $context['identity']['backend'] ??= $context['backend'] ?? null;
                    $context['response']['status_code'] = $response->getStatusCode();

                    if (Status::OK !== Status::tryFrom($context['response']['status_code'])) {
                        $writer(
                            Level::Error,
                            "Request to change '{identity.user}@{identity.backend}' - '#{item.id}: {item.title}' play state returned with unexpected '{response.status_code}' status code.",
                            $context,
                        );

                        return [];
                    }

                    $writer(
                        Level::Notice,
                        "Updated '{identity.user}@{identity.backend}' - '#{item.id}: {item.title}' watch state to '{play_state}'.",
                        $context,
                    );

                    return [];
                },
                'error' => static function (Request $request, Throwable $ex) use ($writer, $user): array {
                    if (true === (bool) ag($request->options, 'user_data.' . Options::NO_LOGGING, false)) {
                        return [];
                    }

                    $context = ag($request->extras, 'context', []);
                    $context['identity']['user'] = $user;
                    $context['identity']['backend'] ??= $context['backend'] ?? null;

                    $writer(
                        Level::Error,
                        "Failed during '{identity.user}@{identity.backend}' request to change play state of {item.type} '#{item.id}: {item.title}'. {exception.message}",
                        [
                            ...$context,
                            ...exception_log($ex),
                        ],
                    );

                    return [];
                },
            ],
        );

        return $e;
    }
}
