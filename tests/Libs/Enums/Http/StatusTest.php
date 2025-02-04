<?php

declare(strict_types=1);

namespace Tests\Libs\Enums\Http;

use App\Libs\Enums\Http\Status;
use App\Libs\TestCase;

class StatusTest extends TestCase
{
    /**
     * This test checks if the important status codes are recognized. However, we do not cover the
     * entire range of status codes as it is not feasible to test all of them.
     */
    public function test_important_status_codes()
    {
        $statusCodes = [200, 201, 202, 204, 304, 400, 401, 403, 404, 405, 406, 409, 500, 503, 504, 505];

        foreach ($statusCodes as $code) {
            if (null === ($enum = Status::tryfrom($code))) {
                $this->fail("Important HTTP status code '{$code}' not recognized.");
            } else {
                $this->assertEquals(
                    $code,
                    $enum->value,
                    "The return value of 'Status::{$enum->name}' '{$enum->value}' is not the expected '{$code}' value."
                );
            }
        }
    }

    /**
     * This test checks if the status code is within the boundary of 100 to 599.
     * This test is needed to trigger an error if the status code is out of the boundary. As some parts
     * of the codebase expect the status code to be within this boundary.
     */
    public function test_status_code_boundary()
    {
        foreach (Status::cases() as $code) {
            $this->assertTrue(
                $code->value >= 100 && $code->value <= 599,
                "HTTP Status Code 'Status::{$code->name}' '{$code->value}' is out of boundary."
            );
        }
    }
}
