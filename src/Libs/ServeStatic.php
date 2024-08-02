<?php

declare(strict_types=1);

namespace App\Libs;

use App\Libs\Enums\Http\Status;
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
        'md' => 'text/markdown; charset=utf-8',
    ];

    /**
     * @var array<string, string> These files are served from outside the public directory.
     */
    private const array MD_FILES = [
        '/README.md' => __DIR__ . '/../../README.md',
        '/NEWS.md' => __DIR__ . '/../../NEWS.md',
        '/FAQ.md' => __DIR__ . '/../../FAQ.md',
    ];

    private const array MD_IMAGES = [
        '/screenshots' => __DIR__ . '/../../',
    ];
    private array $looked = [];

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
                code: Status::HTTP_METHOD_NOT_ALLOWED->value
            );
        }

        if (array_key_exists($requestPath, self::MD_FILES)) {
            return $this->serveFile($request, new SplFileInfo(self::MD_FILES[$requestPath]));
        }

        // -- check if the request path is in the MD_IMAGES array
        foreach (self::MD_IMAGES as $key => $value) {
            if (str_starts_with($requestPath, $key)) {
                $this->staticPath = realpath($value);
                break;
            }
        }

        $filePath = fixPath($this->staticPath . $requestPath);

        if (is_dir($filePath)) {
            $filePath = $filePath . '/index.html';
        }

        if (!file_exists($filePath)) {
            $checkIndex = $this->deepIndexLookup($this->staticPath, $requestPath);
            if (!file_exists($checkIndex)) {
                throw new NotFoundException(
                    message: r(
                        "File '{file}' is not found. {checkIndex} {looked}",
                        [
                            'file' => $requestPath,
                            'checkIndex' => $checkIndex,
                            'looked' => $this->looked,
                        ]
                    ),
                    code: Status::HTTP_NOT_FOUND->value
                );
            }
            $filePath = $checkIndex;
        }

        if (false === ($realBasePath = realpath($this->staticPath))) {
            throw new BadRequestException(
                message: r("The static path '{path}' doesn't exists.", ['path' => $this->staticPath]),
                code: Status::HTTP_SERVICE_UNAVAILABLE->value
            );
        }

        $filePath = realpath($filePath);

        if (false === $filePath || false === str_starts_with($filePath, $realBasePath)) {
            throw new BadRequestException(
                message: r("Request '{file}' is invalid.", ['file' => $requestPath]),
                code: Status::HTTP_BAD_REQUEST->value
            );
        }

        return $this->serveFile($request, new SplFileInfo($filePath));
    }

    private function serveFile(iRequest $request, SplFileInfo $file): iResponse
    {
        $ifModifiedSince = $request->getHeaderLine('if-modified-since');

        if (!empty($ifModifiedSince) && false !== $file->getMTime()) {
            try {
                $ifModifiedSince = makeDate($ifModifiedSince)->getTimestamp();
                if ($ifModifiedSince >= $file->getMTime()) {
                    return new Response(Status::HTTP_NOT_MODIFIED->value, [
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

        return new Response(Status::HTTP_OK->value, $headers, $stream);
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

    private function deepIndexLookup(string $base, string $path): string
    {
        // -- paths may look like /parent/id/child, do a deep lookup for index.html at each level
        // return the first index.html found
        $path = fixPath($path);

        if ('/' === $path) {
            return $path;
        }

        $paths = explode('/', $path);
        $count = count($paths);
        if ($count < 2) {
            return $path;
        }

        $index = $count - 1;

        for ($i = $index; $i > 0; $i--) {
            $check = $base . implode('/', array_slice($paths, 0, $i)) . '/index.html';
            $this->looked[] = $check;
            if (file_exists($check)) {
                return $check;
            }
        }

        return $path;
    }
}
