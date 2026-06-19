<?php

declare(strict_types=1);

namespace App\Libs\Extends;

use Monolog\Formatter\FormatterInterface;
use Monolog\Handler\StreamHandler as BaseStreamHandler;

final class JsonlStreamHandler extends BaseStreamHandler
{
    protected function getDefaultFormatter(): FormatterInterface
    {
        return new JsonlFormatter();
    }
}
