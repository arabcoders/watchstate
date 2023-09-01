<?php

declare(strict_types=1);

namespace Tests\Libs;

use App\Libs\TestCase;

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
        $this->assertSame('bar', ag($arr, ['baz', 'sub.foo']), 'When first key is not found, second key is used');
        $this->assertNull(ag($arr, ['not_set', 'not_set_2']), 'When non-existing key is passed, null is returned');
        $this->assertSame(
            'bar',
            ag($arr, 'sub/foo', 'bar', '/'),
            'When custom delimiter is passed, it is used to split path.'
        );
        // write more tests
    }
}
