<?php

declare(strict_types=1);

use App\Libs\Middlewares\APIKeyRequiredMiddleware;
use App\Libs\Middlewares\ParseJsonBodyMiddleware;

return static fn(): array => [
    fn() => new APIKeyRequiredMiddleware(),
    fn() => new ParseJsonBodyMiddleware(),
];
