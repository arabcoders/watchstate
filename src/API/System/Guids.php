<?php

declare(strict_types=1);

namespace App\API\System;

use App\Libs\Attributes\Route\Get;
use App\Libs\Container;
use App\Libs\Enums\Http\Status;
use App\Libs\Guid;
use Psr\Http\Message\ResponseInterface as iResponse;
use Psr\Http\Message\ServerRequestInterface as iRequest;
use Psr\Log\LoggerInterface;

final class Guids
{
    public const string URL = '%{api.prefix}/system/guids';

    #[Get(self::URL . '[/]', name: 'system.guids')]
    public function __invoke(iRequest $request): iResponse
    {
        $list = [];

        Guid::setLogger(Container::get(LoggerInterface::class));
        $validator = Guid::getValidators();

        foreach (Guid::getSupported() as $guid => $type) {
            $item = [
                'guid' => after($guid, 'guid_'),
                'type' => $type,
                'validator' => ag($validator, $guid, fn() => new \stdClass()),
            ];

            $list[] = $item;
        }

        return api_response(Status::OK, $list);
    }
}
