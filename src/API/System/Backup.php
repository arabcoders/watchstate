<?php

declare(strict_types=1);

namespace App\API\System;

use App\Libs\Attributes\Route\Get;
use App\Libs\Attributes\Route\Route;
use App\Libs\Config;
use App\Libs\Enums\Http\Method;
use App\Libs\Enums\Http\Status;
use App\Libs\Stream;
use finfo;
use Psr\Http\Message\ResponseInterface as iResponse;
use Psr\Http\Message\ServerRequestInterface as iRequest;

final class Backup
{
    public const string URL = '%{api.prefix}/system/backup';

    #[Get(self::URL . '[/]', name: 'system.backup')]
    public function list(): iResponse
    {
        $path = fixPath(Config::get('path') . '/backup');

        $list = [];

        foreach (glob($path . '/*.json') as $file) {
            $isAuto = 1 === preg_match('/\w+\.\d{8}\.json/i', basename($file));

            $builder = [
                'filename' => basename($file),
                'type' => $isAuto ? 'automatic' : 'manual',
                'size' => filesize($file),
                'date' => filemtime($file),
            ];

            $list[] = $builder;
        }

        $sorter = array_column($list, 'date');
        array_multisort($sorter, SORT_DESC, $list);

        foreach ($list as &$item) {
            $item['date'] = makeDate(ag($item, 'date'));
        }

        return api_response(Status::OK, $list);
    }

    #[Route(['GET', 'DELETE'], self::URL . '/{filename}[/]', name: 'system.backup.view')]
    public function read(iRequest $request, string $filename): iResponse
    {
        $path = realpath(fixPath(Config::get('path') . '/backup'));

        $filePath = realpath($path . '/' . $filename);

        if (false === $filePath) {
            return api_error('File not found.', Status::NOT_FOUND);
        }

        if (false === str_starts_with($filePath, $path)) {
            return api_error('Invalid file path.', Status::BAD_REQUEST);
        }

        if (Method::DELETE === Method::from($request->getMethod())) {
            unlink($filePath);
            return api_response(Status::OK);
        }

        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($filePath);

        return api_response(Status::OK, Stream::make($filePath, 'r'), headers: [
            'Content-Type' => false === $mime ? 'application/octet-stream' : $mime,
        ]);
    }
}
