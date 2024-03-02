<?php

declare(strict_types=1);

use App\Libs\Middlewares\APIKeyRequiredMiddleware;

return static fn(): array => [
    fn() => new APIKeyRequiredMiddleware(),
];
