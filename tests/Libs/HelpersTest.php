<?php

declare(strict_types=1);

namespace Tests\Libs;

use App\Backends\Plex\PlexClient;
use App\Libs\Config;
use App\Libs\Container;
use App\Libs\Database\DBLayer;
use App\Libs\Entity\StateEntity;
use App\Libs\Enums\Http\Method;
use App\Libs\Enums\Http\Status;
use App\Libs\Exceptions\AppExceptionInterface;
use App\Libs\Exceptions\DBLayerException;
use App\Libs\Exceptions\InvalidArgumentException;
use App\Libs\Exceptions\RuntimeException;
use App\Libs\Extends\MockHttpClient;
use App\Libs\Extends\ReflectionContainer;
use App\Libs\TestCase;
use JsonMachine\Items;
use JsonMachine\JsonDecoder\ErrorWrappingDecoder;
use JsonMachine\JsonDecoder\ExtJsonDecoder;
use JsonSerializable;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Stream;
use Nyholm\Psr7Server\ServerRequestCreator;
use Psr\SimpleCache\CacheInterface;
use Stringable;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Yaml\Yaml;
use TypeError;

class HelpersTest extends TestCase
{
    protected CacheInterface|null $cache = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cache = new class implements CacheInterface {
            public array $cache = [];
            public bool $throw = false;

            public function get(string $key, mixed $default = null): mixed
            {
                return $this->cache[$key] ?? $default;
            }

            public function set(string $key, mixed $value, \DateInterval|int|null $ttl = null): bool
            {
                if ($this->throw) {
                    throw new class() extends \InvalidArgumentException implements
                        \Psr\SimpleCache\InvalidArgumentException {
                    };
                }

                $this->cache[$key] = $value;
                return true;
            }

            public function delete(string $key): bool
            {
                unset($this->cache[$key]);
                return true;
            }

            public function clear(): bool
            {
                $this->cache = [];
                return true;
            }

            public function getMultiple(iterable $keys, mixed $default = null): iterable
            {
                foreach ($keys as $key) {
                    yield $key => $this->get($key, $default);
                }
            }

            public function setMultiple(iterable $values, \DateInterval|int|null $ttl = null): bool
            {
                foreach ($values as $key => $value) {
                    $this->set($key, $value, $ttl);
                }
                return true;
            }

            public function deleteMultiple(iterable $keys): bool
            {
                foreach ($keys as $key) {
                    $this->delete($key);
                }
                return true;
            }

            public function has(string $key): bool
            {
                return isset($this->cache[$key]);
            }

            public function reset(): void
            {
                $this->cache = [];
            }
        };
    }

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

        $exception = null;
        try {
            ag_set(['foo' => 'bar'], 'foo.bar.taz', 'baz');
        } catch (\Throwable $e) {
            $exception = $e;
        } finally {
            $this->assertSame(
                TypeError::class,
                $exception ? $exception::class : null,
                'When trying to set value to non-array, exception is thrown.'
            );
        }

        $exception = null;
        try {
            ag_set(['foo' => ['bar' => ['taz' => 'tt']]], 'foo.bar.taz.tt', 'baz');
        } catch (\Throwable $e) {
            $exception = $e;
        } finally {
            $this->assertSame(
                RuntimeException::class,
                $exception ? $exception::class : null,
                'When trying to set value to existing key, exception is thrown.'
            );
        }
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
        $this->assertSame(
            [0 => 'foo', 1 => 'bar'],
            ag_delete([0 => 'foo', 1 => 'bar', 2 => 'taz'], 2),
            'When an int key is passed, and it exists, it is deleted, and copy of the modified array is returned'
        );

        $this->assertSame(
            $arr,
            ag_delete($arr, 121),
            'When an int key is passed, and it does not exist, original array is returned'
        );

        $this->assertSame(
            $arr,
            ag_delete($arr, 'test.bar'),
            'When a non-existing key is passed, original array is returned.'
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
        $request = new ServerRequestCreator($factory, $factory, $factory, $factory)
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

        $request = new ServerRequestCreator($factory, $factory, $factory, $factory)
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
        $fromFile = new ServerRequestCreator($factory2, $factory2, $factory2, $factory2)
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
        api_response(200, $data);
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
        $response = api_error('error message', Status::BAD_REQUEST, headers: [
            'X-Test-Header' => 'test',
        ]);
        $this->assertSame(Status::BAD_REQUEST->value, $response->getStatusCode());
        $this->assertSame('application/json', $response->getHeaderLine('Content-Type'));
        $this->assertSame(getAppVersion(), $response->getHeaderLine('X-Application-Version'));
        $this->assertSame('test', $response->getHeaderLine('X-Test-Header'));
        $this->assertSame($data, json_decode($response->getBody()->getContents(), true));

        $response = api_error('error message', Status::BAD_REQUEST, opts: [
            'callback' => fn($response) => $response->withStatus(Status::INTERNAL_SERVER_ERROR->value)
        ]);
        $this->assertSame(Status::INTERNAL_SERVER_ERROR->value, $response->getStatusCode());
    }

    public function test_api_message(): void
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

        $data = ['info' => ['code' => Status::OK->value, 'message' => 'info message']];
        $response = api_message('info message', Status::OK, headers: [
            'X-Test-Header' => 'test',
        ]);
        $this->assertSame(Status::OK->value, $response->getStatusCode());
        $this->assertSame('application/json', $response->getHeaderLine('Content-Type'));
        $this->assertSame(getAppVersion(), $response->getHeaderLine('X-Application-Version'));
        $this->assertSame('test', $response->getHeaderLine('X-Test-Header'));
        $this->assertSame($data, json_decode($response->getBody()->getContents(), true));

        $response = api_message('info message', Status::OK, opts: [
            'callback' => fn($response) => $response->withStatus(Status::INTERNAL_SERVER_ERROR->value)
        ]);

        $this->assertSame(Status::INTERNAL_SERVER_ERROR->value, $response->getStatusCode());
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

        $cl = new class implements JsonSerializable {
            public function jsonSerialize(): array
            {
                return ['foo' => 'bar'];
            }

            public function __toString(): string
            {
                return json_encode($this->jsonSerialize());
            }
        };

        $cl2 = new class implements Stringable {
            public function __toString(): string
            {
                return json_encode(['foo' => 'bar']);
            }
        };

        $cl3 = new class() {
            public string $foo = 'bar';
        };

        $this->assertSame(
            '(baz: {"foo":"bar"})',
            arrayToString(['baz' => $cl]),
            'When array contains a class that implements JsonSerializable it is converted into array string.'
        );

        $this->assertSame(
            '(baz: {"foo":"bar"}), (foo: true), (bar: false)',
            arrayToString([
                'baz' => $cl2,
                'foo' => true,
                'bar' => false,
            ]),
            "When an object that implements Stringable is passed, it's casted to string"
        );

        $this->assertSame(
            '(baz: [ (foo: bar) ])',
            arrayToString(['baz' => $cl3]),
            "When a class doesn't implement JsonSerializable or Stringable, it's converted to array. using object vars."
        );
    }

    public function test_isValidName(): void
    {
        $validNames = ['foo', '123', 'foo_bar', '1foo_bar'];
        $invalidNames = ['foo bar', 'foo-bar', 'foo/bar', 'foo?bar', 'foo*bar', 'foo_baR', 'FOOBAR'];

        foreach ($validNames as $name) {
            $this->assertTrue(isValidName($name), "When given name is '{$name}', true should be is returned.");
        }

        foreach ($invalidNames as $name) {
            $this->assertFalse(isValidName($name), "When given name is '{$name}', false should be is returned.");
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
        $key = sprintf('%s://%s:%s@%s?id=%s', 'movie', 'guid_tvdb', '1200', 'test_plex', '121');
        $keyPassed = $key . '&garbage=1';

        $this->assertSame(
            $key,
            (string)makeIgnoreId($keyPassed),
            'When ignore url is passed with garbage query string, it is removed.'
        );
    }

    public function test_isIgnoredId(): void
    {
        $key = sprintf('%s://%s:%s@%s?id=%s', 'movie', 'guid_tvdb', '1200', 'test_plex', '121');

        $userContext = $this->createUserContext();

        $this->assertTrue(
            isIgnoredId($userContext, 'test_plex', 'movie', 'guid_tvdb', '1200', '121', opts: [
                'list' => [
                    (string)makeIgnoreId($key) => makeDate(),
                ],
            ]),
            'When exact ignore url is passed, and it is found in ignore list, true is returned.'
        );

        Config::init([
            'ignore' => [
                (string)makeIgnoreId($key)->withQuery('') => makeDate(),
            ]
        ]);

        $this->assertTrue(
            isIgnoredId($userContext, 'test_plex', 'movie', 'guid_tvdb', '1200', '121', opts: [
                'list' => [
                    (string)makeIgnoreId($key)->withQuery('') => makeDate()
                ]
            ]),
            'When ignore url is passed with and ignore list has url without query string, true is returned.'
        );

        $this->assertFalse(
            isIgnoredId($userContext, 'test_plex', 'movie', 'guid_tvdb', '1201', '121', opts: [
                'list' => [
                    (string)makeIgnoreId($key)->withQuery('') => makeDate()
                ]
            ]),
            'When ignore url is passed with and ignore list does not contain the url, false is returned.'
        );

        $this->expectException(InvalidArgumentException::class);
        isIgnoredId($userContext, 'test_plex', 'not_real_type', 'guid_tvdb', '1200', '121', opts: [
            'list' => [
                (string)makeIgnoreId($key)->withQuery('') => makeDate()
            ]
        ]);
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

        $message = 'foo bar,taz';
        $context = ['obj' => ['foo' => 'bar', 'baz' => 'taz']];
        $this->assertSame(
            ['message' => $message, 'context' => $context],
            r_array($message, $context),
            'When non-existing placeholder is passed, string is returned as it is.'
        );

        $this->assertSame(
            'Time is: 2020-01-01T00:00:00+00:00',
            r('Time is: {date}', ['date' => makeDate('2020-01-01', 'UTC')]),
            'When date is passed, it is converted into string and placeholders are replaced with values.'
        );

        $this->assertSame(
            'HTTP Status: 200',
            r('HTTP Status: {status}', ['status' => Status::OK]),
            'When Int backed Enum is passed, it is converted into its value and the placeholder is replaced with it.'
        );

        $this->assertSame(
            'HTTP Method: POST',
            r('HTTP Method: {method}', ['method' => Method::POST]),
            'When String backed Enum is passed, it is converted into its value and the placeholder is replaced with it.'
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
        $request = new ServerRequestCreator($factory, $factory, $factory, $factory)
            ->fromArrays(['REQUEST_METHOD' => 'GET', 'REMOTE_ADDR' => '1.2.3.4']);

        $this->assertSame(
            '1.2.3.4',
            getClientIp($request),
            'When request is passed, it returns client ip.'
        );

        $factory = new Psr17Factory();
        $request = new ServerRequestCreator($factory, $factory, $factory, $factory)
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

    public function test_generateRoutes()
    {
        $routes = generateRoutes('cli', [CacheInterface::class => $this->cache]);

        $this->assertCount(
            2,
            $this->cache->cache,
            'It should have generated two cache buckets for http and cli routes.'
        );
        $this->assertGreaterThanOrEqual(
            1,
            count($this->cache->cache['routes_cli']),
            'It should have more than 1 route for cli routes.'
        );
        $this->assertGreaterThanOrEqual(
            1,
            count($this->cache->cache['routes_http']),
            'It should have more than 1 route for cli routes.'
        );

        $this->assertSame(
            $routes,
            $this->cache->cache['routes_cli'],
            'It should return cli routes when called with cli type.'
        );

        $this->cache->reset();

        $this->assertSame(
            generateRoutes('http', [CacheInterface::class => $this->cache]),
            $this->cache->cache['routes_http'],
            'It should return http routes. when called with http type.'
        );

        $this->cache->reset();
        $this->cache->throw = true;
        $routes = generateRoutes('http', [CacheInterface::class => $this->cache]);
        $this->assertCount(0, $this->cache->cache, 'When cache throws exception, it should not save anything.');
        $this->assertNotSame([], $routes, 'Routes should be generated even if cache throws exception.');

        // --
        $save = Config::get('supported', []);
        Config::save('supported', ['not_set' => 'not_set_client', 'plex' => PlexClient::class,]);
        $routes = generateRoutes('http', [CacheInterface::class => $this->cache]);
        Config::save('supported', $save);
    }

    public function test_getSystemMemoryInfo()
    {
        /** @noinspection PhpUnhandledExceptionInspection */
        $none = getSystemMemoryInfo(bin2hex(random_bytes(32)));
        $this->assertIsArray($none, 'It should return array.');
        $this->assertSame([], $none, 'When mem-file is not readable, it should return empty array.');

        $info = getSystemMemoryInfo(__DIR__ . '/../Fixtures/meminfo_data.txt');
        $this->assertIsArray($info, 'It should return array of memory info.');
        $this->assertArrayHasKey('mem_total', $info, 'It should have total memory key.');
        $this->assertArrayHasKey('mem_free', $info, 'It should have free memory key.');
        $this->assertArrayHasKey('mem_available', $info, 'It should have available memory key.');
        $this->assertArrayHasKey('swap_total', $info, 'It should have swap total key.');
        $this->assertArrayHasKey('swap_free', $info, 'It should have swap free key.');

        $keysValues = [
            "mem_total" => 131598708000.0,
            "mem_free" => 10636272000.0,
            "mem_available" => 113059644000.0,
            "swap_total" => 144758584000.0,
            "swap_free" => 140512824000.0,
        ];

        foreach ($keysValues as $key => $value) {
            $this->assertSame($value, $info[$key], "It should have correct value for {$key} key.");
        }

        if (is_writeable(sys_get_temp_dir())) {
            try {
                $fileName = tempnam(sys_get_temp_dir(), 'meminfo');
                $none = getSystemMemoryInfo($fileName);
                $this->assertIsArray($none, 'It should return array.');
                $this->assertSame([], $none, 'When mem-file is empty it should return empty array.');
            } finally {
                if (file_exists($fileName)) {
                    unlink($fileName);
                }
            }
        } else {
            $this->markTestSkipped('Temp directory is not writable.');
        }
    }

    public function test_checkIgnoreRule()
    {
        Config::save('servers', ['test_backend' => []]);
        $rule = 'movie://tvdb:276923@test_backend?id=133367';
        $this->assertTrue(checkIgnoreRule($rule));

        // -- if no db source is given, it should throw exception.
        $exception = null;
        try {
            checkIgnoreRule('movie://test_backend?id=133367&garbage=1');
        } catch (\Throwable $e) {
            $exception = $e;
        } finally {
            $this->assertSame(RuntimeException::class, $exception ? $exception::class : null);
            $this->assertSame(
                'No db source was given.',
                $exception?->getMessage(),
                'When no db source is given, it should throw exception.'
            );
        }

        $exception = null;
        try {
            checkIgnoreRule('movie://foo@test_backend?id=133367&garbage=1');
        } catch (\Throwable $e) {
            $exception = $e;
        } finally {
            $this->assertSame(RuntimeException::class, $exception ? $exception::class : null);
            $this->assertStringContainsString(
                "Invalid db source name 'foo' was given.",
                $exception?->getMessage(),
                'When invalid db source is given, it should throw exception.'
            );
        }

        $exception = null;
        try {
            checkIgnoreRule('movie://tvdb@test_backend?id=133367&garbage=1');
        } catch (\Throwable $e) {
            $exception = $e;
        } finally {
            $this->assertSame(RuntimeException::class, $exception ? $exception::class : null);
            $this->assertSame(
                'No external id was given.',
                $exception?->getMessage(),
                'When no external id is given in the password part of url, it should throw exception.'
            );
        }

        $exception = null;
        try {
            checkIgnoreRule('http://tvdb:123@test_backend?id=133367&garbage=1');
        } catch (\Throwable $e) {
            $exception = $e;
        } finally {
            $this->assertSame(
                RuntimeException::class,
                $exception ? $exception::class : null,
                $exception?->getMessage() ?? ''
            );
            $this->assertStringContainsString(
                "Invalid type 'http' was given.",
                $exception?->getMessage(),
                'When invalid type is given, it should throw exception.'
            );
        }

        $exception = null;
        try {
            checkIgnoreRule('movie://tvdb:123@not_set?id=133367&garbage=1');
        } catch (\Throwable $e) {
            $exception = $e;
        } finally {
            $this->assertSame(
                RuntimeException::class,
                $exception ? $exception::class : null,
                $exception?->getMessage() ?? ''
            );
            $this->assertStringContainsString(
                "Invalid backend name 'not_set' was given.",
                $exception?->getMessage(),
                'When invalid backend name is given, it should throw exception.'
            );
        }

        $exception = null;
        try {
            checkIgnoreRule('//tvdb:123@not_set?id=133367&garbage=1');
        } catch (\Throwable $e) {
            $exception = $e;
        } finally {
            $this->assertSame(
                RuntimeException::class,
                $exception ? $exception::class : null,
                $exception?->getMessage() ?? ''
            );
            $this->assertStringContainsString(
                'No type was given.',
                $exception?->getMessage(),
                'When no type is given, it should throw exception.'
            );
        }
        $exception = null;
        try {
            checkIgnoreRule('//');
        } catch (\Throwable $e) {
            $exception = $e;
        } finally {
            $this->assertSame(
                RuntimeException::class,
                $exception ? $exception::class : null,
                $exception?->getMessage() ?? ''
            );
            $this->assertStringContainsString(
                'Invalid ignore rule was given.',
                $exception?->getMessage(),
                'When parse_url fails to parse url, it should throw exception.'
            );
        }
    }

    public function test_addCors()
    {
        $response = api_response(Status::OK, headers: ['X-Request-Id' => '1']);
        $response = addCors($response, headers: [
            'X-Test-Add' => 'test',
            'X-Request-Id' => '2',
        ], methods: ['GET', 'POST', 'PUT', 'DELETE', 'PATCH', 'OPTIONS']);

        $this->assertSame('*', $response->getHeaderLine('Access-Control-Allow-Origin'));
        $this->assertSame(
            'GET, POST, PUT, DELETE, PATCH, OPTIONS',
            $response->getHeaderLine('Access-Control-Allow-Methods')
        );
        $this->assertSame(
            'X-Application-Version, X-Request-Id, Authorization, *',
            $response->getHeaderLine('Access-Control-Allow-Headers')
        );
        $this->assertGreaterThanOrEqual(600, (int)$response->getHeaderLine('Access-Control-Max-Age'));
        $this->assertSame('test', $response->getHeaderLine('X-Test-Add'));
        $this->assertSame('1', $response->getHeaderLine('X-Request-Id'), 'The original header should not be altered.');
        $this->assertNotSame(
            '2',
            $response->getHeaderLine('X-Request-Id'),
            'AddCors: headers should not alter already set headers.'
        );
    }

    public function test_deepArrayMerge()
    {
        $array1 = [
            'foo' => 'bar',
            'baz' => 'taz',
            'kaz' => [
                'raz' => 'maz',
                'naz' => 'laz',
            ],
        ];

        $array2 = [
            'foo' => 'baz',
            'kaz' => [
                'raz' => 'baz',
                'naz' => 'baz',
            ],
        ];

        $expected = [
            'foo' => 'baz',
            'baz' => 'taz',
            'kaz' => [
                'raz' => 'baz',
                'naz' => 'baz',
            ],
        ];

        $this->assertSame($expected, deepArrayMerge([$array1, $array2]), 'It should merge arrays correctly.');
        $this->assertSame(
            [['foo' => 'baz'], ['baz' => 'taz'],],
            deepArrayMerge([[['foo' => 'bar']], [['foo' => 'baz'], ['baz' => 'taz'],]], true),
            'if preserve keys is true'
        );

        $this->assertSame(
            [['foo' => 'bar'], ['foo' => 'baz'], ['baz' => 'taz'],],
            deepArrayMerge([[['foo' => 'bar']], [['foo' => 'baz'], ['baz' => 'taz'],]], false),
            'if preserve keys is false'
        );
    }

    public function test_tryCatch()
    {
        $f = null;
        $x = tryCatch(fn() => throw new RuntimeException(), fn($e) => $e, function () use (&$f) {
            $f = 'finally_was_called';
        });

        $this->assertInstanceOf(
            RuntimeException::class,
            $x,
            'When try block is successful, it should return the value.'
        );
        $this->assertSame('finally_was_called', $f, 'finally block should be executed.');
    }

    public function test_getServerColumnSpec()
    {
        $this->assertSame(
            [
                'key' => 'user',
                'type' => 'string',
                'visible' => true,
                'description' => 'The user ID of the backend.',
            ],
            getServerColumnSpec('user'),
            'It should return correct column spec.'
        );

        $this->assertSame([], getServerColumnSpec('not_set'), 'It should return empty array when column is not set.');
    }

    public function test_getEnvSpec()
    {
        $this->assertSame(
            [
                'key' => 'WS_DATA_PATH',
                'description' => 'Where to store main data. (config, db).',
                'type' => 'string',
            ],
            getEnvSpec('WS_DATA_PATH'),
            'It should return correct env spec.'
        );

        $this->assertSame([], getEnvSpec('not_set'), 'It should return empty array when env is not set.');
    }

    public function test_isTaskWorkerRunning()
    {
        $_ENV['IN_CONTAINER'] = false;
        $d = isSchedulerRunning();
        $this->assertTrue($d['status'], 'When not in container, and $ignoreContainer is false, it should return true.');
        unset($_ENV['IN_CONTAINER']);

        $_ENV['DISABLE_CRON'] = true;
        $d = isSchedulerRunning(ignoreContainer: true);
        $this->assertFalse($d['status'], 'When DISABLE_CRON is set, it should return false.');
        unset($_ENV['DISABLE_CRON']);

        $d = isSchedulerRunning(pidFile: __DIR__ . '/../Fixtures/worker.pid', ignoreContainer: true);
        $this->assertFalse($d['status'], 'When pid file is not found, it should return false.');

        $tmpFile = tempnam(sys_get_temp_dir(), 'worker');
        try {
            file_put_contents($tmpFile, getmypid());
            $d = isSchedulerRunning(pidFile: $tmpFile, ignoreContainer: true);
            $this->assertTrue($d['status'], 'When pid file is found, and process exists it should return true.');
        } finally {
            if (file_exists($tmpFile)) {
                unlink($tmpFile);
            }
        }

        $tmpFile = tempnam(sys_get_temp_dir(), 'worker');
        try {
            /** @noinspection PhpUnhandledExceptionInspection */
            file_put_contents($tmpFile, random_int(1, 9999) . getmypid());
            $d = isSchedulerRunning(pidFile: $tmpFile, ignoreContainer: true);
            $this->assertFalse(
                $d['status'],
                'When pid file is found, and process does not exists it should return false.'
            );
        } finally {
            if (file_exists($tmpFile)) {
                unlink($tmpFile);
            }
        }
    }

    public function test_findSideCarFiles()
    {
        $n = new \SplFileInfo(__DIR__ . '/../Fixtures/local_data/test.mkv');
        $this->assertCount(
            4,
            findSideCarFiles($n),
            'It should return side car files for given file.'
        );
    }

    public function test_array_change_key_case_recursive()
    {
        $array = [
            'foo' => 'bar',
            'baz' => 'taz',
            'kaz' => [
                'raz' => 'maz',
                'naz' => 'laz',
            ],
        ];

        $expected = [
            'FOO' => 'bar',
            'BAZ' => 'taz',
            'KAZ' => [
                'RAZ' => 'maz',
                'NAZ' => 'laz',
            ],
        ];

        $this->assertSame(
            $expected,
            array_change_key_case_recursive($array, CASE_UPPER),
            'It should change keys case.'
        );

        $this->assertSame(
            $array,
            array_change_key_case_recursive($expected, CASE_LOWER),
            'It should change keys case.'
        );

        $this->expectException(RuntimeException::class);
        array_change_key_case_recursive($array, 999);
    }

    public function test_getMimeType()
    {
        $this->assertSame(
            'application/json',
            getMimeType(__DIR__ . '/../Fixtures/plex_data.json'),
            'It should return correct mime type.'
        );
    }

    public function test_getExtension()
    {
        $this->assertSame(
            'json',
            getExtension(__DIR__ . '/../Fixtures/plex_data.json'),
            'It should return correct extension.'
        );
    }

    public function test_generateUUID()
    {
        #1ef6d04c-23c3-6442-9fd5-c87f54c3d8d1
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-6[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            generateUUID(),
            'It should match valid UUID6 pattern.'
        );

        $this->assertMatchesRegularExpression(
            '/^test-[0-9a-f]{8}-[0-9a-f]{4}-6[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            generateUUID('test'),
            'It should match valid UUID6 pattern.'
        );
    }

    public function test_cacheableItem()
    {
        $reflectContainer = new class() {
            public function call(callable $callable, array $args = []): mixed
            {
                return $callable(...$args);
            }
        };

        $item = fn() => cacheableItem(
            key: 'test',
            function: fn() => 'foo',
            ignoreCache: false,
            opts: [
                CacheInterface::class => $this->cache,
                ReflectionContainer::class => $reflectContainer,
            ]
        );

        $this->assertSame('foo', $item(), 'It should return correct value.');
        $this->assertSame('foo', $item(), 'It should return correct value.');
    }

    public function test_getPagination()
    {
        $factory = new Psr17Factory();
        $creator = new ServerRequestCreator($factory, $factory, $factory, $factory);

        $request = $creator->fromArrays([
            'REQUEST_METHOD' => 'GET',
            'QUERY_STRING' => 'page=2&perpage=10'
        ], get: ['page' => 2, 'perpage' => 10]);

        [$page, $perpage, $start] = getPagination($request, 1);

        $this->assertSame(2, $page, 'It should return correct page number.');
        $this->assertSame(10, $perpage, 'It should return correct perpage number.');
        $this->assertSame(10, $start, 'It should return correct start number.');
    }

    public function test_getBackend()
    {
        Container::init();
        Config::init(require __DIR__ . '/../../config/config.php');
        foreach ((array)require __DIR__ . '/../../config/services.php' as $name => $definition) {
            Container::add($name, $definition);
        }
        Config::save('backends_file', __DIR__ . '/../Fixtures/test_servers.yaml');

        $this->assertInstanceOf(
            PlexClient::class,
            getBackend('test_plex'),
            'It should return correct backend client.'
        );

        $this->expectException(RuntimeException::class);
        getBackend('not_set');
    }

    public function test_makeBackend()
    {
        Container::init();
        Config::init(require __DIR__ . '/../../config/config.php');
        foreach ((array)require __DIR__ . '/../../config/services.php' as $name => $definition) {
            Container::add($name, $definition);
        }
        Config::save('backends_file', __DIR__ . '/../Fixtures/test_servers.yaml');

        $exception = null;
        try {
            makeBackend([], 'foo');
        } catch (\Throwable $e) {
            $exception = $e;
        } finally {
            $this->assertInstanceOf(
                InvalidArgumentException::class,
                $exception,
                'Should throw exception when no type is given.'
            );
            $this->assertStringContainsString('No backend type was set.', $exception?->getMessage());
        }

        $exception = null;
        try {
            makeBackend(['type' => 'plex'], 'foo');
        } catch (\Throwable $e) {
            $exception = $e;
        } finally {
            $this->assertInstanceOf(
                InvalidArgumentException::class,
                $exception,
                'Should throw exception when no url is given.'
            );
            $this->assertStringContainsString('No backend url was set.', $exception?->getMessage());
        }

        $exception = null;
        try {
            makeBackend(['type' => 'far', 'url' => 'http://test.example.invalid'], 'foo');
        } catch (\Throwable $e) {
            $exception = $e;
        } finally {
            $this->assertInstanceOf(
                InvalidArgumentException::class,
                $exception,
                'Should throw exception when no type is not supported.'
            );
            $this->assertStringContainsString('Unexpected client type', $exception?->getMessage());
        }

        $data = Yaml::parseFile(__DIR__ . '/../Fixtures/test_servers.yaml');

        $this->assertInstanceOf(
            PlexClient::class,
            makeBackend($data['test_plex'], 'test_plex'),
            'It should return correct backend client.'
        );
    }

    public function test_lw()
    {
        $exception = new RuntimeException();
        $exception->addContext('foo', 'bar');

        $this->assertSame(
            [AppExceptionInterface::class => ['foo' => 'bar']],
            lw('test', [], $exception)['context'],
            'it should return the added AppContext'
        );
        $this->assertSame(
            ['bar' => 'foo'],
            lw('test', ['bar' => 'foo'], new \RuntimeException())['context'],
            'If exception is not AppExceptionInterface, it should return same data.'
        );

        $exception = new DBLayerException();
        /** @noinspection SqlResolve */
        $exception->setInfo('SELECT * FROM foo WHERE id = :id', ['id' => 1], [], 122);
        /** @noinspection SqlResolve */
        $this->assertSame(
            [
                'bar' => 'foo',
                DBLayer::class => [
                    'query' => 'SELECT * FROM foo WHERE id = :id',
                    'bind' => ['id' => 1],
                    'error' => [],
                ],
            ],
            lw('test', ['bar' => 'foo'], $exception)['context'],
            'If exception is not AppExceptionInterface, it should return same data.'
        );

        $this->assertSame(
            ['bar' => 'foo'],
            lw('test', ['bar' => 'foo'])['context'],
            'If no exception is given, it should return same data.'
        );
    }

    public function test_commandContext()
    {
        $_ENV['IN_CONTAINER'] = true;
        $this->assertSame(
            'docker exec -ti watchstate console',
            trim(commandContext()),
            'It should return correct command context. When in container.'
        );
        unset($_ENV['IN_CONTAINER']);

        $_ENV['IN_CONTAINER'] = false;
        $this->assertSame(
            $_SERVER['argv'][0] ?? 'php bin/console',
            trim(commandContext()),
            'If not in container, it should return argv[0] or defaults to php bin/console.'
        );
        unset($_ENV['IN_CONTAINER']);
    }

    public function test_normalizeName()
    {
        $isValid = ['foo', 'foo_bar', '0user', 'user_123', 'user_123_foo', 'user_123_foo_bar'];
        foreach ($isValid as $name) {
            $this->assertSame(
                $name,
                normalizeName($name),
                "When valid name '{$name}' is passed, it should return same string."
            );
        }

        $this->assertSame(
            'user_123',
            normalizeName('123'),
            'When name is made entirely of numbers, it should prepend user_ to it.'
        );

        $invalidNames = [
            'foo bar',
            'foo-bar',
            'foo@baR',
        ];

        foreach ($invalidNames as $name) {
            $this->assertSame(
                'foo_bar',
                normalizeName($name),
                "When invalid name '{$name}' is passed, it should return same string with underscores."
            );
        }
    }
}
