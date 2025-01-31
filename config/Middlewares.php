<?php

declare(strict_types=1);

use App\Libs\Middlewares\{AddCorsMiddleware,
    APIKeyRequiredMiddleware,
    NoAccessLogMiddleware,
    ParseJsonBodyMiddleware,
    ProfilerMiddleware};

return static fn(): array => [
    fn() => new ProfilerMiddleware(),
    fn() => new APIKeyRequiredMiddleware(),
    fn() => new ParseJsonBodyMiddleware(),
    fn() => new NoAccessLogMiddleware(),
    fn() => new AddCorsMiddleware(),
];
