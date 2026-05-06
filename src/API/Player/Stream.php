<?php

declare(strict_types=1);

namespace App\API\Player;

use App\Libs\Attributes\Route\Get;
use App\Libs\Enums\Http\Status;
use App\Libs\Stream as BodyStream;
use finfo;
use Psr\Http\Message\ResponseInterface as iResponse;
use Psr\Http\Message\ServerRequestInterface as iRequest;
use Psr\SimpleCache\CacheInterface as iCache;
use Psr\SimpleCache\InvalidArgumentException;
use SensitiveParameter;

final readonly class Stream
{
    public const string URL = Index::URL . '/stream';

    public function __construct(
        private iCache $cache,
    ) {}

    /**
     * Stream the signed media file directly with byte-range support.
     *
     * @throws InvalidArgumentException
     */
    #[Get(pattern: self::URL . '/{token}[/[{name:.*}[/]]]')]
    public function __invoke(iRequest $request, #[SensitiveParameter] string $token): iResponse
    {
        if (null === ($data = $this->cache->get($token, null))) {
            return api_error('Token is expired or invalid.', Status::BAD_REQUEST);
        }

        if (null === ($path = ag($data, 'path', null))) {
            return api_error('Path is empty.', Status::BAD_REQUEST);
        }

        $path = rawurldecode((string) $path);

        if (false === file_exists($path)) {
            return api_error('Path not found.', Status::NOT_FOUND);
        }

        if (false === is_file($path)) {
            return api_error(r("Path '{path}' is not a file.", ['path' => $path]), Status::BAD_REQUEST);
        }

        $size = filesize($path);
        if (false === $size) {
            return api_error('Unable to read file size.', Status::INTERNAL_SERVER_ERROR);
        }

        $mime = new finfo(FILEINFO_MIME_TYPE)->file($path);
        $headers = [
            'Accept-Ranges' => 'bytes',
            'Cache-Control' => 'no-cache',
            'Content-Disposition' => $this->getContentDisposition($path),
            'Content-Length' => (string) $size,
            'Content-Type' => false === $mime ? 'application/octet-stream' : $mime,
            'Last-Modified' => sprintf(
                '%s GMT',
                gmdate('D, d M Y H:i:s', false !== filemtime($path) ? filemtime($path) : time()),
            ),
            'X-Accel-Buffering' => 'no',
        ];

        $status = Status::OK;
        if ('' !== ($rangeHeader = trim($request->getHeaderLine('Range')))) {
            if (null === ($range = $this->parseRange($rangeHeader, $size))) {
                return api_response(Status::REQUESTED_RANGE_NOT_SATISFIABLE, headers: [
                    'Accept-Ranges' => 'bytes',
                    'Content-Range' => sprintf('bytes */%d', $size),
                    'Content-Type' => false === $mime ? 'application/octet-stream' : $mime,
                ]);
            }

            [$start, $end] = $range;
            $status = Status::PARTIAL_CONTENT;
            $headers['Content-Length'] = (string) ($end - $start + 1);
            $headers['Content-Range'] = sprintf('bytes %d-%d/%d', $start, $end, $size);
        }

        return api_response($status, body: BodyStream::make($path, 'rb'), headers: $headers);
    }

    /**
     * @return array{0:int,1:int}|null
     */
    private function parseRange(string $header, int $size): ?array
    {
        if (!preg_match('/^bytes=(\d*)-(\d*)$/', trim($header), $matches)) {
            return null;
        }

        $start = $matches[1];
        $end = $matches[2];

        if ('' === $start && '' === $end) {
            return null;
        }

        if ('' === $start) {
            $length = (int) $end;
            if ($length < 1) {
                return null;
            }

            $startInt = max(0, $size - $length);
            $endInt = $size - 1;
        } else {
            $startInt = (int) $start;
            $endInt = '' === $end ? $size - 1 : (int) $end;
        }

        if ($startInt < 0 || $endInt < $startInt || $startInt >= $size || $endInt >= $size) {
            return null;
        }

        return [$startInt, $endInt];
    }

    private function getContentDisposition(string $path): string
    {
        $name = basename($path);
        $fallback = str_replace('"', '\\"', $name);

        return sprintf(
            'inline; filename="%s"; filename*=utf-8\'\'%s',
            $fallback,
            rawurlencode($name),
        );
    }
}
