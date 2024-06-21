<?php

declare(strict_types=1);

namespace App\API\System;

use App\Libs\Attributes\Route\Delete;
use App\Libs\Config;
use App\Libs\ConfigFile;
use App\Libs\Database\DatabaseInterface as iDB;
use App\Libs\HTTP_STATUS;
use Psr\Http\Message\ResponseInterface as iResponse;
use Psr\Http\Message\ServerRequestInterface as iRequest;
use Redis;
use RedisException;

final class Reset
{
    public const string URL = '%{api.prefix}/system/reset';

    public function __construct(private Redis $redis, private iDB $db)
    {
    }

    #[Delete(self::URL . '[/]', name: 'system.reset')]
    public function __invoke(iRequest $request, array $args = []): iResponse
    {
        $this->db->reset();

        try {
            $this->redis->flushDB();
        } catch (RedisException) {
        }

        $list = ConfigFile::open(Config::get('backends_file'), 'yaml', autoCreate: true);

        foreach ($list->getAll() as $name => $backend) {
            $list->set("{$name}.import.lastSync", null);
            $list->set("{$name}.export.lastSync", null);
        }

        $list->persist();

        return api_response(HTTP_STATUS::HTTP_OK, ['message' => 'System reset.']);
    }
}
