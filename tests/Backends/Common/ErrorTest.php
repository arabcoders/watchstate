<?php

declare(strict_types=1);

namespace Tests\Backends\Common;

use App\Backends\Common\Error;
use App\Backends\Common\Levels;
use App\Libs\TestCase;

class ErrorTest extends TestCase
{
    public function test_backend_error_object(): void
    {
        $context = [
            'not' => 'used',
            'foo' => 'bar',
            'arr' => [
                'foo' => 'bar'
            ],
            'obj' => (object)[
                'foo' => 'bar'
            ],
            'res' => fopen('php://memory', 'r'),
        ];

        fclose($context['res']);

        $message = 'Hello World {foo}! {arr} {obj} {res} {taz}';

        $error = new Error(
            message: $message,
            context: $context,
            level: Levels::ERROR,
            previous: null,
        );

        $this->assertTrue(
            $error->hasTags(),
            'hasTags() should return true of message contains tags.'
        );

        $this->assertFalse(
            $error->hasException(),
            'hasException() should return false if no previous exception is found.'
        );

        $this->assertEquals(
            $message,
            $error->message,
            'Assert message is returned as it is with no formatting.'
        );

        $this->assertEquals(
            'Hello World bar! array{"foo":"bar"} [object stdClass] [resource (closed)] {taz}',
            $error->format(),
            'Assert message is formatted correctly if tags are found.'
        );

        $this->assertEquals(
            $context,
            $error->context,
            'Error object should have the same context as the one passed in the constructor.'
        );

        $this->assertEquals(
            Levels::ERROR->value,
            $error->level(),
            'level() should return the string value of the enum level.'
        );

        try {
            throw new \RuntimeException('test exception');
        } catch (\RuntimeException $e) {
        }

        $error = new Error('message with no tags', previous: $e);
        $this->assertFalse(
            $error->hasTags(),
            'hasTags() should return false if no tags are found.'
        );
        $this->assertSame(
            'message with no tags',
            $error->format(),
            'format() should return the message as it is if no tags are found.'
        );

        $this->assertTrue(
            $error->hasException(),
            'hasException() should return true if previous exception is set.'
        );

        $this->assertStringContainsString(
            'message with no tags',
            $error->__toString(),
            '__toString() should return the message.'
        );
    }
}
