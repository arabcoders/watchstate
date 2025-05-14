<?php

declare(strict_types=1);

namespace App\Libs;

use App\Libs\Enums\Http\Status;
use finfo;
use League\Route\Http\Exception\BadRequestException;
use League\Route\Http\Exception\NotFoundException;
use Psr\Http\Message\ResponseInterface as iResponse;
use Psr\Http\Message\ServerRequestInterface as iRequest;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use SplFileInfo;
use Throwable;

final class ServeStatic implements LoggerAwareInterface
{
    use LoggerAwareTrait;

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
        '/CHANGELOG.md' => __DIR__ . '/../../CHANGELOG.md',
    ];

    private const array MD_IMAGES = [
        '/screenshots' => __DIR__ . '/../../',
        '/guides' => __DIR__ . '/../../',
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
        if (false === in_array($request->getMethod(), ['GET', 'HEAD', 'OPTIONS'])) {
            throw new BadRequestException(
                message: r("Method '{method}' is not allowed.", ['method' => $request->getMethod()]),
                code: Status::METHOD_NOT_ALLOWED->value
            );
        }

        // -- as we alter the static path for .md files, we need to keep the original path
        // -- do not mutate the original path. as it may be used in other requests.
        $staticPath = $this->staticPath;
        $requestPath = $request->getUri()->getPath();

        if (array_key_exists($requestPath, self::MD_FILES)) {
            return $this->serveFile($request, new SplFileInfo(self::MD_FILES[$requestPath]));
        }

        // -- check if the request path is in the MD_IMAGES array
        foreach (self::MD_IMAGES as $key => $value) {
            if (str_starts_with($requestPath, $key)) {
                $staticPath = realpath($value);
                break;
            }
        }

        if (false === ($realBasePath = realpath($staticPath))) {
            throw new BadRequestException(
                message: r("The static path '{path}' doesn't exists.", ['path' => $staticPath]),
                code: Status::SERVICE_UNAVAILABLE->value
            );
        }

        $filePath = fixPath($staticPath . $requestPath);
        if (is_dir($filePath)) {
            $filePath = $filePath . '/index.html';
        }

        if (!file_exists($filePath)) {
            $this->logger?->debug("File '{file}' is not found.", ['file' => $filePath]);
            $checkIndex = fixPath($staticPath . $this->deepIndexLookup($staticPath, $requestPath));
            if (false === file_exists($checkIndex) || false === is_file($checkIndex)) {
                throw new NotFoundException(r("Path '{file}' is not found.", [
                    'file' => $requestPath,
                ]), code: Status::NOT_FOUND->value);
            }
            $filePath = $checkIndex;
        }


        $filePath = realpath($filePath);
        if (false === $filePath || false === str_starts_with($filePath, $realBasePath)) {
            throw new BadRequestException(
                message: r("Request '{file}' is invalid.", ['file' => $requestPath]),
                code: Status::BAD_REQUEST->value
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
                    return api_response(Status::NOT_MODIFIED, headers: [
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

        return api_response(Status::OK, body: $stream, headers: $headers);
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
        if ('/' === $path || empty($path)) {
            return $path;
        }

        $paths = explode('/', $path);
        $count = count($paths);
        $index = $count - 1;

        if ($index < 2) {
            return $path;
        }

        for ($i = $index; $i > 0; $i--) {
            $check = implode('/', array_slice($paths, 0, $i)) . '/index.html';
            if (file_exists($base . $check)) {
                return $check;
            }
        }

        return $path;
    }
}
