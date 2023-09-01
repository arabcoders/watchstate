<?php

declare(strict_types=1);

namespace Tests\Libs;

use App\Libs\Entity\StateEntity;
use App\Libs\TestCase;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Stream;
use Nyholm\Psr7Server\ServerRequestCreator;

class HelpersTest extends TestCase
{
    public function test_env_conditions(): void
    {
        $values = [
            'FOO' => 'bar',
            'V_TRUE_1' => 'true',
            'V_TRUE_2' => '(true)',
            'V_FALSE_1' => 'false',
            'V_FALSE_2' => '(false)',
            'V_EMPTY_1' => 'empty',
            'V_EMPTY_2' => '(empty)',
            'V_NULL_1' => 'null',
            'V_NULL_2' => '(null)',
        ];

        foreach ($values as $key => $val) {
            putenv("{$key}={$val}");
        }

        $this->assertSame('bar', env('FOO'), 'When key is set, value is returned');
        $this->assertSame('taz', env('non_set', fn() => 'taz'), 'When key is not set, default value is returned');
        $this->assertTrue(env('V_TRUE_1'), 'When value is "true", true is returned');
        $this->assertTrue(env('V_TRUE_2'), 'When value is "(true)", true is returned');
        $this->assertFalse(env('V_FALSE_1'), 'When value is "false", false is returned');
        $this->assertFalse(env('V_FALSE_2'), 'When value is "(false)", false is returned');
        $this->assertEmpty(env('V_EMPTY_1'), 'When value is "empty", empty string is returned');
        $this->assertEmpty(env('V_EMPTY_2'), 'When value is "(empty)", empty string is returned');
        $this->assertNull(env('V_NULL_1'), 'When value is "null", null is returned');
        $this->assertNull(env('V_NULL_2'), 'When value is "(null)", null is returned');
    }

    public function test_getValue(): void
    {
        $this->assertSame('foo', getValue('foo'), 'When scalar value is passed, it is returned');
        $this->assertSame(
            'foo',
            getValue(fn() => 'foo'),
            'When callable is passed, it is called and result is returned'
        );
    }

    public function test_makeDate(): void
    {
        $this->assertSame(
            '2020-01-01',
            makeDate('2020-01-01')->format('Y-m-d'),
            'When date string is passed, parsed it into Y-m-d format'
        );
        $this->assertSame(
            '2020-01-01',
            makeDate(strtotime('2020-01-01'))->format('Y-m-d'),
            'When timestamp is passed, parsed it into Y-m-d format'
        );
        $this->assertSame(
            '2020-01-01',
            makeDate('2020-01-01 00:00:00')->format('Y-m-d'),
            'Parse datetime string, and parse it into Y-m-d format'
        );
        $this->assertSame(
            '2020-01-01 00:00:00',
            makeDate('2020-01-01 00:00:00')->format('Y-m-d H:i:s'),
            'Parse datetime string, and parse it into Y-m-d H:i:s format'
        );
        $this->assertSame(
            '2020-01-01 00:00:00',
            makeDate(
                new \DateTimeImmutable('2020-01-01 00:00:00')
            )->format('Y-m-d H:i:s'),
            'When datetime DateTimeInterface is passed, it used as it is.'
        );
    }

    public function test_ag(): void
    {
        $arr = [
            'foo' => 'bar',
            'sub' => [
                'foo' => 'bar',
            ],
        ];

        // check against array data source
        $this->assertSame('bar', ag($arr, 'foo'), 'When simple key is passed, value is returned');
        $this->assertSame('bar', ag($arr, 'sub.foo'), 'When dot notation is used, nested key is returned');
        $this->assertSame([], ag([], ''), 'When empty path is passed, source array is returned');
        $this->assertSame($arr, ag($arr, ''), 'When empty key is passed, source array is returned');
        $this->assertSame('bar', ag($arr, ['baz', 'sub.foo']), 'When first key is not found, second key is used');
        $this->assertNull(ag($arr, ['not_set', 'not_set_2']), 'When non-existing key is passed, null is returned');
        $this->assertSame(
            'bar',
            ag($arr, 'sub/foo', 'bar', '/'),
            'When custom delimiter is passed, it is used to split path.'
        );
        // check against array object source
        $arr = (object)[
            'foo' => 'bar',
            'sub' => [
                'foo' => 'bar',
            ],
        ];

        // check against array data source
        $this->assertSame('bar', ag($arr, 'foo'), 'When simple key is passed, value is returned');
        $this->assertSame('bar', ag($arr, 'sub.foo'), 'When dot notation is used, nested key is returned');
        $this->assertSame([], ag([], ''), 'When empty path is passed, source array is returned');
        $this->assertSame($arr, ag($arr, ''), 'When empty key is passed, source array is returned');
        $this->assertSame(
            'bar',
            ag($arr, ['baz', 'sub.foo']),
            'When path array is given, first matching key will be returned.'
        );
        $this->assertNull(
            ag($arr, ['not_set', 'not_set_2']),
            'When path array is given and no matching key is found, default value will be returned.'
        );
        $this->assertSame(
            'bar',
            ag($arr, 'sub/foo', 'bar', '/'),
            'When custom delimiter is passed, it is used to split path.'
        );
        // write more tests
    }

    public function test_ag_set(): void
    {
        $this->assertSame(['foo' => 'bar'],
            ag_set([], 'foo', 'bar'),
            'a simple key is passed it will be in saved in format of [key => value]'
        );

        $this->assertSame(['foo' => ['bar' => 'baz']],
            ag_set([], 'foo.bar', 'baz'),
            'When a nested key is passed, it will be saved in format of [key => [nested_key => value]]'
        );

        $this->assertSame(['foo' => ['bar' => 'baz']],
            ag_set([], 'foo/bar', 'baz', '/'),
            'When a nested key is passed with custom delimiter, it will be saved in format of [key => [nested_key => value]]'
        );

        $arr = [
            'foo' => [
                'bar' => 'baz'
            ],
        ];

        $this->assertSame(['foo' => ['bar' => 'baz', 'kaz' => 'taz']],
            ag_set($arr, 'foo.kaz', 'taz'),
            'When a nested key is passed, it will be saved in format of [key => [nested_key => value]]'
        );

        $this->assertSame(['foo' => ['kaz' => 'taz']],
            ag_set([], 'foo.kaz', 'taz'),
            'When a nested key is passed, it will be saved in format of [key => [nested_key => value]]'
        );
    }

    public function test_ag_exits(): void
    {
        $arr = [
            0 => 'taz',
            'foo' => 'bar',
            'sub' => [
                'foo' => 'bar',
            ],
        ];
        // write tests covering all cases of ag_exists
        $this->assertTrue(ag_exists($arr, 'foo'), 'When simple key is passed, and it exists, true is returned');
        $this->assertTrue(ag_exists($arr, 'sub.foo'), 'When dot notation is used, and it exists, true is returned');
        $this->assertFalse(ag_exists($arr, 'not_set'), 'When non-existing key is passed, false is returned');
        $this->assertFalse(ag_exists($arr, 'sub.not_set'), 'When non-existing nested key is passed, false is returned');
        $this->assertFalse(ag_exists([], ''), 'When empty path is passed, false is returned');
        $this->assertFalse(ag_exists($arr, ''), 'When empty key is passed, false is returned');
        $this->assertTrue(ag_exists($arr, 0), 'when numeric key is passed, and it exists, true is returned');
        $this->assertTrue(
            ag_exists($arr, 'sub/foo', '/'),
            'When custom delimiter is passed, it is used to split path.'
        );
    }

    public function test_ag_delete(): void
    {
        $arr = [
            'foo' => 'bar',
            'sub' => [
                'foo' => 'bar',
            ],
        ];

        $this->assertSame(
            ['foo' => 'bar', 'sub' => []],
            ag_delete($arr, 'sub.foo'),
            'When dot notation is used, and it exists, it is deleted, and copy of the modified array is returned'
        );

        $this->assertSame(
            ['foo' => 'bar', 'sub' => []],
            ag_delete($arr, 'sub/foo', '/'),
            'When custom delimiter is passed, it is used to split path. and it exists, it is deleted, and copy of the modified array is returned'
        );

        $this->assertSame(
            ['sub' => ['foo' => 'bar']],
            ag_delete($arr, 'foo'),
            'When simple key is passed, and it exists, it is deleted, and copy of the modified array is returned'
        );
    }

    public function test_fixPath(): void
    {
        $this->assertSame(
            '/foo' . DIRECTORY_SEPARATOR . 'bar',
            fixPath('/foo' . DIRECTORY_SEPARATOR . 'bar' . DIRECTORY_SEPARATOR),
            'When path ends with directory separator, it is removed.'
        );

        $this->assertSame(
            'foo' . DIRECTORY_SEPARATOR . 'bar',
            fixPath('foo' . DIRECTORY_SEPARATOR . 'bar'),
            'When path does not end with directory separator, it is not modified.'
        );
    }

    public function test_fsize(): void
    {
        $this->assertSame('1.00B', fsize(1), 'When size is less than 1KB, it is returned in B format');
        $this->assertSame('1.02K', fsize(1024), 'When size is less than 1MB, it is returned in KB format');
        $this->assertSame('1.05M', fsize(1024 * 1024), 'When size is less than 1GB, it is returned in MB format');
        $this->assertSame(
            '1.07G',
            fsize(1024 * 1024 * 1024),
            'When size is less than 1TB, it is returned in GB format'
        );
        $this->assertSame(
            '1.10T',
            fsize(1024 * 1024 * 1024 * 1024),
            'When size is less than 1P, it is returned in TB format'
        );
    }

    public function test_saveWebhookPayload(): void
    {
        $movieData = require __DIR__ . '/../Fixtures/MovieEntity.php';
        $entity = new StateEntity($movieData);
        $stream = new Stream(fopen('php://memory', 'w+'));
        $factory = new Psr17Factory();
        $request = (new ServerRequestCreator($factory, $factory, $factory, $factory))
            ->fromArrays(['REQUEST_METHOD' => 'GET']);
        saveWebhookPayload(entity: $entity, request: $request, file: $stream);

        $stream->rewind();
        $data = trim($stream->getContents());
        $content = json_decode($data, associative: true);
        $fromPayload = $entity::fromArray(ag($content, 'entity'));

        $this->assertSame(
            $entity->getAll(),
            $fromPayload->getAll(),
            'saveWebhookPayload() should save webhook payload into given stream if it is provided otherwise it should save it into default stream.'
        );

        $this->expectException(\Error::class);
        saveWebhookPayload(entity: $entity, request: $request);
    }

    public function test_saveRequestPayload(): void
    {
        $movieData = require __DIR__ . '/../Fixtures/MovieEntity.php';

        $stream = new Stream(fopen('php://memory', 'w+'));

        $factory = new Psr17Factory();

        $request = (new ServerRequestCreator($factory, $factory, $factory, $factory))
            ->fromArrays(server: ['REQUEST_METHOD' => 'GET']);
        $request = $request->withBody(Stream::create(json_encode($movieData)))
            ->withParsedBody($movieData)
            ->withQueryParams(['foo' => 'bar', 'baz' => 'taz'])
            ->withAttribute('foo', 'bar');

        saveRequestPayload(request: $request, file: $stream);

        $stream->rewind();
        $data = trim($stream->getContents());
        $content = json_decode($data, associative: true);
        dump($content);

        $factory2 = new Psr17Factory();
        $fromFile = (new ServerRequestCreator($factory2, $factory2, $factory2, $factory2))
            ->fromArrays(server: ag($content, 'server'), body: ag($content, 'body'));
        $fromFile = $fromFile
            ->withAttribute('foo', 'bar')
            ->withParsedBody(ag($content, 'parsed'))
            ->withQueryParams(ag($content, 'query'));

        $this->assertSame($request->getServerParams(), $fromFile->getServerParams());
        $this->assertSame($request->getQueryParams(), $fromFile->getQueryParams());
        $this->assertSame($request->getAttributes(), $fromFile->getAttributes());
        $this->assertSame($request->getParsedBody(), $fromFile->getParsedBody());

        $this->expectException(\Error::class);
        saveRequestPayload(request: $request);
    }
}
