<?php

declare(strict_types=1);

namespace Tests\API\Player;

use App\API\Player\Subtitle;
use App\Libs\Enums\Http\Status;
use App\Libs\TestCase;
use Monolog\Handler\NullHandler;
use Monolog\Logger;
use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Uri;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Psr16Cache;

class SubtitleTest extends TestCase
{
    public function test_m3u8_ass(): void
    {
        $cache = new Psr16Cache(new ArrayAdapter());
        $logger = new Logger('test', [new NullHandler()]);
        $token = 'play-test';
        $cache->set($token, [
            'path' => TESTS_PATH . '/Fixtures/local_data/test.mkv',
            'time' => 'PT24H',
            'config' => [
                'duration' => '10',
            ],
        ]);

        $request = new ServerRequest('GET', new Uri('http://localhost/v1/api/player/subtitle/' . $token . '/ass.x0.m3u8'));
        $response = (new Subtitle($cache, $logger))->m3u8($request, $token, 'ass', 'x', '0');

        $this->assertSame(Status::OK, Status::from($response->getStatusCode()));
        $this->assertStringContainsString('/' . $token . '/x0.ass', (string) $response->getBody());
    }

    public function test_convert_bad_ext(): void
    {
        $cache = new Psr16Cache(new ArrayAdapter());
        $logger = new Logger('test', [new NullHandler()]);
        $token = 'play-test';
        $cache->set($token, [
            'path' => TESTS_PATH . '/Fixtures/local_data/test.mkv',
            'time' => 'PT24H',
            'config' => [],
        ]);

        $request = new ServerRequest('GET', new Uri('http://localhost/v1/api/player/subtitle/' . $token . '/x0.txt'));
        $response = (new Subtitle($cache, $logger))->convert($request, $token, 'x', '0', 'txt');

        $this->assertSame(Status::BAD_REQUEST, Status::from($response->getStatusCode()));
    }

    public function test_m3u8_rejects_foreign_ext(): void
    {
        $this->initTempDir();
        $path = self::$tmpPath . '/sample.mp4';
        $bad = self::$tmpPath . '/secret.srt';
        file_put_contents($path, 'test');
        file_put_contents($bad, "1\n00:00:00,000 --> 00:00:01,000\nsecret\n");

        $cache = new Psr16Cache(new ArrayAdapter());
        $logger = new Logger('test', [new NullHandler()]);
        $token = 'play-test';
        $cache->set($token, [
            'path' => $path,
            'time' => 'PT24H',
            'config' => [
                'duration' => '10',
                'externals' => [
                    1 => [
                        'path' => $bad,
                    ],
                ],
            ],
        ]);

        $request = new ServerRequest('GET', new Uri('http://localhost/v1/api/player/subtitle/' . $token . '/webvtt.x1.m3u8'));
        $response = (new Subtitle($cache, $logger))->m3u8($request, $token, 'webvtt', 'x', '1');

        $this->assertSame(Status::BAD_REQUEST, Status::from($response->getStatusCode()));
    }
}
