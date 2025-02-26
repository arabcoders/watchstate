<?php

declare(strict_types=1);

namespace App\API\System;

use App\Libs\Attributes\Route\Delete;
use App\Libs\Enums\Http\Status;
use App\Libs\Exceptions\RuntimeException;
use App\Libs\Mappers\ImportInterface as iImport;
use App\Libs\Traits\APITraits;
use Psr\Http\Message\ResponseInterface as iResponse;
use Psr\Http\Message\ServerRequestInterface as iRequest;
use Psr\Log\LoggerInterface as iLogger;
use Redis;
use RedisException;

final class Reset
{
    use APITraits;

    public const string URL = '%{api.prefix}/system/reset';

    #[Delete(self::URL . '[/]', name: 'system.reset')]
    public function __invoke(iRequest $request, Redis $redis, iImport $mapper, iLogger $logger): iResponse
    {
        try {
            $userContext = $this->getUserContext($request, $mapper, $logger);
            $user = $userContext->name;
        } catch (RuntimeException $e) {
            return api_error($e->getMessage(), Status::NOT_FOUND);
        }

        try {
            $ns = getAppVersion();
            $ns .= isValidName($user) ? '.' . $user : '.' . md5($user);

            $keys = $redis->keys("{$ns}*");

            if ($keys && is_array($keys)) {
                $redis->del($keys);
            }
        } catch (RedisException) {
        }

        $userContext->db->reset();

        foreach (array_keys($userContext->config->getAll()) as $name) {
            $userContext->config->set("{$name}.import.lastSync", null);
            $userContext->config->set("{$name}.export.lastSync", null);
        }

        $userContext->config->persist();

        return api_response(Status::OK, ['message' => 'System reset.']);
    }
}
