<?php

declare(strict_types=1);

namespace App\API\System;

use App\Libs\Attributes\Route\Delete;
use App\Libs\Enums\Http\Status;
use Psr\Http\Message\ResponseInterface as iResponse;
use Redis;
use RedisException;

final class Cache
{
    public const string URL = '%{api.prefix}/system/cache';

    #[Delete(self::URL . '[/]', name: 'system.cache')]
    public function purge_cache(Redis $redis): iResponse
    {
        try {
            $status = $redis->flushDB(true);
        } catch (RedisException $e) {
            return api_error($e->getMessage(), Status::INTERNAL_SERVER_ERROR);
        }

        return api_message(
            $status ? 'Cache purged successfully.' : 'Failed to purge cache.',
            $status ? Status::OK : Status::INTERNAL_SERVER_ERROR
        );
    }
}
