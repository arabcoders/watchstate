<?php

declare(strict_types=1);

namespace App\Libs;

final class Options
{
    public const DRY_RUN = 'DRY_RUN';
    public const FORCE_FULL = 'FORCE_FULL';
    public const DEEP_DEBUG = 'DEEP_DEBUG';
    public const IGNORE_DATE = 'IGNORE_DATE';
    public const EXPORT_ALLOWED_TIME_DIFF = 'EXPORT_TIME_DIFF';

    private function __construct()
    {
    }
}
