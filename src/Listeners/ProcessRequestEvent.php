<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Libs\Attributes\DI\Inject;
use App\Libs\Container;
use App\Libs\Entity\StateInterface as iState;
use App\libs\Events\DataEvent;
use App\Libs\Exceptions\RuntimeException;
use App\Libs\Extends\LoggerProxy;
use App\Libs\Extends\ProxyHandler;
use App\Libs\Mappers\Import\DirectMapper;
use App\Libs\Mappers\ImportInterface as iImport;
use App\Libs\Options;
use App\Libs\UserContext;
use App\Model\Events\EventListener;
use Monolog\Level;
use Monolog\Logger;
use Psr\Log\LoggerInterface as iLogger;
use Throwable;

#[EventListener(self::NAME)]
final readonly class ProcessRequestEvent
{
    public const string NAME = 'process_request';

    /**
     * Class constructor.
     *
     * @param iLogger $logger The logger object.
     */
    public function __construct(
        #[Inject(DirectMapper::class)]
        private iImport $mapper,
        private iLogger $logger,
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
        $isGeneric = true === (bool) ag($e->getOptions(), Options::IS_GENERIC, false);

        try {
            $userContext = get_user_context(user: $user, mapper: $this->mapper, logger: $this->logger);
        } catch (RuntimeException $ex) {
            $writer(Level::Error, $ex->getMessage());
            return $e;
        }

        $entity = Container::get(iState::class)::fromArray($e->getData())
            ->setIsTainted((bool) ag($e->getOptions(), 'tainted', false));

        $backend = make_backend(backend: $userContext->config->get($entity->via), name: $entity->via, options: [
            iLogger::class => LoggerProxy::create($writer),
            UserContext::class => $userContext,
        ]);

        // -- revalidate the metadata based on user context.
        if (true === $isGeneric) {
            try {
                $backend->getMetadata(ag($entity->getMetadata($entity->via), iState::COLUMN_ID));
            } catch (Throwable $ex) {
                $writer(Level::Info, $ex->getMessage());
                return $e;
            }
        }

        if (null !== ($lastSync = ag($userContext->get($entity->via, []), 'import.lastSync'))) {
            $lastSync = make_date($lastSync);
        }

        $message = r("Processing '{user}@{backend}' {tainted} request '{title}'. {data}", [
            'backend' => $entity->via,
            'title' => $entity->getName(),
            'tainted' => $entity->isTainted() ? 'tainted' : 'untainted',
            'lastSync' => $lastSync,
            'user' => $userContext->name,
            'data' => array_to_string([
                'event' => ag($entity->getExtra($entity->via), iState::COLUMN_EXTRA_EVENT, '??'),
                'state' => $entity->isWatched() ? 'played' : 'unplayed',
                'progress' => $entity->hasPlayProgress() ? 'Yes' : 'No',
                'request_id' => ag($e->getOptions(), Options::REQUEST_ID, '-'),
            ]),
        ]);

        $writer(Level::Notice, $message);

        $isDebug = (bool) ag($e->getOptions(), Options::DEBUG_TRACE, false);
        if (true === (bool) ag($backend->getContext()->options, Options::DEBUG_TRACE, false)) {
            $isDebug = true;
        }

        $mapper = $userContext->mapper;

        if (true === $isDebug) {
            $writer(Level::Notice, 'Debug mode enabled.');
            $mapper = $mapper->setOptions(ag_set($mapper->getOptions(), Options::DEBUG_TRACE, true));
        }

        $logger = clone $this->logger;
        assert($logger instanceof Logger, 'Expected logger instance for request processing.');

        $handler = ProxyHandler::create($e->addLog(...), Level::Info);
        $logger->pushHandler($handler);
        $mapper->setLogger($logger);
        $opts = [
            Options::IMPORT_METADATA_ONLY => (bool) ag($e->getOptions(), Options::IMPORT_METADATA_ONLY),
            Options::DISABLE_MARK_UNPLAYED => (bool) ag($e->getOptions(), Options::DISABLE_MARK_UNPLAYED),
            Options::STATE_UPDATE_EVENT => static fn(iState $state) => queue_push(
                entity: $state,
                userContext: $userContext,
            ),
            Options::LOG_TO_WRITER => $writer,
            Options::AFTER => $lastSync,
        ];

        if (true === $isDebug) {
            $opts[Options::DEBUG_TRACE] = true;
        }

        $mapper->add($entity, $opts);

        $mapper->commit();
        $handler->close();

        return $e;
    }
}
