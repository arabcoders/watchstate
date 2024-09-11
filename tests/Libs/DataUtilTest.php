<?php

declare(strict_types=1);

namespace Tests\Libs;

use App\Libs\DataUtil;
use App\Libs\TestCase;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7Server\ServerRequestCreator;
use Psr\Http\Message\ServerRequestInterface as iRequest;

class DataUtilTest extends TestCase
{
    private array $query = ['page' => 1, 'limit' => 10];
    private array $post = [
        'foo' => 'bar',
        'baz' => 'kaz',
        'sub' => ['key' => 'val'],
        'bool' => true,
        'int' => 1,
        'float' => 1.1
    ];

    private iRequest|null $request = null;

    protected function setUp(): void
    {
        parent::setUp();
        $factory = new Psr17Factory();
        $creator = new ServerRequestCreator($factory, $factory, $factory, $factory);

        $this->request = $creator->fromArrays(
            server: [
                'REQUEST_METHOD' => 'GET',
                'SCRIPT_FILENAME' => realpath(__DIR__ . '/../../public/index.php'),
                'REMOTE_ADDR' => '127.0.0.1',
                'REQUEST_URI' => '/',
                'SERVER_NAME' => 'localhost',
                'SERVER_PORT' => 80,
                'HTTP_USER_AGENT' => 'WatchState/0.0',
            ],
            headers: [
                'Accept' => 'application/json',
                'Authorization' => 'Bearer api_test_token',
            ],
            cookie: ['test' => 'cookie'],
            get: $this->query,
            post: $this->post,
            files: [
                'file' => [
                    'name' => 'test_servers.yaml',
                    'type' => 'text/plain',
                    'size' => filesize(__DIR__ . '/../Fixtures/test_servers.yaml'),
                    'error' => UPLOAD_ERR_OK,
                    'tmp_name' => __DIR__ . '/../Fixtures/test_servers.yaml',
                ],
            ]
        );
    }

    public function test_DataUtil_fromArray(): void
    {
        $this->assertSame($this->query, DataUtil::fromArray($this->query)->getAll(), 'fromArray() returns all data');
        $this->assertSame($this->post, DataUtil::fromArray($this->post)->getAll(), 'fromArray() returns all data');
    }

    public function test_DataUtil_fromRequest(): void
    {
        $this->assertSame(
            $this->post,
            DataUtil::fromRequest($this->request)->getAll(),
            'fromRequest() returns all data post data, Default is without query params.'
        );

        $this->assertSame(
            $this->post,
            DataUtil::fromRequest($this->request, includeQueryParams: false)->getAll(),
            'fromRequest() return only post data, without query params when includeQueryParams is explicitly set to false.'
        );

        $this->assertSame(
            array_replace_recursive($this->query, $this->post),
            DataUtil::fromRequest($this->request, includeQueryParams: true)->getAll(),
            'fromRequest() returns all data including query params when includeQueryParams is explicitly set to true.'
        );
    }

    public function test_DataUtil_get(): void
    {
        $obj = DataUtil::fromRequest($this->request, includeQueryParams: true);

        $this->assertSame($this->query['page'], $obj->get('page'), 'get() returns the value of the key if it exists.');
        $this->assertSame(
            $this->query['limit'],
            $obj->get('limit'),
            'get() returns the value of the key if it exists.'
        );
        $this->assertNull($obj->get('not_set'), 'get() returns null if the key does not exist.');
        $this->assertSame(
            'default',
            $obj->get('not_set', 'default'),
            'get() returns the default value if the key does not exist.'
        );
        $this->assertIsArray($obj->get('sub'), 'get() returns an array if the key is an array.');
        $this->assertIsBool($obj->get('bool'), 'get() returns a boolean if the key is a boolean.');
        $this->assertIsInt($obj->get('int'), 'get() returns an integer if the key is an integer.');
        $this->assertIsFloat($obj->get('float'), 'get() returns a float if the key is a float.');
        $this->assertIsString($obj->get('foo'), 'get() returns a string if the key is a string.');
        $this->assertSame(
            ag($this->post, 'sub.key'),
            $obj->get('sub.key'),
            'get() returns the value of the key if it exists.'
        );
    }

    public function test_dataUtil_has()
    {
        $obj = DataUtil::fromRequest($this->request, includeQueryParams: true);
        $this->assertTrue($obj->has('page'), 'has() returns true if the key exists.');
        $this->assertTrue($obj->has('limit'), 'has() returns true if the key exists.');
        $this->assertFalse($obj->has('not_set'), 'has() returns false if the key does not exist.');
        $this->assertTrue($obj->has('sub'), 'has() returns true if the key is an array.');
        $this->assertTrue($obj->has('sub.key'), 'has() returns true if the sub.key exists.');
    }

    public function test_dataUtil_map()
    {
        $obj = DataUtil::fromRequest($this->request, includeQueryParams: true);
        $callback = fn($value) => is_string($value) ? strtoupper($value) : $value;
        $data = array_replace_recursive($this->query, $this->post);
        $this->assertSame(
            array_map($callback, $data),
            $obj->map($callback)->getAll(),
            'map() returns the array with the callback applied to each value.'
        );
    }

    public function test_dataUtil_filter()
    {
        $data = array_replace_recursive($this->query, $this->post);
        $obj = DataUtil::fromRequest($this->request, includeQueryParams: true);
        $callback = fn($value, $key) => is_string($value) && $key === 'foo';
        $this->assertSame(
            array_filter($data, $callback, ARRAY_FILTER_USE_BOTH),
            $obj->filter($callback)->getAll(),
            'filter() returns the array with the callback applied to each value.'
        );
    }

    public function test_dataUtil_with()
    {
        $obj = DataUtil::fromArray($this->query);
        $expected = $this->query;
        $expected['new'] = 'value';

        $this->assertSame(
            $expected,
            $obj->with('new', 'value')->getAll(),
            'with() returns a new DataUtil object with the key and value set.'
        );
    }

    public function test_dataUtil_without()
    {
        $obj = DataUtil::fromArray($this->query);

        $this->assertSame(
            ['page' => $this->query['page']],
            $obj->without('limit')->getAll(),
            'without() returns a new DataUtil object without the key.'
        );

        $this->assertSame(
            $this->query,
            $obj->without('not_set')->getAll(),
            'without() returns a new DataUtil object even if the key does not exist with same data.'
        );

        $this->assertNotSame(
            spl_object_hash($obj),
            spl_object_hash($obj->without('not_set')),
            'without() Any mutation should return a new object. and the spl_object_hash() should not be the same.'
        );

        $this->assertNotSame(
            spl_object_id($obj),
            spl_object_id($obj->without('not_set')),
            'without() Any mutation should return a new object. and the spl_object_id() should not be the same.'
        );
    }

    public function test_dataUtil_jsonSerialize()
    {
        $obj = DataUtil::fromArray($this->query);
        $this->assertSame(json_encode($this->query), json_encode($obj), 'jsonSerialize() returns the data array.');
    }

    public function test_dataUtil_toString()
    {
        $obj = DataUtil::fromArray($this->query);
        $this->assertSame(json_encode($this->query), (string)$obj, 'jsonSerialize() returns the data array.');
    }

}
