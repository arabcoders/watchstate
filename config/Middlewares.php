<?php

declare(strict_types=1);

use App\Libs\Middlewares\{AddCorsMiddleware,
    AddTimingMiddleware,
    AuthorizationMiddleware,
    NoAccessLogMiddleware,
    ParseJsonBodyMiddleware};

return static fn(): array => [
    fn() => new AddTimingMiddleware(),
    fn() => new AuthorizationMiddleware(),
    fn() => new ParseJsonBodyMiddleware(),
    fn() => new NoAccessLogMiddleware(),
    fn() => new AddCorsMiddleware(),
];
