<?php

declare(strict_types=1);

namespace App\API\System;

use App\Libs\APIResponse;
use App\Libs\Attributes\Route\Get;
use App\Libs\Database\DBLayer;
use App\Libs\Enums\Http\Method;
use App\Libs\Enums\Http\Status;
use Psr\Http\Message\ResponseInterface as iResponse;
use RuntimeException;

final class Images
{
    public const string URL = '%{api.prefix}/system/images';

    #[Get(self::URL . '/{type:poster|background}[/]', name: 'system.images')]
    public function __invoke(DBLayer $db, string $type): iResponse
    {
        try {
            $resp = $this->getImage($db, $type);
        } catch (RuntimeException) {
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

    public function getImage(DBLayer $db, string $type, int|null $oldId = null): APIResponse
    {
        $record = $db->query('SELECT id FROM "state" ORDER BY RANDOM() LIMIT 1');
        $id = $record->fetchColumn();

        if (empty($id)) {
            throw new RuntimeException('No records found');
        }

        $id = (int)$id;

        $resp = APIRequest(Method::GET, r('/history/{id}/images/{type}', ['id' => $id, 'type' => $type]));

        if ($resp->status !== Status::OK) {
            if ($id === $oldId) {
                throw new RuntimeException('No record found.');
            }
            return $this->getImage($db, $type, $id);
        }

        return $resp;
    }
}
