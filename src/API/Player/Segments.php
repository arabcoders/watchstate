<?php

declare(strict_types=1);

namespace App\API\Player;

use App\Libs\Attributes\Route\Get;
use App\Libs\DataUtil;
use App\Libs\Enums\Http\Status;
use App\Libs\Stream;
use DateInterval;
use JsonException;
use Psr\Http\Message\ResponseInterface as iResponse;
use Psr\Http\Message\ServerRequestInterface as iRequest;
use Psr\Log\LoggerInterface as iLogger;
use Psr\SimpleCache\CacheInterface as iCache;
use Psr\SimpleCache\InvalidArgumentException;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

readonly class Segments
{
    public const string URL = '%{api.prefix}/player/segments';

    private const array OVERLAY = [
        'hdmv_pgs_subtitle',
        'dvd_subtitle',
    ];

    public function __construct(private iCache $cache, private iLogger $logger)
    {
    }

    /**
     * @throws InvalidArgumentException
     */
    #[Get(pattern: self::URL . '/{token}/{segment}[.{type}]')]
    public function __invoke(iRequest $request, string $token, string $segment): iResponse
    {
        if (null === ($json = $this->cache->get($token, null))) {
            return api_error('Token is expired or invalid.', Status::BAD_REQUEST);
        }

        if ($request->hasHeader('if-modified-since')) {
            return api_response(Status::NOT_MODIFIED, headers: ['Cache-Control' => 'public, max-age=25920000']);
        }

        if (null === ($path = ag($json, 'path', null))) {
            return api_error('Path is empty.', Status::BAD_REQUEST);
        }

        $path = rawurldecode($path);

        if (false === file_exists($path)) {
            return api_error('Path not found.', Status::NOT_FOUND);
        }

        if (!is_file($path)) {
            return api_error(r("Path '{path}' is not a file.", ['path' => $path]), Status::BAD_REQUEST);
        }

        $segment = (int)$segment;
        $sConfig = (array)ag($json, 'config', []);

        try {
            $json = ffprobe_file($path, $this->cache);
        } catch (RuntimeException|JsonException $e) {
            return api_error($e->getMessage(), Status::INTERNAL_SERVER_ERROR);
        }

        $incr = 0;
        $subIndex = [];
        $internalSubs = [];
        foreach (ag($json, 'streams', []) as $id => $stream) {
            if ('subtitle' !== ag($stream, 'codec_type')) {
                continue;
            }
            $subIndex[$id] = $incr;
            $internalSubs[$incr] = $stream;
            $incr++;
        }

        $sConfig['segment_size'] = number_format((int)$sConfig['segment_size'], 6);

        $params = DataUtil::fromArray($sConfig);

        $audio = $params->get('audio');
        $subtitle = $params->get('subtitle');
        $external = $params->get('external', null);
        $hwaccel = (bool)$params->get('hwaccel', false);
        $vaapi_device = $params->get('vaapi_device', '/dev/dri/renderD128');
        $vCodec = $params->get('video_codec', $hwaccel ? 'h264_vaapi' : 'libx264');
        $isIntel = $hwaccel && 'h264_vaapi' === $vCodec;
        $segmentSize = number_format((int)$params->get('segment_size', Playlist::SEGMENT_DUR), 6);

        if ($hwaccel && false === file_exists($vaapi_device)) {
            return api_error(r("VAAPI device '{device}' not found.", ['device' => $vaapi_device]), Status::BAD_REQUEST);
        }

        if (null !== $external && false === file_exists($external)) {
            return api_error(r("External subtitle '{path}' not found.", ['path' => $external]), Status::NOT_FOUND);
        }

        // -- Only transcode one segment per file, Otherwise wait until it finishes.
        $tmpVidLock = r("{path}/t-{name}.lock", [
            'path' => sys_get_temp_dir(),
            'name' => $token,
            'type' => getExtension($path),
        ]);

        while (true === file_exists($tmpVidLock)) {
            $pid = (int)file_get_contents($tmpVidLock);
            if (false === file_exists("/proc/{$pid}")) {
                @unlink($tmpVidLock);
                break;
            }
            usleep(20000);
        }

        $cmd = [
            'ffmpeg',
            '-ss',
            (string)($segment === 0 ? 0 : ($segmentSize * $segment)),
            '-t',
            (string)(ag($request->getQueryParams(), 'sd', $segmentSize)),
            '-xerror',
            '-hide_banner',
            '-loglevel',
            'error',
        ];

        $tmpSubFile = null;
        $tmpVidFile = r("{path}/t-{name}-vlink.{type}", [
            'path' => sys_get_temp_dir(),
            'name' => $token,
            'type' => getExtension($path),
        ]);

        // -- video section. overlay picture based subs.
        $overlay = empty($external) && null !== $subtitle &&
            in_array(ag($this->getStream(ag($json, 'streams', []), $subtitle), 'codec_name', ''), self::OVERLAY);

        if (false === file_exists($tmpVidFile)) {
            symlink($path, $tmpVidFile);
        }

        $cmd[] = '-copyts';
        if ($isIntel) {
            $cmd[] = '-hwaccel';
            $cmd[] = 'vaapi';
            $cmd[] = '-vaapi_device';
            $cmd[] = $vaapi_device;
            if ($overlay) {
                $cmd[] = '-hwaccel_output_format';
                $cmd[] = 'vaapi';
            }
        }

        $cmd[] = '-i';
        $cmd[] = 'file:' . $tmpVidFile;

        # remove garbage metadata.
        $cmd[] = '-map_metadata';
        $cmd[] = '-1';
        $cmd[] = '-map_chapters';
        $cmd[] = '-1';

        $cmd[] = '-pix_fmt';
        $cmd[] = $isIntel ? 'vaapi_vld' : 'yuv420p';

        $cmd[] = '-g';
        $cmd[] = '52';

        if ($overlay && empty($external) && null !== $subtitle) {
            $cmd[] = '-filter_complex';
            if ($isIntel) {
                $cmd[] = "[0:0]hwdownload,format=nv12[base];[base][0:" . $subtitle . "]overlay[v];[v]hwupload[k]";
                $cmd[] = '-map';
                $cmd[] = '[k]';
            } else {
                $cmd[] = "[0:v:0][0:" . $subtitle . "]overlay[v]";
                $cmd[] = '-map';
                $cmd[] = '[v]';
            }
        } else {
            $cmd[] = '-map';
            $cmd[] = '0:v:0';
        }

        $cmd[] = '-strict';
        $cmd[] = '-2';
        if (empty($external) && $isIntel) {
            $cmd[] = '-vf';
            $cmd[] = 'format=nv12,hwupload';
        }
        $cmd[] = '-codec:v';
        $cmd[] = $vCodec;

        $cmd[] = '-crf';
        $cmd[] = $params->get('video_crf', '23');
        $cmd[] = '-preset:v';
        $cmd[] = $params->get('video_preset', 'fast');

        if (0 !== (int)$params->get('video_bitrate', 0)) {
            $cmd[] = '-b:v';
            $cmd[] = $params->get('video_bitrate', '192k');
        }

        $cmd[] = '-level';
        $cmd[] = $params->get('video_level', '4.1');
        $cmd[] = '-profile:v';
        $cmd[] = $params->get('video_profile', 'main');

        // -- audio section.
        $cmd[] = '-map';
        $cmd[] = null === $audio ? '0:a:0' : "0:{$audio}";

        $cmd[] = '-codec:a';
        $cmd[] = 'aac';

        $cmd[] = '-b:a';
        $cmd[] = $params->get('audio_bitrate', '192k');
        $cmd[] = '-ar';
        $cmd[] = $params->get('audio_sampling_rate', '22050');
        $cmd[] = '-ac';
        $cmd[] = $params->get('audio_channels', '2');

        // -- subtitles.
        if (null !== $external) {
            $tmpSubFile = r("{path}/t-{name}-slink.{type}", [
                'path' => sys_get_temp_dir(),
                'name' => $token,
                'type' => getExtension($external),
            ]);
            if (false === file_exists($tmpSubFile)) {
                symlink($external, $tmpSubFile);
            }
            $cmd[] = '-vf';
            $cmd[] = "subtitles={$tmpSubFile}" . ($isIntel ? ',format=nv12,hwupload' : '');
        } elseif (null !== $subtitle && !$overlay) {
            $subStreamIndex = (int)$subIndex[$subtitle];
            $tmpSubFile = r("{path}/t-{name}-internal-sub-{index}.{type}", [
                'path' => sys_get_temp_dir(),
                'name' => $token,
                'index' => $subStreamIndex,
                'type' => $internalSubs[$subStreamIndex]['codec_name'],
            ]);
            $streamLink = $this->extractTextSubTitle(
                $tmpVidFile,
                $internalSubs[$subStreamIndex]['codec_name'],
                $subStreamIndex,
                $tmpSubFile
            );

            $cmd[] = '-vf';
            $cmd[] = "subtitles={$streamLink}" . ($isIntel ? ',format=nv12,hwupload' : '');
        } else {
            $cmd[] = '-sn';
        }

        $cmd[] = '-muxdelay';
        $cmd[] = '0';
        $cmd[] = '-f';
        $cmd[] = 'mpegts';
        $cmd[] = 'pipe:1';

        $debug = (bool)ag($sConfig, 'debug', false);

        try {
            $start = microtime(true);
            $process = new Process($cmd);
            $process->setTimeout(60);
            $process->start();

            $lock = new Stream($tmpVidLock, 'w');
            $lock->write((string)$process->getPid());
            $lock->close();

            $process->wait();
            $end = microtime(true);

            if (!$process->isSuccessful()) {
                $this->logger->error(
                    r("Failed to generate segment. '{error}'", ['error' => $process->getErrorOutput()]), [
                        'stdout' => $process->getOutput(),
                        'stderr' => $process->getErrorOutput(),
                        'Ffmpeg' => $process->getCommandLine(),
                        'config' => $sConfig,
                        'command' => implode(' ', $cmd),
                    ]
                );

                return api_error('Failed to generate segment. check logs.', Status::INTERNAL_SERVER_ERROR, headers: [
                    'X-Transcode-Time' => round($end - $start, 6),
                ]);
            }

            $response = api_response(Status::OK, body: Stream::create($process->getOutput()), headers: [
                'Content-Type' => 'video/mpegts',
                'X-Transcode-Time' => round($end - $start, 6),
                'X-Emitter-Flush' => 1,
                'Pragma' => 'public',
                'Access-Control-Allow-Origin' => '*',
                'Cache-Control' => sprintf('public, max-age=%s', time() + 31536000),
                'Last-Modified' => sprintf('%s GMT', gmdate('D, d M Y H:i:s', time())),
                'Expires' => sprintf('%s GMT', gmdate('D, d M Y H:i:s', time() + 31536000)),
            ]);

            if (true === $debug) {
                $response = $response
                    ->withHeader('X-Ffmpeg', $process->getCommandLine())
                    ->withHeader(
                        'X-Transcode-Config',
                        json_encode($sConfig, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                    );
            }

            return $response;
        } catch (Throwable $e) {
            $this->logger->error("Failed to generate segment. '{error}' at {file}:{line}", [
                'stdout' => isset($process) ? $process->getOutput() : null,
                'stderr' => isset($process) ? $process->getErrorOutput() : null,
                'Ffmpeg' => isset($process) ? $process->getCommandLine() : null,
                'config' => $sConfig,
                'command' => implode(' ', $cmd),
                'error' => $e->getMessage(),
                'line' => $e->getLine(),
                'file' => $e->getFile(),
                'trace' => $e->getTrace(),
            ]);

            return api_error('Failed to generate segment. check logs.', Status::INTERNAL_SERVER_ERROR);
        } finally {
            if (file_exists($tmpVidLock)) {
                unlink($tmpVidLock);
            }

            if (file_exists($tmpVidFile) && is_link($tmpVidFile)) {
                unlink($tmpVidFile);
            }

            if (null !== $tmpSubFile && file_exists($tmpSubFile) && is_link($tmpSubFile)) {
                unlink($tmpSubFile);
            }
        }
    }

    /**
     * @throws InvalidArgumentException
     */
    private function extractTextSubTitle(string $path, string $type, int $stream, string $cacheFile): string
    {
        $cacheKey = md5("{$path}:" . filesize($path) . ":{$stream}");
        if (null !== ($cached = $this->cache->get($cacheKey, null))) {
            $stream = Stream::make($cacheFile, 'w');
            $stream->write($cached);
            $stream->close();
            return $cacheFile;
        }

        $cmd = [
            'ffmpeg',
            '-xerror',
            '-hide_banner',
            '-loglevel',
            'error',
            '-i',
            'file:' . $path,
            '-map',
            "0:s:{$stream}",
            '-f',
            $type,
            'pipe:1',
        ];

        try {
            $process = new Process($cmd);
            $process->setTimeout($stream ? 120 : 60);
            $process->start();
            $process->wait();

            if (!$process->isSuccessful()) {
                $this->logger->error('Failed to extract subtitle.', [
                    'stdout' => $process->getOutput(),
                    'stderr' => $process->getErrorOutput(),
                    'Ffmpeg' => $process->getCommandLine(),
                    'command' => implode(' ', $cmd),
                ]);
                return "{$path}:stream_index={$stream}";
            }

            $body = $process->getOutput();
            $this->cache->set($cacheKey, $body, new DateInterval('PT1H'));

            $stream = Stream::make($cacheFile, 'w');
            $stream->write($body);
            $stream->close();
            return $cacheFile;
        } catch (Throwable $e) {
            $this->logger->error("Failed to extract subtitles. '{error}' at {file}:{line}", [
                'stdout' => isset($process) ? $process->getOutput() : null,
                'stderr' => isset($process) ? $process->getErrorOutput() : null,
                'Ffmpeg' => isset($process) ? $process->getCommandLine() : null,
                'command' => implode(' ', $cmd),
                'error' => $e->getMessage(),
                'line' => $e->getLine(),
                'file' => $e->getFile(),
                'trace' => $e->getTrace(),
            ]);
            return "{$path}:stream_index={$stream}";
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
