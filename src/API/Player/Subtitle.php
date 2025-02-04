<?php

declare(strict_types=1);

namespace App\API\Player;

use App\Libs\Attributes\Route\Get;
use App\Libs\Config;
use App\Libs\Enums\Http\Status;
use App\Libs\Stream;
use App\Libs\VttConverter;
use JsonException;
use Psr\Http\Message\ResponseInterface as iResponse;
use Psr\Http\Message\ServerRequestInterface as iRequest;
use Psr\Log\LoggerInterface as iLogger;
use Psr\SimpleCache\CacheInterface as iCache;
use Psr\SimpleCache\InvalidArgumentException;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

final readonly class Subtitle
{
    public const array FORMATS = [
        'vtt' => 'text/vtt',
        'webvtt' => 'text/vtt',
        'srt' => 'text/srt',
        'ass' => 'text/ass',
    ];

    public const array INTERNAL_NAMING = [
        'subrip',
        'ass',
        'vtt'
    ];

    public const string URL = '%{api.prefix}/player/subtitle';
    private const string EXTERNAL = 'x';
    private const string INTERNAL = 'i';

    public function __construct(private iCache $cache, private iLogger $logger)
    {
    }

    /**
     * @throws InvalidArgumentException
     */
    #[Get(pattern: self::URL . '/{token}/{type}.{source:\w{1}}{index:number}.m3u8')]
    public function m3u8(iRequest $request, string $token, string $source, string $index): iResponse
    {
        if (null === ($data = $this->cache->get($token, null))) {
            return api_error('Token is expired or invalid.', Status::BAD_REQUEST);
        }

        if ($request->hasHeader('if-modified-since')) {
            return api_response(Status::NOT_MODIFIED, headers: ['Cache-Control' => 'public, max-age=25920000']);
        }

        if ('x' === $source) {
            $subtitles = ag($data, 'config.externals', []);
            if (empty($subtitles)) {
                return api_error('No external subtitles found.', Status::BAD_REQUEST);
            }

            $subtitle = array_filter($subtitles, fn($s) => $s === (int)$index, ARRAY_FILTER_USE_KEY);
            if (empty($subtitle)) {
                return api_error('Subtitle not found.', Status::BAD_REQUEST);
            }

            $subtitle = array_shift($subtitle);
        }

        $isSecure = (bool)Config::get('api.secure', false);
        $subtitleUrl = parseConfigValue(Subtitle::URL);

        $lines = [];
        $lines[] = '#EXTM3U';
        $lines[] = '#EXT-X-TARGETDURATION:' . ag($data, 'config.duration');
        $lines[] = '#EXT-X-PLAYLIST-TYPE:VOD';
        $lines[] = '#EXT-X-VERSION:3';
        $lines[] = '#EXT-X-MEDIA-SEQUENCE:0';

        $lines[] = '#EXTINF:' . ag($data, 'config.duration') . ',';
        $lines[] = r('{api_url}/{token}/{source}{index}.webvtt{auth}', [
            'api_url' => $subtitleUrl,
            'token' => $token,
            'source' => $source,
            'index' => $index,
            'auth' => $isSecure ? '?apikey=' . Config::get('api.key') : '',
        ]);
        $lines[] = '#EXT-X-ENDLIST';

        return api_response(Status::OK, Stream::create(implode("\n", $lines)), [
            'Content-Type' => 'application/x-mpegurl',
            'Pragma' => 'public',
            'Cache-Control' => sprintf('public, max-age=%s', time() + 31536000),
            'Last-Modified' => sprintf('%s GMT', gmdate('D, d M Y H:i:s', time())),
            'Expires' => sprintf('%s GMT', gmdate('D, d M Y H:i:s', time() + 31536000)),
            'Access-Control-Max-Age' => 3600 * 24 * 30,
        ]);
    }

    /**
     * @throws InvalidArgumentException
     */
    #[Get(pattern: self::URL . '/{token}/{source:\w{1}}{index:\d{1}}.{ext:\w{3,10}}')]
    public function convert(iRequest $request, string $token, string $source, string $index): iResponse
    {
        if (null === ($data = $this->cache->get($token, null))) {
            return api_error('Token is expired or invalid.', Status::BAD_REQUEST);
        }

        if ($request->hasHeader('if-modified-since')) {
            return api_response(Status::NOT_MODIFIED, headers: ['Cache-Control' => 'public, max-age=25920000']);
        }

        $stream = null;

        switch ($source) {
            case self::EXTERNAL:
                {
                    $subtitles = ag($data, 'config.externals', []);
                    if (empty($subtitles)) {
                        return api_error('No external subtitles found.', Status::BAD_REQUEST);
                    }

                    $subtitle = array_filter($subtitles, fn($s) => $s === (int)$index, ARRAY_FILTER_USE_KEY);

                    if (empty($subtitle)) {
                        return api_error('Subtitle not found.', Status::BAD_REQUEST);
                    }

                    $subtitle = array_shift($subtitle);

                    if (null === ($path = ag($subtitle, 'path'))) {
                        return api_error('Subtitle path not found.', Status::BAD_REQUEST);
                    }

                    $path = rawurldecode($path);
                }
                break;
            case self::INTERNAL:
                {
                    if (null === ($path = ag($data, 'path', null))) {
                        return api_error('Path is empty.', Status::BAD_REQUEST);
                    }
                    $path = rawurldecode($path);
                    $stream = (int)$index;
                }
                break;
            default:
                return api_error(r("Invalid source '{source}' was specified.", [
                    'source' => $source
                ]), Status::BAD_REQUEST);
        }

        $response = $this->make(
            $path,
            $stream,
            (bool)ag($data, 'config.debug', false),
            (bool)ag($request->getQueryParams(), 'reload', false)
        );

        if (Status::OK !== Status::from($response->getStatusCode())) {
            return $response;
        }

        try {
            return api_response(Status::from($response->getStatusCode()), $response->getBody(), [
                'Content-Type' => $response->getHeaderLine('Content-Type'),
                'Pragma' => 'public',
                'Cache-Control' => sprintf('public, max-age=%s', time() + 31536000),
                'Last-Modified' => sprintf('%s GMT', gmdate('D, d M Y H:i:s', time())),
                'Expires' => sprintf('%s GMT', gmdate('D, d M Y H:i:s', time() + 31536000)),
                'Access-Control-Max-Age' => 3600 * 24 * 30,
                'X-Cache' => $response->getHeaderLine('X-Cache'),
            ]);
        } catch (Throwable $e) {
            $this->logger->error($e->getMessage(), $e->getTrace());
            return api_error($e->getMessage(), Status::INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * @throws InvalidArgumentException
     */
    private function make(string $file, int|null $stream = null, bool $debug = false, bool $noCache = false): iResponse
    {
        if (false === file_exists($file)) {
            return api_error(r("Path '{path}' is not found.", ['path' => $file]), Status::NOT_FOUND);
        }

        if (false === is_file($file)) {
            return api_error(r("Path '{path}' is not a file.", ['path' => $file]), Status::BAD_REQUEST);
        }

        $type = 'webvtt';
        $size = filesize($file);
        $kStream = '';
        if (null !== $stream) {
            $kStream = ":{$stream}";
        }

        $cacheKey = md5("{$file}{$kStream}:{$size}");
        if (false === $noCache && $this->cache->has($cacheKey)) {
            return api_response(Status::OK, Stream::create($this->cache->get($cacheKey)), [
                'Content-Type' => 'text/vtt',
                'X-Accel-Buffering' => 'no',
                'Access-Control-Allow-Origin' => '*',
                'Access-Control-Max-Age' => 300,
                'X-Cache' => 'hit',
            ]);
        }

        if (null === $stream && !array_key_exists(getExtension($file), self::FORMATS)) {
            return api_error("Unsupported subtitle file.", Status::BAD_REQUEST);
        }

        $tmpFile = sys_get_temp_dir() . '/ffmpeg_' . $cacheKey . '.' . $type;
        if (!file_exists($tmpFile)) {
            symlink($file, $tmpFile);
        }

        if (null !== $stream) {
            try {
                $streamInfo = $this->getStream(ag(ffprobe_file($file, $this->cache), 'streams', []), $stream);
                $codecType = ag($streamInfo, 'codec_type', '');

                if ('subtitle' !== $codecType) {
                    return api_error("Only subtitle stream conversion is supported.", Status::BAD_REQUEST, $streamInfo);
                }

                $codec = ag($streamInfo, 'codec_name', '');

                if (false === in_array($codec, self::INTERNAL_NAMING)) {
                    return api_error(r("This codec type '{codec}' is not supported.", [
                        'codec' => $codec
                    ]), Status::BAD_REQUEST, $streamInfo);
                }
            } catch (RuntimeException|JsonException $e) {
                return api_error($e->getMessage(), Status::INTERNAL_SERVER_ERROR);
            }
        }

        $cmd = [
            'ffmpeg',
            '-xerror',
            '-hide_banner',
            '-loglevel',
            'error',
            '-i',
            'file:' . $tmpFile
        ];

        if (null !== $stream) {
            $cmd[] = '-map';
            $cmd[] = "0:{$stream}";
        }

        $cmd[] = '-f';
        $cmd[] = $type;
        $cmd[] = 'pipe:1';

        try {
            $process = new Process($cmd);
            $process->setTimeout($stream ? 120 : 60);
            $process->start();

            $process->wait();

            if (!$process->isSuccessful()) {
                if (true === $debug) {
                    return api_error($process->getErrorOutput(), Status::INTERNAL_SERVER_ERROR, headers: [
                        'X-FFmpeg' => $process->getCommandLine()
                    ]);
                }

                return api_error('Failed to convert subtitle.', Status::INTERNAL_SERVER_ERROR);
            }

            $body = $process->getOutput();

            try {
                $vtt = VttConverter::parse($body);
                if (!empty($vtt) && count($vtt) > 2) {
                    $firstKey = array_key_first($vtt);
                    $lastKey = array_key_last($vtt);

                    if (null !== $firstKey && null !== $lastKey && $firstKey !== $lastKey) {
                        $firstEndTime = $vtt[$firstKey]['end'];
                        $lastEndTime = $vtt[$lastKey]['end'];
                        if ($firstEndTime === $lastEndTime) {
                            unset($vtt[$firstKey]);
                            $body = VttConverter::export($vtt);
                        }
                    }
                }
            } catch (Throwable) {
                // -- pass subtitles as it is.
            }

            $this->cache->set($cacheKey, $body);

            return api_response(Status::OK, Stream::create($body), [
                'Content-Type' => self::FORMATS[$type],
                'X-Cache' => 'miss'
            ]);
        } catch (Throwable $e) {
            return api_error($e->getMessage(), Status::INTERNAL_SERVER_ERROR);
        } finally {
            if (file_exists($tmpFile) && is_link($tmpFile)) {
                unlink($tmpFile);
            }
        }
    }

    private function getStream(array $streams, int $index): array
    {
        foreach ($streams as $stream) {
            if ((int)ag($stream, 'index') === $index) {
                return $stream;
            }
        }
        return [];
    }
}
