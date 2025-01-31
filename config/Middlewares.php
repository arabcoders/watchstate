<?php

declare(strict_types=1);

use App\Libs\Middlewares\{AddCorsMiddleware,
    AddTimingMiddleware,
    APIKeyRequiredMiddleware,
    NoAccessLogMiddleware,
    ParseJsonBodyMiddleware};

return static fn(): array => [
    fn() => new AddTimingMiddleware(),
    fn() => new APIKeyRequiredMiddleware(),
    fn() => new ParseJsonBodyMiddleware(),
    fn() => new NoAccessLogMiddleware(),
    fn() => new AddCorsMiddleware(),
];
