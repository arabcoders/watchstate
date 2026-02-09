<?php

declare(strict_types=1);

namespace App\API\System;

use App\Libs\Attributes\Route\Delete;
use App\Libs\Attributes\Route\Post;
use App\Libs\Enums\Http\Status;
use App\Libs\Mappers\ImportInterface as iImport;
use Psr\Http\Message\ResponseInterface as iResponse;
use Psr\Http\Message\ServerRequestInterface as iRequest;
use Psr\Log\LoggerInterface as iLogger;
use Redis;
use RedisException;

final class Reset
{
    public const string URL = '%{api.prefix}/system/reset';

    #[Delete(self::URL . '[/]', name: 'system.reset')]
    public function reset(iRequest $request, Redis $redis, iImport $mapper, iLogger $logger): iResponse
    {
        foreach (get_users_context($mapper, $logger) as $userContext) {
            // -- reset database.
            $userContext->db->reset();

            // -- reset last import/export date.
            foreach (array_keys($userContext->config->getAll()) as $name) {
                $userContext->config->set("{$name}.import.lastSync", null);
                $userContext->config->set("{$name}.export.lastSync", null);
            }

            // -- persist changes.
            $userContext->config->persist(true);
        }

        // -- reset cache data.
        try {
            $redis->flushDB();
        } catch (RedisException) {
        }

        return api_response(Status::OK, ['message' => 'System reset is complete.']);
    }

    #[Post(self::URL . '/opcache[/]', name: 'system.reset.opcache')]
    public function opcache(iRequest $request, Redis $redis, iImport $mapper, iLogger $logger): iResponse
    {
        return api_response(Status::OK, [
            'message' => opcache_reset() ? 'OPCache reset is complete.' : 'OPCache reset failed.',
        ]);
    }
}
