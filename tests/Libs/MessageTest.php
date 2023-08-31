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
        $this->assertSame('foo', Message::get('tester'));
    }

    public function test_message_get_conditions(): void
    {
        Message::reset();
        Message::add('tester', 'foo');
        $this->assertSame('foo', Message::get('tester'));
        $this->assertSame('not_set', Message::get('non_set', 'not_set'));
        $this->assertSame(null, Message::get('non_set'));
    }

    public function test_message_getAll(): void
    {
        Message::reset();
        $this->assertSame([], Message::getAll());
        Message::add('tester', 'foo');
        $this->assertSame(['tester' => 'foo'], Message::getAll());
    }

    public function test_message_increment(): void
    {
        Message::reset();
        Message::increment('up', 2);
        $this->assertSame(2, Message::get('up'));
        Message::increment('up');
        $this->assertSame(3, Message::get('up'));
    }

    public function test_message_reset(): void
    {
        Message::reset();
        Message::increment('up.foo');
        $this->assertSame(['up' => ['foo' => 1]], Message::getAll());
        Message::reset();
        $this->assertSame([], Message::getAll());
    }

}
