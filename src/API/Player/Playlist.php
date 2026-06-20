<?php

declare(strict_types=1);

namespace App\API\Player;

use App\API\System\Sign;
use App\Libs\Attributes\Route\Get;
use App\Libs\DataUtil;
use App\Libs\Enums\Http\Status;
use App\Libs\Stream;
use DateInterval;
use Psr\Http\Message\ResponseInterface as iResponse;
use Psr\Http\Message\ServerRequestInterface as iRequest;
use Psr\Log\LoggerInterface as iLogger;
use Psr\SimpleCache\CacheInterface as iCache;
use Psr\SimpleCache\InvalidArgumentException;
use SensitiveParameter;
use Throwable;

readonly class Playlist
{
    public const string URL = Index::URL . '/playlist';
    public const float SEGMENT_DUR = 6.000;

    public function __construct(
        private iCache $cache,
        private iLogger $logger,
    ) {}

    /**
     * @throws InvalidArgumentException
     */
    #[Get(pattern: self::URL . '/{token}[/[{fake:.*}[/]]]')]
    public function __invoke(iRequest $request, #[SensitiveParameter] string $token): iResponse
    {
        if (null === ($data = $this->cache->get($token, null))) {
            return api_error('Token is expired or invalid.', Status::BAD_REQUEST);
        }

        $params = DataUtil::fromRequest($request);

        $sConfig = (array) ag($data, 'config', []);
        $subtitleMode = strtolower((string) ag($sConfig, 'subtitle_mode', ''));
        $browserSubs = (bool) ag($sConfig, 'browser_subtitles', false);
        $hasIntSub = ag_exists($sConfig, 'subtitle') && null !== ag($sConfig, 'subtitle');
        $hasExtSub = ag_exists($sConfig, 'external') && null !== ag($sConfig, 'external');
        $hasBurnedSubs = 'soft' !== $subtitleMode && ($hasIntSub || $hasExtSub);

        if (null === ($path = ag($data, 'path', null))) {
            return api_error('Path is empty.', Status::BAD_REQUEST);
        }

        $path = rawurldecode($path);

        if ($params->get('debug')) {
            $sConfig['debug'] = true;
        }

        $lc = require __DIR__ . '/../../../config/languageCodes.php';

        try {
            $ffprobe = ffprobe_file($path, $this->cache);

            if (null === ($duration = ag($ffprobe, 'format.duration'))) {
                return api_error('format.duration is empty. probably corrupted file.', Status::BAD_REQUEST);
            }

            $sConfig['duration'] = $duration;
            $sConfig['externals'] = Subs::list($path);
            $sConfig['segment_size'] = number_format((float) $params->get('sd', self::SEGMENT_DUR), 6);
            unset($sConfig['direct_play']);

            if (!ag_exists($sConfig, 'audio')) {
                foreach (ag($ffprobe, 'streams', []) as $id => $stream) {
                    if (
                        !(
                            'audio' === ag($stream, 'codec_type')
                            && true === ag(
                                $stream,
                                'disposition.default',
                                false,
                            )
                        )
                    ) {
                        continue;
                    }

                    $sConfig['audio'] = (int) $id;
                    break;
                }

                // -- if no default audio stream, pick the first audio stream.
                if (!ag_exists($sConfig, 'audio')) {
                    foreach (ag($ffprobe, 'streams', []) as $id => $stream) {
                        if ('audio' !== ag($stream, 'codec_type')) {
                            continue;
                        }

                        $sConfig['audio'] = (int) $id;
                        break;
                    }
                }
            }

            $sConfig['token'] = $token;
            $data['config'] = $sConfig;

            Sign::update($token, $data, new DateInterval(ag($data, 'time')), $this->cache);

            $lines = [];
            $lines[] = '#EXTM3U';
            $hasSoftSubs = false;
            $emitSoftSubs = false === $browserSubs && false === $hasBurnedSubs;

            $subtitleUrl = parse_config_value(Subtitle::URL);

            if ($emitSoftSubs) {
                foreach (ag($sConfig, 'externals', []) as $id => $x) {
                    $ext = get_extension(ag($x, 'path'));
                    $file = ag($x, 'path');

                    $lang = ag($x, 'language', 'und');
                    $lang = $lc['short'][$lang] ?? $lang;

                    if (isset($lc['names'][$lang])) {
                        $name = r('{name} ({type})', [
                            'name' => $lc['names'][$lang],
                            'type' => strtoupper($ext),
                        ]);
                    } else {
                        $name = basename($file);
                    }

                    $link = r('{api_url}/{token}/{type}.x{id}.m3u8', [
                        'api_url' => $subtitleUrl,
                        'id' => $id,
                        'type' => 'webvtt',
                        'token' => $token,
                    ]);

                    // -- flag lang to 2 chars
                    $k = array_filter($lc['short'], static fn($v, $k) => $v === $lang, ARRAY_FILTER_USE_BOTH);
                    if (!empty($k)) {
                        $lang = array_keys($k);
                        $lang = array_shift($lang);
                    }

                    $lines[] = r(
                        '#EXT-X-MEDIA:TYPE=SUBTITLES,GROUP-ID="subs",NAME="(x) {name}",DEFAULT=NO,AUTOSELECT=NO,FORCED=NO,LANGUAGE="{lang}",URI="{uri}"',
                        [
                            'lang' => $lang,
                            'name' => $name,
                            'uri' => $link,
                            'index' => $id,
                        ],
                    );
                    $hasSoftSubs = true;
                }

                foreach (ag($ffprobe, 'streams', []) as $id => $x) {
                    if ('subtitle' !== ag($x, 'codec_type')) {
                        continue;
                    }

                    if (false === in_array(ag($x, 'codec_name'), Subtitle::INTERNAL_NAMING, true)) {
                        continue;
                    }

                    $lang = ag($x, 'tags.language', 'und');
                    $title = ag($x, 'tags.title', 'Unknown');

                    $link = r('{api_url}/{token}/{type}.i{id}.m3u8', [
                        'api_url' => $subtitleUrl,
                        'id' => $id,
                        'type' => 'webvtt',
                        'token' => $token,
                    ]);

                    // -- flip lang to 2 chars
                    $k = array_filter($lc['short'], static fn($v, $k) => $v === $lang, ARRAY_FILTER_USE_BOTH);
                    if (!empty($k)) {
                        $lang = array_keys($k);
                        $lang = array_shift($lang);
                    }

                    $lines[] = r(
                        '#EXT-X-MEDIA:TYPE=SUBTITLES,GROUP-ID="subs",NAME="(i) {name} ({codec})",DEFAULT=NO,AUTOSELECT=NO,FORCED=NO,LANGUAGE="{lang}",URI="{uri}"',
                        [
                            'lang' => $lang,
                            'name' => $title,
                            'codec' => ag($x, 'codec_name'),
                            'uri' => $link,
                            'index' => $id,
                        ],
                    );
                    $hasSoftSubs = true;
                }
            }

            $lines[] = r('#EXT-X-STREAM-INF:PROGRAM-ID=1{subs}', [
                'subs' => true === $hasSoftSubs ? ',SUBTITLES="subs"' : '',
            ]);

            $lines[] = r('{api_url}/{token}/segments.m3u8', [
                'token' => $token,
                'api_url' => parse_config_value(M3u8::URL),
            ]);

            return api_response(Status::OK, Stream::create(implode("\n", $lines)), [
                'Content-Type' => 'application/x-mpegurl',
                'Cache-Control' => 'no-cache',
                'Access-Control-Max-Age' => 300,
            ]);
        } catch (Throwable $e) {
            $this->logger->error(
                'Failed to generate playlist. {exception.message}',
                [
                    'operation' => 'player.playlist',
                    ...exception_log($e),
                ],
            );
            return api_error($e->getMessage(), Status::INTERNAL_SERVER_ERROR);
        }
    }
}
