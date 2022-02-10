<?php

declare(strict_types=1);

use Monolog\Logger;
use Psr\Log\LoggerInterface;

return (function (): array {
    return [
        LoggerInterface::class => [
            'class' => fn() => new Logger('logger')
        ],
    ];
})();
