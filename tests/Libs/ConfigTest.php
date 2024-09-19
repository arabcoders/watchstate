<?php

declare(strict_types=1);

namespace Tests\Libs;

use App\Libs\Config;
use App\Libs\TestCase;

class ConfigTest extends TestCase
{
    private array $data = ['foo' => 'bar', 'baz' => 'kaz', 'sub' => ['key' => 'val']];

    protected function setUp(): void
    {
        Config::init($this->data);
        parent::setUp();
    }

    public function test_config_init_getAll(): void
    {
        Config::init($this->data);

        $this->assertSame(
            $this->data,
            Config::getAll(),
            'When config is initialized, getAll() returns all data'
        );
    }

    public function test_config_get(): void
    {
        $this->assertSame(
            $this->data['foo'],
            Config::get('foo'),
            'When key is set, get() returns its value'
        );
    }

    public function test_get_config_value_default(): void
    {
        $this->assertSame(
            'not_set',
            Config::get('key_not_set', 'not_set'),
            'When key is not set, default value is returned'
        );
    }

    public function test_config_append(): void
    {
        $data = $this->data;
        $data['taz'] = 'maz';

        Config::append($data);

        $this->assertSame(
            $data,
            Config::getAll(),
            'When data is appended, getAll() returns all data including appended data.'
        );
    }

    public function test_config_save(): void
    {
        Config::save('sub.key', 'updated');
        Config::save('foo', 'updated');

        $this->assertSame(
            'updated',
            Config::get('foo'),
            'When key is set via save, get() returns its value'
        );
        $this->assertSame(
            'updated',
            Config::get('sub.key'),
            'When key is set via save, get() returns its value'
        );
    }

    public function test_config_reset(): void
    {
        $this->assertCount(
            count($this->data),
            Config::getAll(),
            'When config is initialized, getAll() returns all data'
        );

        Config::reset();
        $this->assertEmpty(
            Config::getAll(),
            'When config is reset, getAll() returns empty array'
        );
    }

    public function test_config_has(): void
    {
        $this->assertTrue(Config::has('foo'), 'When key is set, has() returns true');
        $this->assertFalse(Config::has('taz'), 'When key is not set, has() returns false');
    }

    public function test_config_delete(): void
    {
        Config::remove('sub');
        $data = $this->data;
        unset($data['sub']);

        $this->assertSame(
            $data,
            Config::getAll(),
            'When key is removed, getAll() returns all data except removed data.'
        );
        $this->assertFalse(
            Config::has('sub'),
            'When key is removed, has() returns false'
        );
    }

}
