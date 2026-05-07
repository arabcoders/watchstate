<?php

declare(strict_types=1);

namespace Tests\API\Player;

use App\API\Player\Playlist;
use App\Libs\Enums\Http\Status;
use App\Libs\TestCase;
use Monolog\Handler\NullHandler;
use Monolog\Logger;
use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Uri;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Psr16Cache;

class PlaylistTest extends TestCase
{
    public function test_playlist_clears_direct_play(): void
    {
        $this->initTempDir();
        $path = self::$tmpPath . '/sample.mp4';
        file_put_contents($path, 'test');

        $cache = new Psr16Cache(new ArrayAdapter());
        $logger = new Logger('test', [new NullHandler()]);
        $token = 'play-test';

        $cache->set($token, [
            'path' => $path,
            'time' => 'PT24H',
            'config' => [
                'direct_play' => true,
            ],
        ]);

        $ffprobe = [
            'format' => [
                'duration' => '10.5',
            ],
            'streams' => [
                [
                    'codec_type' => 'video',
                    'codec_name' => 'h264',
                    'index' => 0,
                ],
                [
                    'codec_type' => 'audio',
                    'codec_name' => 'aac',
                    'index' => 1,
                    'disposition' => [
                        'default' => true,
                    ],
                ],
            ],
        ];

        $cache->set(md5($path . filesize($path)), $ffprobe);

        $request = new ServerRequest('GET', new Uri('http://localhost/v1/api/player/playlist/' . $token));
        $response = (new Playlist($cache, $logger))($request, $token);

        self::assertSame(Status::OK, Status::from($response->getStatusCode()));

        $updated = $cache->get($token);
        self::assertIsArray($updated);
        self::assertFalse(ag_exists($updated, 'config.direct_play'));
        self::assertSame('10.5', ag($updated, 'config.duration'));
        self::assertSame('6.000000', ag($updated, 'config.segment_size'));
    }

    public function test_playlist_sanitizes_exts(): void
    {
        $this->initTempDir();
        $path = self::$tmpPath . '/sample.mp4';
        $side = self::$tmpPath . '/sample.en.srt';
        $bad = self::$tmpPath . '/secret.srt';
        file_put_contents($path, 'test');
        file_put_contents($side, "1\n00:00:00,000 --> 00:00:01,000\nhello\n");
        file_put_contents($bad, "1\n00:00:00,000 --> 00:00:01,000\nsecret\n");

        $cache = new Psr16Cache(new ArrayAdapter());
        $logger = new Logger('test', [new NullHandler()]);
        $token = 'play-test';

        $cache->set($token, [
            'path' => $path,
            'time' => 'PT24H',
            'config' => [
                'browser_subtitles' => false,
                'externals' => [
                    9 => ['path' => $bad],
                ],
            ],
        ]);

        $ffprobe = [
            'format' => [
                'duration' => '10.5',
            ],
            'streams' => [
                [
                    'codec_type' => 'video',
                    'codec_name' => 'h264',
                    'index' => 0,
                ],
                [
                    'codec_type' => 'audio',
                    'codec_name' => 'aac',
                    'index' => 1,
                    'disposition' => [
                        'default' => true,
                    ],
                ],
            ],
        ];

        $cache->set(md5($path . filesize($path)), $ffprobe);

        $request = new ServerRequest('GET', new Uri('http://localhost/v1/api/player/playlist/' . $token));
        $response = (new Playlist($cache, $logger))($request, $token);

        self::assertSame(Status::OK, Status::from($response->getStatusCode()));
        self::assertStringContainsString('/' . $token . '/webvtt.x0.m3u8', (string) $response->getBody());

        $updated = $cache->get($token);
        self::assertIsArray($updated);
        self::assertSame([$side], array_column(ag($updated, 'config.externals', []), 'path'));
    }
}
