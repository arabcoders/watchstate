<?php

declare(strict_types=1);

use App\Libs\Middlewares\{APIKeyRequiredMiddleware, NoAccessLogMiddleware, ParseJsonBodyMiddleware};

return static fn(): array => [
    fn() => new APIKeyRequiredMiddleware(),
    fn() => new ParseJsonBodyMiddleware(),
    fn() => new NoAccessLogMiddleware(),
];
