<?php

declare(strict_types=1);

namespace App\Libs\Exceptions;

class HttpException extends RuntimeException implements AppExceptionInterface
{
    use UseAppException;
}
