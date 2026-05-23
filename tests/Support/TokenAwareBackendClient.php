<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Backends\Common\Context;
use App\Libs\Exceptions\Backends\InvalidContextException;

final class TokenAwareBackendClient extends FakeBackendClient
{
    public function validateContext(Context $context): bool
    {
        if ('good-token' === $context->backendToken) {
            return true;
        }

        throw new InvalidContextException('Backend responded with 401. Most likely means token is invalid.');
    }
}
