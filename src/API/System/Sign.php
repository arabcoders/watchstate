<?php

declare(strict_types=1);

namespace App\API\System;

use App\Libs\Attributes\Route\Post;
use App\Libs\Config;
use App\Libs\Container;
use App\Libs\Database\DatabaseInterface as iDB;
use App\Libs\DataUtil;
use App\Libs\Entity\StateInterface as iState;
use App\Libs\Enums\Http\Status;
use DateInterval;
use DateTimeInterface;
use Psr\Http\Message\ResponseInterface as iResponse;
use Psr\Http\Message\ServerRequestInterface as iRequest;
use Psr\SimpleCache\CacheInterface as iCache;

final readonly class Sign
{
    public const string URL = '%{api.prefix}/system/sign';

    public function __construct(private iCache $cache, private iDB $db)
    {
    }

    /**
     * @throws \Exception if the time passed to the DateInterval is invalid.
     */
    #[Post(pattern: self::URL . '/{id:number}[/]')]
    public function __invoke(iRequest $request, string $id): iResponse
    {
        $params = DataUtil::fromRequest($request);

        if (null === ($path = $params->get('path', null))) {
            return api_error('Path is empty.', Status::BAD_REQUEST);
        }

        if (false === file_exists($path)) {
            return api_error('Path not found.', Status::NOT_FOUND);
        }

        $entity = $this->db->get(Container::get(iState::class)::fromArray([iState::COLUMN_ID => $id]));

        if (null === $entity) {
            return api_error('Reference entity not found.', Status::BAD_REQUEST);
        }

        $time = $params->get('time', 'PT24H');
        $expires = new DateInterval($time);

        $key = self::sign([
            'id' => $id,
            'path' => $path,
            'time' => $time,
            'config' => $params->get('config'),
            'version' => getAppVersion(),
        ], $expires, $this->cache);

        return api_response(Status::OK, [
            'token' => $key,
            'secure' => (bool)Config::get('api.secure', false),
            'expires' => makeDate()->add($expires)->format(DateTimeInterface::ATOM),
        ]);
    }

    public static function sign(array $data, DateInterval|null $ttl = null, iCache|null $cache = null): string
    {
        if (null === $cache) {
            $cache = Container::get(iCache::class);
        }

        $key = self::key($data);

        /** @noinspection PhpUnhandledExceptionInspection */
        $cache->set($key, $data, $ttl);

        return $key;
    }

    public static function update(
        string $key,
        array $data,
        DateInterval|null $ttl = null,
        iCache|null $cache = null
    ): bool {
        if (null === $cache) {
            $cache = Container::get(iCache::class);
        }

        /** @noinspection PhpUnhandledExceptionInspection */
        return $cache->set($key, $data, $ttl);
    }

    private static function key(array $config): string
    {
        return 'play-' . substr(bin2hex(openssl_digest(json_encode($config), 'shake256', true)), 0, 12);
    }
}
