<?php

declare(strict_types=1);

namespace App\Libs\Exceptions;

/**
 * Class ErrorException
 */
class ErrorException extends \ErrorException implements AppExceptionInterface
{
    use UseAppException;
}
