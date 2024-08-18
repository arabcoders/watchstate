<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Libs\Config;
use App\Libs\Container;
use App\Libs\Entity\StateInterface as iState;
use App\libs\Events\DataEvent;
use App\Libs\Extends\ProxyHandler;
use App\Libs\Mappers\Import\DirectMapper;
use App\Libs\Options;
use App\Model\Events\EventListener;
use Monolog\Logger;
use Psr\Log\LoggerInterface as iLogger;

#[EventListener(self::NAME)]
final readonly class ProcessRequestEvent
{
    public const string NAME = 'process_request';

    /**
     * Class constructor.
     *
     * @param iLogger $logger The logger object.
     */
    public function __construct(private iLogger $logger, private DirectMapper $mapper)
    {
        set_time_limit(0);
        ini_set('memory_limit', '-1');
    }

    public function __invoke(DataEvent $e): DataEvent
    {
        $e->stopPropagation();

        $entity = Container::get(iState::class)::fromArray($e->getData());

        if (null !== ($lastSync = ag(Config::get("servers.{$entity->via}", []), 'import.lastSync'))) {
            $lastSync = makeDate($lastSync);
        }

        $message = r('SYSTEM: Processing [{backend}] [{title}] {tainted} request.', [
            'backend' => $entity->via,
            'title' => $entity->getName(),
            'event' => ag($entity->getExtra($entity->via), iState::COLUMN_EXTRA_EVENT, '??'),
            'tainted' => $entity->isTainted() ? 'tainted' : 'untainted',
            'lastSync' => $lastSync,
        ]);

        $e->addLog($message);
        $this->logger->notice($message);

        $logger = clone $this->logger;
        assert($logger instanceof Logger);

        $handler = ProxyHandler::create($e->addLog(...));
        $logger->pushHandler($handler);

        $oldLogger = $this->mapper->getLogger();
        $this->mapper->setLogger($logger);

        $metadataOnly = (bool)ag($e->getOptions(), Options::IMPORT_METADATA_ONLY);
        $this->mapper->add($entity, [
            Options::IMPORT_METADATA_ONLY => $metadataOnly,
            Options::STATE_UPDATE_EVENT => fn(iState $state) => queuePush($state),
            'after' => $lastSync,
        ]);

        $this->mapper->commit();
        $this->mapper->setLogger($oldLogger);

        $handler->close();

        return $e;
    }
}
