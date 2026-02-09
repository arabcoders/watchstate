<?php

declare(strict_types=1);

namespace App\API\System;

use App\Libs\Attributes\Route\Get;
use App\Libs\Attributes\Route\Route;
use App\Libs\Config;
use App\Libs\Enums\Http\Method;
use App\Libs\Enums\Http\Status;
use App\Libs\Stream;
use DirectoryIterator;
use finfo;
use Psr\Http\Message\ResponseInterface as iResponse;
use Psr\Http\Message\ServerRequestInterface as iRequest;

final class Backup
{
    public const string URL = '%{api.prefix}/system/backup';

    #[Get(self::URL . '[/]', name: 'system.backup')]
    public function list(): iResponse
    {
        $list = [];

        foreach (new DirectoryIterator(fix_path(Config::get('path') . '/backup')) as $file) {
            if ($file->isDot() || $file->isDir() || $file->isLink() || false === $file->isFile()) {
                continue;
            }

            $isAuto = 1 === preg_match('/^(\w+\.)?\w+\.\d{8}\.json(\.zip)?$/i', $file->getBasename());

            $builder = [
                'filename' => $file->getBasename(),
                'type' => $isAuto ? 'automatic' : 'manual',
                'size' => $file->getSize(),
                'date' => $file->getMTime(),
            ];

            $list[] = $builder;
        }

        $sorter = array_column($list, 'date');
        array_multisort($sorter, SORT_DESC, $list);

        foreach ($list as &$item) {
            $item['date'] = make_date(ag($item, 'date'));
        }

        return api_response(Status::OK, $list);
    }

    #[Route(['GET', 'DELETE'], self::URL . '/{filename}[/]', name: 'system.backup.view')]
    public function read(iRequest $request, string $filename): iResponse
    {
        $path = realpath(fix_path(Config::get('path') . '/backup'));

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

        $mime = new finfo(FILEINFO_MIME_TYPE)->file($filePath);

        return api_response(Status::OK, Stream::make($filePath, 'r'), headers: [
            'Content-Type' => false === $mime ? 'application/octet-stream' : $mime,
        ]);
    }
}
