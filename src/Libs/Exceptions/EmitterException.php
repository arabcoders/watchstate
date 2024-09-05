<?php

declare(strict_types=1);

namespace App\Libs\Exceptions;

use RuntimeException;

class EmitterException extends RuntimeException implements AppExceptionInterface
{
    use UseAppException;

    public static function forHeadersSent(string $filename, int $line): self
    {
        return new self(r('Unable to emit response. Headers already sent in %s:%d', [
            'filename' => $filename,
            'line' => $line,
        ]));
    }

    public static function forOutputSent(): self
    {
        return new self('Output has been emitted previously. Cannot emit response.');
    }
}
