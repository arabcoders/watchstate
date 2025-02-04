<?php

declare(strict_types=1);

namespace Tests\Libs\Enums\Http;

use App\Libs\Enums\Http\Method;
use App\Libs\TestCase;

class MethodTest extends TestCase
{
    public function test_expected_methods()
    {
        $methods = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS', 'HEAD'];

        foreach ($methods as $method) {
            if (null === ($enum = Method::tryfrom($method))) {
                $this->fail("HTTP Method '{$method}' not recognized.");
            } else {
                $this->assertEquals(
                    $method,
                    $enum->value,
                    "The return value of 'Method::{$enum->name}' '{$enum->value}' is not the expected value of '{$method}'."
                );
            }
        }
    }
}
