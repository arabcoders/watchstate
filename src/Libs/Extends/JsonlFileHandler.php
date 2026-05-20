<?php

declare(strict_types=1);

namespace App\Libs\Extends;

use Monolog\Formatter\FormatterInterface;
use Monolog\Handler\StreamHandler;

final class JsonlFileHandler extends StreamHandler
{
    protected function getDefaultFormatter(): FormatterInterface
    {
        return new JsonlFormatter();
    }
}
