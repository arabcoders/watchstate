<?php

declare(strict_types=1);

namespace Tests\Libs;

use App\Libs\Message;
use App\Libs\TestCase;

class MessageTest extends TestCase
{
    public function test_message_add(): void
    {
        Message::reset();
        Message::add('tester', 'foo');
        $this->assertSame(
            'foo',
            Message::get('tester'),
            'When message is added, it can be retrieved by key'
        );
    }

    public function test_message_get_conditions(): void
    {
        Message::reset();
        Message::add('tester', 'foo');
        $this->assertSame(
            'foo',
            Message::get('tester'),
            'When key is set, value is returned'
        );
        $this->assertSame(
            'not_set',
            Message::get('non_set', 'not_set'),
            'When key is not set, default value is returned'
        );
        $this->assertSame(
            'not_set',
            Message::get('non_set', fn() => 'not_set'),
            'When key is not set, and default value is closure, it is called and result is returned'
        );
        $this->assertSame(
            null,
            Message::get('non_set'),
            'When key is not set, null is returned'
        );
    }

    public function test_message_getAll(): void
    {
        Message::reset();
        $this->assertSame([],
            Message::getAll(),
            'When no message is set, getAll() returns empty array'
        );
        Message::add('tester', 'foo');
        $this->assertSame(['tester' => 'foo'],
            Message::getAll(),
            'When message is set, getAll() returns all messages'
        );
    }

    public function test_message_increment(): void
    {
        Message::reset();
        Message::increment('up', 2);
        $this->assertSame(
            2,
            Message::get('up'),
            'When message is incremented using custom increment value, the incremented value returned by same key'
        );
        Message::increment('up');
        $this->assertSame(
            3,
            Message::get('up'),
            'When message is incremented using default increment value, the incremented value returned by same key'
        );
    }

    public function test_message_reset(): void
    {
        Message::reset();
        Message::increment('up.foo');
        $this->assertSame(['up' => ['foo' => 1]],
            Message::getAll(),
            'When message is incremented, it is stored in store');
        Message::reset();
        $this->assertSame([],
            Message::getAll(),
            'When message is reset, store is empty');
    }

}
