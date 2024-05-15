<?php

declare(strict_types=1);

namespace App\Libs;

use finfo;
use League\Route\Http\Exception\BadRequestException;
use League\Route\Http\Exception\NotFoundException;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface as iResponse;
use Psr\Http\Message\ServerRequestInterface as iRequest;
use SplFileInfo;
use Throwable;

final class ServeStatic
{
    private finfo|null $mimeType = null;

    private const array CONTENT_TYPE = [
        'css' => 'text/css; charset=utf-8',
        'js' => 'text/javascript; charset=utf-8',
        'html' => 'text/html; charset=utf-8',
        'woff2' => 'font/woff2',
        'ico' => 'image/x-icon',
        'json' => 'application/json; charset=utf-8',
    ];

    public function __construct(private string|null $staticPath = null)
    {
        if (null === $this->staticPath) {
            $this->staticPath = Config::get('webui.path', __DIR__ . '/../../public/exported');
        }
    }

    /**
     * Serve Static Resources.
     *
     * @param iRequest $request the request object
     *
     * @return iResponse the response object
     * @throws BadRequestException if incorrect path was given.
     * @throws NotFoundException if file was not found.
     */
    public function serve(iRequest $request): iResponse
    {
        $requestPath = $request->getUri()->getPath();

        if (false === in_array($request->getMethod(), ['GET', 'HEAD', 'OPTIONS'])) {
            throw new BadRequestException(
                message: r("Method '{method}' is not allowed.", ['method' => $request->getMethod()]),
                code: HTTP_STATUS::HTTP_METHOD_NOT_ALLOWED->value
            );
        }

        $filePath = $this->staticPath . $requestPath;

        if (is_dir($filePath)) {
            $filePath = $filePath . '/index.html';
        }

        $filePath = fixPath($filePath);

        if (!file_exists($filePath)) {
            $checkIndex = dirname($filePath) . '/index.html';
            if (!file_exists($checkIndex)) {
                throw new NotFoundException(
                    message: r("File '{file}' is not found.", ['file' => $requestPath]),
                    code: HTTP_STATUS::HTTP_NOT_FOUND->value
                );
            }
            $filePath = $checkIndex;
        }

        $filePath = realpath($filePath);

        if (false === str_starts_with($filePath, $this->staticPath)) {
            throw new BadRequestException(
                message: r("Request '{file}' is invalid.", ['file' => $requestPath]),
                code: HTTP_STATUS::HTTP_BAD_REQUEST->value
            );
        }

        $file = new SplFileInfo($filePath);

        $ifModifiedSince = $request->getHeaderLine('if-modified-since');

        if (!empty($ifModifiedSince) && false !== $file->getMTime()) {
            try {
                $ifModifiedSince = makeDate($ifModifiedSince)->getTimestamp();
                if ($ifModifiedSince >= $file->getMTime()) {
                    return new Response(HTTP_STATUS::HTTP_NOT_MODIFIED->value, [
                        'Last-Modified' => gmdate('D, d M Y H:i:s T', $file->getMTime())
                    ]);
                }
            } catch (Throwable) {
            }
        }

        $headers = [
            'Date' => gmdate('D, d M Y H:i:s T'),
            'Content-Length' => $file->getSize(),
            'Last-Modified' => gmdate('D, d M Y H:i:s T', $file->getMTime()),
            'Content-Type' => $this->getMimeType($file),
        ];

        $stream = null;

        if ('GET' === $request->getMethod()) {
            $headers['Cache-Control'] = 'public, max-age=31536000';
            $stream = Stream::make($file->getRealPath());
        }

        return new Response(HTTP_STATUS::HTTP_OK->value, $headers, $stream);
    }

    /**
     * Get the content type of the file.
     *
     * @param SplFileInfo $file the file object
     *
     * @return string
     */
    private function getMimeType(SplFileInfo $file): string
    {
        if (array_key_exists($file->getExtension(), self::CONTENT_TYPE)) {
            return self::CONTENT_TYPE[$file->getExtension()];
        }

        if (null === $this->mimeType) {
            $this->mimeType = new finfo(FILEINFO_MIME_TYPE);
        }

        $mime = $this->mimeType->file($file->getRealPath());

        return false === $mime ? 'application/octet-stream' : $mime;
    }
}
