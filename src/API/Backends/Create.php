<?php

declare(strict_types=1);

namespace App\API\Backends;

use App\Libs\Attributes\Route\Post;
use App\Libs\HTTP_STATUS;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

#[Post(pattern: self::URL)]
final class Create
{
    public const URL = '/api/backends';

    public function __invoke(ServerRequestInterface $request, array $args = []): ResponseInterface
    {
        return api_response([
            'message' => 'Create'
        ], HTTP_STATUS::HTTP_OK);
    }
}
