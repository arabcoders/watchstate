<?php

declare(strict_types=1);

namespace App\API\System;

use App\Libs\Attributes\Route\Get;
use App\Libs\ServeStatic;
use League\Route\Http\Exception\BadRequestException;
use League\Route\Http\Exception\NotFoundException;
use Psr\Http\Message\ResponseInterface as iResponse;
use Psr\Http\Message\ServerRequestInterface as iRequest;

final class StaticFiles
{
    public const string URL = '%{api.prefix}/system/static';

    /**
     * Serve static files from the public directory or predefined paths.
     *
     * @throws BadRequestException If the request path is invalid or does not match the expected pattern.
     * @throws NotFoundException If the requested file does not exist or cannot be served.
     */
    #[Get(self::URL . '/{file:.*}', name: 'system.static.files')]
    public function __invoke(iRequest $request, ServeStatic $cls): iResponse
    {
        return $cls->serve(
            $request->withUri(
                $request->getUri()->withPath(
                    str_replace(parseConfigValue(self::URL), '', $request->getUri()->getPath())
                )
            )
        );
    }
}
