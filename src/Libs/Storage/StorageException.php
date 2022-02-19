<?php

declare(strict_types=1);

namespace App\Libs\Storage;

use RuntimeException;

class StorageException extends RuntimeException
{
    public const SETUP_NOT_CALLED = 1;
}
