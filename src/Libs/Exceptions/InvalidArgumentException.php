<?php

declare(strict_types=1);

namespace App\Libs\Exceptions;

/**
 * Class InvalidArgumentException
 */
class InvalidArgumentException extends \InvalidArgumentException implements AppExceptionInterface
{
    use UseAppException;
}
