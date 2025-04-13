<?php

declare(strict_types=1);

namespace App\API\System;

use App\Libs\Attributes\Route\Get;
use App\Libs\Exceptions\RuntimeException;
use Psr\Http\Message\ResponseInterface as iResponse;

final readonly class Explode
{
    public const string URL = '%{api.prefix}/system/explode';

    #[Get(self::URL . '[/]', name: 'system.explode')]
    public function __invoke(): iResponse
    {
        throw new RuntimeException('Throwing an exception to test exception handling.');
    }
}
