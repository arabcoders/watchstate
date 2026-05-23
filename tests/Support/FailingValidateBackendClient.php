<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Backends\Common\Context;
use App\Libs\Exceptions\Backends\InvalidContextException;

final class FailingValidateBackendClient extends FakeBackendClient
{
    public function validateContext(Context $context): bool
    {
        $ex = new InvalidContextException('Backend returned a non-JSON response from /system/Info. Response content-type was text/html.');
        $ex->setContext([
            'http' => [
                'url' => 'http://backend1.example.invalid/system/Info',
                'status_code' => 200,
            ],
            'response' => [
                'content_type' => 'text/html',
                'reason' => 'Jellyfin',
            ],
        ]);

        throw $ex;
    }
}
