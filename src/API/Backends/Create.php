<?php

declare(strict_types=1);

namespace App\API\Backends;

use App\Libs\Attributes\Route\Post;
use App\Libs\HTTP_STATUS;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

#[Post(self::URL, name: 'backends.create')]
final class Create
{
    public const URL = '%{api.prefix}/backends';

    public function __invoke(ServerRequestInterface $request, array $args = []): ResponseInterface
    {
        return api_error('Not yet implemented', HTTP_STATUS::HTTP_SERVICE_UNAVAILABLE);
    }
}
