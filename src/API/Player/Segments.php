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
    public const string URL = Index::URL . '/segments';

    private const array OVERLAY = [
        'hdmv_pgs_subtitle',
        'dvd_subtitle',
    ];

    public function __construct(
        private iCache $cache,
        private iLogger $logger,
    ) {}

    /**
     * @throws InvalidArgumentException
     */
    #[Get(pattern: self::URL . '/{token}/{segment}[.{type}]')]
    public function __invoke(iRequest $request, #[\SensitiveParameter] string $token, string $segment): iResponse
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

        $segment = (int) $segment;
        $sConfig = (array) ag($json, 'config', []);

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

        $sConfig['segment_size'] = number_format((int) $sConfig['segment_size'], 6);

        $params = DataUtil::fromArray($sConfig);

        $audio = $params->get('audio');
        $subtitle = $params->get('subtitle');
        $external = $params->get('external', null);
        $hwaccel = (bool) $params->get('hwaccel', false);
        $vaapi_device = $params->get('vaapi_device', '/dev/dri/renderD128');
        $vCodec = $params->get('video_codec', $hwaccel ? 'h264_vaapi' : 'libx264');
        $isVAAPI = $hwaccel && 'h264_vaapi' === $vCodec;
        $isQSV = $hwaccel && 'h264_qsv' === $vCodec;
        $segmentSize = number_format((int) $params->get('segment_size', Playlist::SEGMENT_DUR), 6);
        $directPlay = null === $subtitle && null === $external && $params->has('direct_play');

        if ($isVAAPI && false === file_exists($vaapi_device)) {
            return api_error(r("VAAPI device '{device}' not found.", ['device' => $vaapi_device]), Status::BAD_REQUEST);
        }

        if (null !== $external && false === file_exists($external)) {
            return api_error(r("External subtitle '{path}' not found.", ['path' => $external]), Status::NOT_FOUND);
        }

        // -- Only transcode one segment per file, Otherwise wait until it finishes.
        $tmpVidLock = r('{path}/t-{name}.lock', [
            'path' => sys_get_temp_dir(),
            'name' => $token,
            'type' => get_extension($path),
        ]);

        while (true === file_exists($tmpVidLock)) {
            $pid = (int) file_get_contents($tmpVidLock);
            if (false === file_exists("/proc/{$pid}")) {
                @unlink($tmpVidLock);
                break;
            }
            usleep(20_000);
        }

        $directPlay = $directPlay && str_ends_with($this->getStream(ag($json, 'streams', []), 0)['codec_name'], '264');

        $cmd = ['ffmpeg'];
        if (false === $directPlay) {
            $cmd[] = '-ss';
            $cmd[] = (string) ($segment === 0 ? 0 : $segmentSize * $segment);
            $cmd[] = '-t';
            $cmd[] = (string) ag($request->getQueryParams(), 'sd', $segmentSize);
        }
        $cmd[] = '-xerror';
        $cmd[] = '-hide_banner';
        $cmd[] = '-loglevel';
        $cmd[] = 'error';

        $tmpSubFile = null;
        $tmpVidFile = r('{path}/t-{name}-vlink.{type}', [
            'path' => sys_get_temp_dir(),
            'name' => $token,
            'type' => get_extension($path),
        ]);

        // -- video section. overlay picture based subs.
        $overlay =
            empty($external)
            && null !== $subtitle
            && in_array(ag($this->getStream(ag($json, 'streams', []), $subtitle), 'codec_name', ''), self::OVERLAY, true);

        if (false === file_exists($tmpVidFile)) {
            symlink($path, $tmpVidFile);
        }

        if (false === $directPlay) {
            $cmd[] = '-copyts';
        }

        if ($isQSV && false === $directPlay) {
            $cmd[] = '-hwaccel';
            $cmd[] = 'qsv';
            if ($overlay) {
                $cmd[] = '-hwaccel_output_format';
                $cmd[] = 'qsv';
            }
        }

        if ($isVAAPI && false === $directPlay) {
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

        if (true === $directPlay) {
            $cmd[] = '-ss';
            $cmd[] = (string) ($segment === 0 ? 0 : $segmentSize * $segment);
            $cmd[] = '-t';
            $cmd[] = (string) ag($request->getQueryParams(), 'sd', $segmentSize);
        }

        // remove garbage metadata.
        $cmd[] = '-map_metadata';
        $cmd[] = '-1';
        $cmd[] = '-map_chapters';
        $cmd[] = '-1';

        $cmd[] = '-pix_fmt';
        $cmd[] = $params->get('pix_fmt', 'yuv420p');

        if (true === $directPlay) {
            $cmd[] = '-force_key_frames';
            $cmd[] = 'expr:gte(t,n_forced*' . (int) $sConfig['segment_size'] . ')';
        } else {
            $cmd[] = '-g';
            $cmd[] = '52';
        }

        if ($overlay && empty($external) && null !== $subtitle) {
            $cmd[] = '-filter_complex';
            if ($isVAAPI) {
                $cmd[] = '[0:0]hwdownload,format=nv12[base];[base][0:' . $subtitle . ']overlay[v];[v]hwupload[k]';
                $cmd[] = '-map';
                $cmd[] = '[k]';
            } else {
                $cmd[] = '[0:v:0][0:' . $subtitle . ']overlay[v]';
                $cmd[] = '-map';
                $cmd[] = '[v]';
            }
        } else {
            $cmd[] = '-map';
            $cmd[] = '0:v:0';
        }

        $cmd[] = '-strict';
        $cmd[] = '-2';
        if (empty($external) && $isVAAPI && false === $directPlay) {
            $cmd[] = '-vf';
            $cmd[] = 'format=nv12,hwupload';
        }
        $cmd[] = '-codec:v';
        $cmd[] = $directPlay ? 'copy' : $vCodec;

        if (false === $directPlay) {
            $cmd[] = '-crf';
            $cmd[] = $params->get('video_crf', '23');
            $cmd[] = '-preset:v';
            $cmd[] = $params->get('video_preset', 'fast');

            if (0 !== (int) $params->get('video_bitrate', 0)) {
                $cmd[] = '-b:v';
                $cmd[] = $params->get('video_bitrate', '192k');
            }

            $cmd[] = '-level';
            $cmd[] = $params->get('video_level', '4.1');
            $cmd[] = '-profile:v';
            $cmd[] = $params->get('video_profile', 'main');
        }

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
            $tmpSubFile = r('{path}/t-{name}-slink.{type}', [
                'path' => sys_get_temp_dir(),
                'name' => $token,
                'type' => get_extension($external),
            ]);
            if (false === file_exists($tmpSubFile)) {
                symlink($external, $tmpSubFile);
            }
            $cmd[] = '-vf';
            $cmd[] = "subtitles={$tmpSubFile}" . ($isVAAPI ? ',format=nv12,hwupload' : '');
        } elseif (null !== $subtitle && !$overlay) {
            $subStreamIndex = (int) $subIndex[$subtitle];
            $tmpSubFile = r('{path}/t-{name}-internal-sub-{index}.{type}', [
                'path' => sys_get_temp_dir(),
                'name' => $token,
                'index' => $subStreamIndex,
                'type' => $internalSubs[$subStreamIndex]['codec_name'],
            ]);
            $streamLink = $this->extractTextSubTitle(
                $tmpVidFile,
                $internalSubs[$subStreamIndex]['codec_name'],
                $subStreamIndex,
                $tmpSubFile,
            );

            $cmd[] = '-vf';
            $cmd[] = "subtitles={$streamLink}" . ($isVAAPI ? ',format=nv12,hwupload' : '');
        } else {
            $cmd[] = '-sn';
        }

        if (true === $directPlay) {
            $cmd[] = '-output_ts_offset';
            $cmd[] = (string) ($segment * $segmentSize);
        }

        $cmd[] = '-muxdelay';
        $cmd[] = '0';
        $cmd[] = '-f';
        $cmd[] = 'mpegts';
        $cmd[] = 'pipe:1';
        $debug = (bool) ag($sConfig, 'debug', false);

        try {
            $start = microtime(true);
            $process = new Process($cmd);
            $process->setTimeout(60);
            $process->start();

            $lock = new Stream($tmpVidLock, 'w');
            $lock->write((string) $process->getPid());
            $lock->close();

            $process->wait();
            $end = microtime(true);

            if (!$process->isSuccessful()) {
                $this->logger->error(
                    r("Failed to generate segment. '{error}'", ['error' => $process->getErrorOutput()]),
                    [
                        'stdout' => $process->getOutput(),
                        'stderr' => $process->getErrorOutput(),
                        'Ffmpeg' => $process->getCommandLine(),
                        'config' => $sConfig,
                        'command' => $this->cmdLog($cmd),
                    ],
                );

                $response = api_error(
                    r("Failed to generate segment. '{error}'", [
                        'error' => $debug ? $process->getErrorOutput() : 'check logs.',
                    ]),
                    Status::INTERNAL_SERVER_ERROR,
                    headers: [
                        'X-Transcode-Time' => round($end - $start, 6),
                    ],
                );

                if (true === $debug) {
                    $response = $response
                        ->withHeader('X-Ffmpeg', $this->cmdLog($cmd))
                        ->withHeader(
                            'X-Transcode-Config',
                            json_encode($sConfig, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                        );
                }

                return $response;
            }

            $response = api_response(Status::OK, body: Stream::create($process->getOutput()), headers: [
                //                'Access-Control-Allow-Origin' => '*',
                'Content-Type' => 'video/mpegts',
                'X-Transcode-Time' => round($end - $start, 6),
                'X-Emitter-Flush' => 1,
            ]);

            if (true === $debug) {
                $response = $response
                    ->withHeader('X-Ffmpeg', $this->cmdLog($cmd))
                    ->withHeader(
                        'X-Transcode-Config',
                        json_encode($sConfig, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                    );
            } else {
                $response = $response
                    ->withHeader('Pragma', 'public')
                    ->withHeader('Cache-Control', sprintf('public, max-age=%s', time() + 31_536_000))
                    ->withHeader('Last-Modified', sprintf('%s GMT', gmdate('D, d M Y H:i:s', time())))
                    ->withHeader('Expires', sprintf('%s GMT', gmdate('D, d M Y H:i:s', time() + 31_536_000)));
            }

            return $response;
        } catch (Throwable $e) {
            $this->logger->error("Failed to generate segment. '{error}' at {file}:{line}", [
                'stdout' => isset($process) ? $process->getOutput() : null,
                'stderr' => isset($process) ? $process->getErrorOutput() : null,
                'Ffmpeg' => $this->cmdLog($cmd),
                'config' => $sConfig,
                'command' => implode(' ', $cmd),
                'error' => $e->getMessage(),
                'line' => $e->getLine(),
                'file' => $e->getFile(),
                'trace' => $e->getTrace(),
            ]);

            $response = api_error('Failed to generate segment. check logs.', Status::INTERNAL_SERVER_ERROR);
            if (true === $debug) {
                $response = $response
                    ->withHeader('X-Ffmpeg', $this->cmdLog($cmd))
                    ->withHeader(
                        'X-Transcode-Config',
                        json_encode($sConfig, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                    );
            }
            return $response;
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
                    'Ffmpeg' => $this->cmdLog($cmd),
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
                'Ffmpeg' => $this->cmdLog($cmd),
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
            if ((int) ag($stream, 'index') !== $index) {
                continue;
            }

            return $stream;
        }
        return [];
    }

    private function cmdLog(array $cmd): string
    {
        return implode(' ', array_map(static fn($v) => str_contains($v, ' ') ? escapeshellarg($v) : $v, $cmd));
    }
}
