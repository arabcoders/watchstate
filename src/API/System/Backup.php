<?php

declare(strict_types=1);

namespace App\API\System;

use App\Libs\Attributes\Route\Get;
use App\Libs\Attributes\Route\Route;
use App\Libs\Config;
use App\Libs\HTTP_STATUS;
use App\Libs\Stream;
use finfo;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface as iResponse;
use Psr\Http\Message\ServerRequestInterface as iRequest;

final class Backup
{
    public const string URL = '%{api.prefix}/system/backup';

    #[Get(self::URL . '[/]', name: 'system.backup')]
    public function __invoke(iRequest $request, array $args = []): iResponse
    {
        $path = fixPath(Config::get('path') . '/backup');

        $list = [];

        foreach (glob($path . '/*.json') as $file) {
            $isTemp = 1 === preg_match('/\w+\.\d+\.json/i', basename($file));

            $builder = [
                'filename' => basename($file),
                'type' => $isTemp ? 'temporary' : 'permanent',
                'size' => filesize($file),
                'created_at' => filectime($file),
                'modified_at' => filemtime($file),
            ];

            $list[] = $builder;
        }

        $sorter = array_column($list, 'created_at');
        array_multisort($sorter, SORT_DESC, $list);

        return api_response(HTTP_STATUS::HTTP_OK, $list);
    }

    #[Route(['GET', 'DELETE'], self::URL . '/{filename}[/]', name: 'system.backup.view')]
    public function logView(iRequest $request, array $args = []): iResponse
    {
        if (null === ($filename = ag($args, 'filename'))) {
            return api_error('Invalid value for filename path parameter.', HTTP_STATUS::HTTP_BAD_REQUEST);
        }

        $path = realpath(fixPath(Config::get('path') . '/backup'));

        $filePath = realpath($path . '/' . $filename);

        if (false === $filePath) {
            return api_error('File not found.', HTTP_STATUS::HTTP_NOT_FOUND);
        }

        if (false === str_starts_with($filePath, $path)) {
            return api_error('Invalid file path.', HTTP_STATUS::HTTP_BAD_REQUEST);
        }

        if ('DELETE' === $request->getMethod()) {
            unlink($filePath);
            return api_response(HTTP_STATUS::HTTP_OK);
        }

        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($filePath);

        return new Response(
            status: HTTP_STATUS::HTTP_OK->value,
            headers: [
                'Content-Type' => false === $mime ? 'application/octet-stream' : $mime,
                'Content-Length' => filesize($filePath),
            ],
            body: Stream::make($filePath, 'r')
        );
    }
}
