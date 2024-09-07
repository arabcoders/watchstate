<?php

declare(strict_types=1);

namespace App\Libs\Exceptions\Backends;

use App\Libs\Exceptions\AppExceptionInterface;
use App\Libs\Exceptions\UseAppException;
use ErrorException;

/**
 * Base Exception class for the backends errors.
 */
class BackendException extends ErrorException implements AppExceptionInterface
{
    use UseAppException;
}
