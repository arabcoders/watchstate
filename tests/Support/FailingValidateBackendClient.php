<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Backends\Common\Context;
use App\Libs\Exceptions\Backends\InvalidContextException;
use App\Libs\Exceptions\Backends\RuntimeException;

final class FailingValidateBackendClient extends FakeBackendClient
{
    public function getUsersList(array $opts = []): array
    {
        $context = $this->getContext();
        $ex = new RuntimeException('Backend returned a non-JSON response from /system/Info. Response content-type was text/html.');
        $ex->setContext([
            'http' => [
                'url' => (string) $context->backendUrl->withPath('/system/Info'),
                'status_code' => 200,
            ],
            'response' => [
                'content_type' => 'text/html',
                'reason' => 'Jellyfin',
            ],
        ]);

        throw $ex;
    }

    public function validateContext(Context $context): bool
    {
        $ex = new InvalidContextException('Backend returned a non-JSON response from /system/Info. Response content-type was text/html.');
        $ex->setContext([
            'http' => [
                'url' => (string) $context->backendUrl->withPath('/system/Info'),
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
