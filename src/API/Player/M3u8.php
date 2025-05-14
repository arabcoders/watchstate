<?php

declare(strict_types=1);

namespace App\API\Player;

use App\Libs\Attributes\Route\Get;
use App\Libs\Config;
use App\Libs\Enums\Http\Status;
use App\Libs\Stream;
use Psr\Http\Message\ResponseInterface as iResponse;
use Psr\Http\Message\ServerRequestInterface as iRequest;
use Psr\SimpleCache\CacheInterface as iCache;
use Psr\SimpleCache\InvalidArgumentException;

final readonly class M3u8
{
    public const string URL = Index::URL . '/m3u8';

    public function __construct(private iCache $cache)
    {
    }

    /**
     * @throws InvalidArgumentException
     */
    #[Get(pattern: self::URL . '/{token}[/[{fake:.*}]]')]
    public function __invoke(iRequest $request, string $token): iResponse
    {
        if (null === ($data = $this->cache->get($token, null))) {
            return api_error('Token is expired or invalid.', Status::BAD_REQUEST);
        }

        if ($request->hasHeader('if-modified-since')) {
            return api_response(Status::NOT_MODIFIED, headers: ['Cache-Control' => 'public, max-age=25920000']);
        }

        $isSecure = (bool)Config::get('api.secure', false);
        $duration = ag($data, 'config.duration');
        $segmentSize = number_format((float)ag($data, 'config.segment_size'), 6);
        $splits = (int)ceil($duration / $segmentSize);

        $lines[] = "#EXTM3U";
        $lines[] = r("#EXT-X-TARGETDURATION:{duration}", ['duration' => $segmentSize]);
        $lines[] = "#EXT-X-VERSION:4";
        $lines[] = "#EXT-X-MEDIA-SEQUENCE:0";
        $lines[] = "#EXT-X-PLAYLIST-TYPE:VOD";

        $segmentUrl = parseConfigValue(Segments::URL);
        for ($i = 0; $i < $splits; $i++) {
            $sSize = ($i + 1) === $splits ? number_format($duration - (($i * $segmentSize)), 6) : $segmentSize;
            $lines[] = r("#EXTINF:{duration},", ['duration' => $sSize]);

            $query = [];

            if ($isSecure) {
                $query['apikey'] = Config::get('api.key');
            }

            if ($sSize !== $segmentSize) {
                $query['sd'] = $sSize;
            }

            $lines[] = r('{api_url}/{token}/{seg}.ts{query}', [
                'api_url' => $segmentUrl,
                'token' => $token,
                'seg' => (string)$i,
                'query' => !empty($query) ? '?' . http_build_query($query) : '',
            ]);
        }

        $lines[] = "#EXT-X-ENDLIST";

        return api_response(Status::OK, Stream::create(implode("\n", $lines)), [
            'Content-Type' => 'application/x-mpegurl',
            'Pragma' => 'public',
            'Cache-Control' => sprintf('public, max-age=%s', time() + 31536000),
            'Last-Modified' => sprintf('%s GMT', gmdate('D, d M Y H:i:s', time())),
            'Expires' => sprintf('%s GMT', gmdate('D, d M Y H:i:s', time() + 31536000)),
        ]);
    }
}
