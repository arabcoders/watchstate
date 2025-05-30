<?php

declare(strict_types=1);

namespace App\API\System;

use App\Libs\APIResponse;
use App\Libs\Attributes\Route\Get;
use App\Libs\Database\DBLayer;
use App\Libs\Enums\Http\Method;
use App\Libs\Enums\Http\Status;
use App\Libs\Mappers\ImportInterface as iImport;
use App\Libs\Traits\APITraits;
use DateInterval;
use Psr\Http\Message\ResponseInterface as iResponse;
use Psr\Http\Message\ServerRequestInterface as iRequest;
use Psr\Log\LoggerInterface as iLogger;
use Psr\SimpleCache\CacheInterface as iCache;
use Psr\SimpleCache\InvalidArgumentException;
use RuntimeException;

final class Images
{
    use APITraits;

    public const string URL = '%{api.prefix}/system/images';

    public function __construct(
        private readonly iImport $mapper,
        private readonly iLogger $logger,
        private readonly iCache $cache,
    ) {
    }

    #[Get(self::URL . '/{type:poster|background}[/]', name: 'system.images')]
    public function __invoke(iRequest $request, DBLayer $db, string $type): iResponse
    {
        try {
            $uc = $this->getUserContext($request, $this->mapper, $this->logger);
            if (count($uc->config) < 1) {
                return api_response(Status::NO_CONTENT);
            }
            $resp = $this->getImage($db, $type, force: (bool)ag($request->getQueryParams(), 'force', false));
        } catch (InvalidArgumentException|RuntimeException) {
            return api_response(Status::NO_CONTENT);
        }

        $headers = [];

        $removeHeaders = ['pragma', 'cache-control', 'expires'];

        foreach ($resp->headers as $key => $value) {
            if (false === in_array(strtolower($key), $removeHeaders)) {
                $headers[$key] = $value;
            }
        }

        return api_response($resp->status, $resp->stream, $headers);
    }

    /**
     * @throws InvalidArgumentException
     */
    public function getImage(DBLayer $db, string $type, int|null $oldId = null, bool $force = false): APIResponse
    {
        $cacheKey = r('system.images.{type}', ['type' => $type]);

        if (null === $oldId && false === $force && $this->cache->has($cacheKey)) {
            $id = (int)$this->cache->get($cacheKey);
        } else {
            $record = $db->query('SELECT id FROM "state" ORDER BY RANDOM() LIMIT 1');
            $id = $record->fetchColumn();
            if (empty($id)) {
                throw new RuntimeException('No records found');
            }
        }

        $id = (int)$id;

        $resp = APIRequest(Method::GET, r('/history/{id}/images/{type}', ['id' => $id, 'type' => $type]));

        if ($resp->status !== Status::OK) {
            if ($id === $oldId) {
                throw new RuntimeException('No record found.');
            }
            return $this->getImage($db, $type, $id);
        }

        $this->cache->set($cacheKey, $id, new DateInterval('PT1H'));

        return $resp;
    }
}
