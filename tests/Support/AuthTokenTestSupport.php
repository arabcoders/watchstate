<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Libs\TokenUtil;

trait AuthTokenTestSupport
{
    /**
     * @param array<string,mixed> $payload
     */
    private function makeUserToken(array $payload): string
    {
        $json = json_encode($payload);
        $this->assertNotFalse($json, 'User token payload JSON encoding should succeed in tests.');

        return TokenUtil::encode(TokenUtil::sign($json) . '.' . $json);
    }
}
