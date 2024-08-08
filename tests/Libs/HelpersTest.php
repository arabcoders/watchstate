<?php

declare(strict_types=1);

namespace Tests\Libs;

use App\Libs\Config;
use App\Libs\Entity\StateEntity;
use App\Libs\Enums\Http\Status;
use App\Libs\Exceptions\InvalidArgumentException;
use App\Libs\Exceptions\RuntimeException;
use App\Libs\TestCase;
use JsonMachine\Items;
use JsonMachine\JsonDecoder\ErrorWrappingDecoder;
use JsonMachine\JsonDecoder\ExtJsonDecoder;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Stream;
use Nyholm\Psr7Server\ServerRequestCreator;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

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

        $arr = (object)[
            'foo' => 'bar',
            'sub' => [
                'foo' => 'bar',
            ],
        ];

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

        $this->expectException(RuntimeException::class);
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

        $this->expectException(RuntimeException::class);
        saveRequestPayload(request: $request);
    }

    public function test_api_response(): void
    {
        Config::append([
            'api' => [
                'response' => [
                    'headers' => [
                        'Content-Type' => 'application/json',
                        'X-Application-Version' => fn() => getAppVersion(),
                        'Access-Control-Allow-Origin' => '*',
                    ],
                ],
            ]
        ]);
        $data = ['foo' => 'bar'];
        $response = api_response(Status::OK, $data);
        $this->assertSame(Status::OK->value, $response->getStatusCode());
        $this->assertSame('application/json', $response->getHeaderLine('Content-Type'));
        $this->assertSame(getAppVersion(), $response->getHeaderLine('X-Application-Version'));
        $this->assertSame($data, json_decode($response->getBody()->getContents(), true));
    }

    public function test_error_response(): void
    {
        Config::append([
            'api' => [
                'response' => [
                    'headers' => [
                        'Content-Type' => 'application/json',
                        'X-Application-Version' => fn() => getAppVersion(),
                        'Access-Control-Allow-Origin' => '*',
                    ],
                ],
            ]
        ]);

        $data = ['error' => ['code' => Status::BAD_REQUEST->value, 'message' => 'error message']];
        $response = api_error('error message', Status::BAD_REQUEST);
        $this->assertSame(Status::BAD_REQUEST->value, $response->getStatusCode());
        $this->assertSame('application/json', $response->getHeaderLine('Content-Type'));
        $this->assertSame(getAppVersion(), $response->getHeaderLine('X-Application-Version'));
        $this->assertSame($data, json_decode($response->getBody()->getContents(), true));
    }

    public function test_httpClientChunks(): void
    {
        $resp = new MockResponse('[{"foo":0},{"foo":1},{"foo":2}]', [
            'http_code' => 200,
            'response_headers' => [
                'content-type' => 'application/json',
            ],
        ]);

        $client = new MockHttpClient($resp);
        /** @noinspection PhpUnhandledExceptionInspection */
        $response = $client->request('GET', 'http://example.com');

        /** @noinspection PhpUnhandledExceptionInspection */
        $it = Items::fromIterable(
            iterable: httpClientChunks($client->stream($response)),
            options: [
                'decoder' => new ErrorWrappingDecoder(
                    new ExtJsonDecoder(assoc: true, options: JSON_INVALID_UTF8_IGNORE)
                )
            ]
        );

        $x = 0;
        foreach ($it as $chunk) {
            $this->assertSame(['foo' => $x], $chunk);
            $x++;
        }
    }

    public function test_afterLast(): void
    {
        $this->assertSame(
            'bar',
            afterLast('foo/bar', '/'),
            'When search delimiter is found, string after last delimiter is returned.'
        );
        $this->assertSame(
            'foo/bar',
            afterLast('foo/bar', '_'),
            'When search delimiter is not found, original string is returned.'
        );
        $this->assertSame(
            'foo/bar',
            afterLast('foo/bar', ''),
            'When search delimiter is empty, original string is returned.'
        );
    }

    public function test_before(): void
    {
        $this->assertSame(
            'foo',
            before('foo/bar', '/'),
            'When search delimiter is found, string before first delimiter is returned.'
        );
        $this->assertSame(
            'foo/bar',
            before('foo/bar', '_'),
            'When search delimiter is not found, original string is returned.'
        );
        $this->assertSame(
            'foo/bar',
            before('foo/bar', ''),
            'When search delimiter is empty, original string is returned.'
        );
    }

    public function test_after(): void
    {
        $this->assertSame(
            'bar/baz',
            after('foo/bar/baz', '/'),
            'When search delimiter is found, string after first delimiter is returned.'
        );
        $this->assertSame(
            'foo/bar/baz',
            after('foo/bar/baz', '_'),
            'When search delimiter is not found, original string is returned.'
        );
        $this->assertSame(
            'foo/bar/baz',
            after('foo/bar/baz', ''),
            'When search delimiter is empty, original string is returned.'
        );
    }

    public function test_arrayToString(): void
    {
        $data = ['foo' => ['bar' => 'baz'], 'kaz' => ['taz' => 'raz']];
        $this->assertSame(
            '(foo: [ (bar: baz) ]), (kaz: [ (taz: raz) ])',
            arrayToString($data),
            'When array is passed, it is converted into array text separated by delimiter.'
        );
        $this->assertSame(
            '(foo: [ (bar: baz) ])@ (kaz: [ (taz: raz) ])',
            arrayToString($data, '@ '),
            'When array is passed, it is converted into array text separated by delimiter.'
        );
    }

    public function test_isValidName(): void
    {
        $this->assertTrue(isValidName('foo'), 'When name is valid, true is returned.');
        $this->assertTrue(isValidName('foo_bar'), 'When name is valid, true is returned.');

        $invalidNames = [
            'foo bar',
            'foo-bar',
            'foo/bar',
            'foo?bar',
            'foo*bar',
        ];

        foreach ($invalidNames as $name) {
            $this->assertFalse(
                isValidName($name),
                "When name ({$name}) is invalid, false is returned."
            );
        }
    }

    public function test_formatDuration(): void
    {
        $this->assertSame(
            '01:00:00',
            formatDuration(3600000),
            'When duration is passed, it is converted into human readable format.'
        );

        $this->assertSame(
            '01:00:00',
            formatDuration(3600000.0),
            'When float duration is passed, it is converted into human readable format.'
        );

        $this->assertSame(
            '00:30:00',
            formatDuration(3600000.0 / 2),
            'When float duration is passed, it is converted into human readable format.'
        );
    }

    public function test_array_keys_diff(): void
    {
        $base = array_flip(['foo', 'bar', 'baz', 'kaz']);
        $list = ['foo', 'bar', 'baz'];
        $this->assertSame(
            ['kaz' => 3],
            array_keys_diff($base, $list, has: false),
            'When base array is passed, and list of keys is passed, it returns array of keys that are not in list if has is false.'
        );
        $this->assertSame(
            ['foo' => 0, 'bar' => 1, 'baz' => 2],
            array_keys_diff($base, $list, has: true),
            'When base array is passed, and list of keys is passed, it returns array of keys that are in list if has is true.'
        );
    }

    public function test_makeIgnoreId(): void
    {
        $key = sprintf('%s://%s:%s@%s?id=%s', 'movie', 'guid_tvdb', '1200', 'home_plex', '121');
        $keyPassed = $key . '&garbage=1';

        $this->assertSame(
            $key,
            (string)makeIgnoreId($keyPassed),
            'When ignore url is passed with garbage query string, it is removed.'
        );
    }

    public function test_isIgnoredId(): void
    {
        $key = sprintf('%s://%s:%s@%s?id=%s', 'movie', 'guid_tvdb', '1200', 'home_plex', '121');

        Config::init([
            'ignore' => [
                (string)makeIgnoreId($key) => makeDate(),
            ]
        ]);

        $this->assertTrue(
            isIgnoredId('home_plex', 'movie', 'guid_tvdb', '1200', '121'),
            'When exact ignore url is passed, and it is found in ignore list, true is returned.'
        );

        Config::init([
            'ignore' => [
                (string)makeIgnoreId($key)->withQuery('') => makeDate(),
            ]
        ]);

        $this->assertTrue(
            isIgnoredId('home_plex', 'movie', 'guid_tvdb', '1200', '121'),
            'When ignore url is passed with and ignore list has url without query string, true is returned.'
        );

        $this->assertFalse(
            isIgnoredId('home_plex', 'movie', 'guid_tvdb', '1201', '121'),
            'When ignore url is passed with and ignore list does not contain the url, false is returned.'
        );
    }

    public function test_r(): void
    {
        $this->assertSame(
            'Hi bar',
            r('Hi {foo}', ['foo' => 'bar']),
            'When string with placeholder is passed, and array of values is passed, placeholders are replaced with values.'
        );

        $this->assertSame(
            'Hi bar',
            r('Hi {foo.bar.kaz}', ['foo' => ['bar' => ['kaz' => 'bar']]]),
            'When string with placeholder is passed, and array of values is passed, placeholders are replaced with values.'
        );

        $this->assertSame(
            'foo',
            r('foo', ['foo' => 'bar']),
            'When string passed without placeholders, it is returned as it is.'
        );

        $this->assertSame(
            'foo bar,taz',
            r('foo {obj}', ['obj' => (object)['foo' => 'bar', 'baz' => 'taz']]),
            'When object is passed, it is converted into array and placeholders are replaced with values.'
        );

        $this->assertSame(
            'foo bar,taz',
            r('foo {obj}', ['obj' => ['foo' => 'bar', 'baz' => 'taz']]),
            'When array is passed, it is converted into array and placeholders are replaced with values.'
        );

        $res = fopen('php://memory', 'r');
        $this->assertSame(
            'foo [resource]',
            r('foo {obj}', ['obj' => $res]),
            'When array is passed, it is converted into array and placeholders are replaced with values.'
        );
        fclose($res);
    }

    public function test_getClientIp(): void
    {
        $factory = new Psr17Factory();
        $request = (new ServerRequestCreator($factory, $factory, $factory, $factory))
            ->fromArrays(['REQUEST_METHOD' => 'GET', 'REMOTE_ADDR' => '1.2.3.4']);

        $this->assertSame(
            '1.2.3.4',
            getClientIp($request),
            'When request is passed, it returns client ip.'
        );

        $factory = new Psr17Factory();
        $request = (new ServerRequestCreator($factory, $factory, $factory, $factory))
            ->fromArrays([
                'REQUEST_METHOD' => 'GET',
                'REMOTE_ADDR' => '1.2.3.4',
                'HTTP_X_FORWARDED_FOR' => '4.3.2.1',
            ]);

        $this->assertSame('1.2.3.4', getClientIp($request), 'When trust proxy is disabled, it returns client ip.');

        Config::init(['trust' => ['proxy' => true]]);

        $this->assertSame(
            '4.3.2.1',
            getClientIp($request),
            'When request is passed, it returns client ip.'
        );
    }

    public function test_getClientIp_SERVER(): void
    {
        $_SERVER['REMOTE_ADDR'] = '1.2.3.4';

        $this->assertSame('1.2.3.4', getClientIp(), 'When request is passed, it returns client ip.');

        Config::init([]);

        $_SERVER['HTTP_X_FORWARDED_FOR'] = '4.3.2.1';
        $this->assertSame('1.2.3.4', getClientIp(), 'When trust proxy is disabled, it returns client ip.');

        Config::init(['trust' => ['proxy' => true]]);

        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '4.3.2.1';

        $this->assertSame('4.3.2.1', getClientIp(), 'When request is passed, it returns client ip.');

        $_SERVER['HTTP_X_FORWARDED_FOR'] = '1.2.3.4';

        $this->assertSame('1.2.3.4', getClientIp(), 'When trust proxy is enabled, and ip is the same return it');

        $_SERVER['HTTP_X_FORWARDED_FOR'] = ',4.3.2.1';
        $this->assertSame(
            '1.2.3.4',
            getClientIp(),
            'When trust proxy is enabled, And first ip is empty return real ip.'
        );

        $_SERVER['HTTP_X_FORWARDED_FOR'] = 'garbage,4.3.2.1';
        $this->assertSame(
            '1.2.3.4',
            getClientIp(),
            'When trust proxy is enabled, first ip is garbage return real ip.'
        );
    }

    public function test_inContainer()
    {
        $this->assertFalse(inContainer(), 'When not in container, false is returned.');

        $_ENV['IN_CONTAINER'] = true;
        $this->assertTrue(inContainer(), 'When in container, true is returned.');


        putenv('IN_CONTAINER=true');
        $this->assertTrue(inContainer(), 'When in container, true is returned.');
    }

    public function test_isValidURL(): void
    {
        $this->assertTrue(isValidURL('http://example.com'), 'When valid url is passed, true is returned.');
        $this->assertTrue(isValidURL('https://example.com'), 'When valid url is passed, true is returned.');
        $this->assertTrue(isValidURL('http://example.com/foo/bar'), 'When valid url is passed, true is returned.');
        $this->assertTrue(isValidURL('https://example.com/foo/bar'), 'When valid url is passed, true is returned.');
        $this->assertTrue(isValidURL('http://www.example.com'), 'When valid url is passed, true is returned.');
        $this->assertTrue(isValidURL('https://www.example.com'), 'When valid url is passed, true is returned.');
        $this->assertTrue(isValidURL('http://www.example.com/foo/bar'), 'When valid url is passed, true is returned.');
        $this->assertTrue(isValidURL('https://www.example.com/foo/bar'), 'When valid url is passed, true is returned.');
        $this->assertTrue(isValidURL('http://127.0.0.1/foo/bar'), 'When valid url is passed, true is returned.');
        $this->assertTrue(isValidURL('https://127.0.0.1/foo/bar'), 'When valid url is passed, true is returned.');
        $this->assertTrue(isValidURL('http://localhost:1337/foo/bar'), 'When valid url is passed, true is returned.');
        $this->assertTrue(isValidURL('https://localhost:1337/foo/bar'), 'When valid url is passed, true is returned.');
        $this->assertFalse(
            isValidURL('example.com/foo/bar?foo=bar&baz'),
            'When invalid url is passed, false is returned.'
        );
        $this->assertFalse(isValidURL('example.com'), 'When invalid url is passed, false is returned.');
    }

    public function test_parseEnvFile(): void
    {
        $envFile = __DIR__ . '/../Fixtures/test_env_vars';

        $parsed = parseEnvFile($envFile);
        $correctData = [
            "WS_TZ" => "Asia/Kuwait",
            "WS_CRON_IMPORT" => "1",
            "WS_CRON_EXPORT" => "0",
            "WS_CRON_IMPORT_AT" => "16 */1 * * *",
            "WS_CRON_EXPORT_AT" => "30 */3 * * *",
            "WS_CRON_PUSH_AT" => "*/10 * * * *",
        ];

        $this->assertCount(count($correctData), $parsed, 'When parsing env file, filter out garbage data.');

        foreach ($correctData as $key => $value) {
            $this->assertSame($value, $parsed[$key], 'Make sure correct values are returned when parsing env file.');
        }

        $this->expectException(InvalidArgumentException::class);
        parseEnvFile(__DIR__ . '/../Fixtures/non_existing_file');
    }

    public function test_loadEnvFile(): void
    {
        $envFile = __DIR__ . '/../Fixtures/test_env_vars';
        $correctData = [
            "WS_TZ" => "Asia/Kuwait",
            "WS_CRON_IMPORT" => "1",
            "WS_CRON_EXPORT" => "0",
            "WS_CRON_IMPORT_AT" => "16 */1 * * *",
            "WS_CRON_EXPORT_AT" => "30 */3 * * *",
            "WS_CRON_PUSH_AT" => "*/10 * * * *",
        ];

        $_ENV['WS_TZ'] = 'Asia/Kuwait';
        putenv('WS_TZ=Asia/Kuwait');

        loadEnvFile($envFile, usePutEnv: true, override: false);

        foreach ($correctData as $key => $value) {
            $this->assertSame($value, env($key), 'Make sure correct values are returned when parsing env file.');
        }

        // -- if given invalid file. it should not throw exception.
        try {
            loadEnvFile(__DIR__ . '/../Fixtures/non_existing_file');
        } catch (\Throwable) {
            $this->fail('This function shouldn\'t throw exception when invalid file is given.');
        }
    }
}
