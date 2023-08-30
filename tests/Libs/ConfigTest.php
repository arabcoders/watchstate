<?php

declare(strict_types=1);

namespace Tests\Libs;

use App\Libs\Config;
use PHPUnit\Framework\TestCase;

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

        $this->assertSame($this->data, Config::getAll());
    }

    public function test_config_get(): void
    {
        $this->assertSame($this->data['foo'], Config::get('foo'));
    }

    public function test_get_config_value_default(): void
    {
        $this->assertSame('not_set', Config::get('key_not_set', 'not_set'));
    }

    public function test_config_append(): void
    {
        $data = $this->data;
        $data['taz'] = 'maz';

        Config::append($data);

        $this->assertSame($data, Config::getAll());
    }

    public function test_config_save(): void
    {
        Config::save('sub.key', 'updated');
        Config::save('foo', 'updated');

        $this->assertSame('updated', Config::get('foo'));
        $this->assertSame('updated', Config::get('sub.key'));
    }

    public function test_config_has(): void
    {
        $this->assertTrue(Config::has('foo'));
        $this->assertFalse(Config::has('taz'));
    }

    public function test_config_delete(): void
    {
        Config::remove('sub');
        $data = $this->data;
        unset($data['sub']);

        $this->assertSame($data, Config::getAll());
        $this->assertFalse(Config::has('sub'));
    }

}
