<?php

declare(strict_types=1);

namespace Tests\Libs;

use App\Libs\Message;
use App\Libs\TestCase;

class MessageTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Message::reset();
    }

    protected function tearDown(): void
    {
        Message::reset();
        parent::tearDown();
    }

    public function test_message_store_defaults(): void
    {
        Message::add('tester.foo', 'bar');
        $this->assertSame(
            'bar',
            Message::get('tester.foo'),
            'When message is added, nested lookup returns the stored value.',
        );
        $this->assertSame(
            'fallback',
            Message::get('missing', 'fallback'),
            'When key is not set, scalar defaults are returned.',
        );
        $this->assertSame(
            'computed',
            Message::get('missing', fn() => 'computed'),
            'When key is not set, closure defaults are resolved.',
        );
        $this->assertNull(Message::get('missing'), 'When key is not set, null is returned by default.');
        $this->assertSame(
            ['tester' => ['foo' => 'bar']],
            Message::getAll(),
            'Stored messages preserve their nested structure.',
        );
    }

    public function test_message_increment_reset(): void
    {
        Message::increment('up.foo', 2);
        Message::increment('up.foo');

        $this->assertSame(
            ['up' => ['foo' => 3]],
            Message::getAll(),
            'Increment accumulates values at the requested nested key.',
        );

        Message::reset();

        $this->assertSame([], Message::getAll(), 'Reset clears the message store.');
    }
}
