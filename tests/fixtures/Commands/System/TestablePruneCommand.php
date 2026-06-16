<?php

declare(strict_types=1);

namespace Tests\fixtures\Commands\System;

use App\Commands\System\PruneCommand;
use App\Libs\Extends\LogMessageProcessor;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Symfony\Component\Console\Input\InputInterface;

final class TestablePruneCommand extends PruneCommand
{
    public static array $due = [];
    public static bool $forced = false;
    public static array $paths = [];

    public function __construct(
        private readonly ?array $pruners = null,
        private readonly ?TestHandler $handler = null,
    ) {
        $logger = new Logger('test', [], [new LogMessageProcessor()]);
        if (null !== $this->handler) {
            $logger->pushHandler($this->handler);
        }
        parent::__construct($logger);
    }

    protected function getPruners(): array
    {
        return $this->pruners ?? ([] !== self::$paths ? $this->loadPruners(false, self::$paths) : parent::getPruners());
    }

    protected function resolvePruners(InputInterface $input): array
    {
        self::$forced = $this->shouldForcePrunerDiscovery($input);

        if (false === self::$forced) {
            return $this->getPruners();
        }

        return [] !== self::$paths ? $this->loadPruners(true, self::$paths) : parent::resolvePruners($input);
    }

    protected function isPrunerDue(array $pruner): bool
    {
        $name = (string) ag($pruner, 'name', 'unknown');

        return self::$due[$name] ?? true;
    }
}
