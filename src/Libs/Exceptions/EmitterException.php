<?php

declare(strict_types=1);

namespace App\Libs\Exceptions;

use RuntimeException;

class EmitterException extends RuntimeException implements AppExceptionInterface
{
    public const int HEADERS_SENT = 500;
    public const int OUTPUT_SENT = 501;

    use UseAppException;

    public static function forHeadersSent(string $filename, int $line): self
    {
        return new self(r('Unable to emit response. Headers already sent in %s:%d', [
            'filename' => $filename,
            'line' => $line,
        ]), code: self::HEADERS_SENT);
    }

    public static function forOutputSent(): self
    {
        return new self('Output has been emitted previously. Cannot emit response.', code: self::OUTPUT_SENT);
    }
}
