<?php

declare(strict_types=1);

namespace App\Libs;

final class Options
{
    public const DRY_RUN = 'DRY_RUN';
    public const NO_CACHE = 'no_cache';
    public const CACHE_TTL = 'cache_ttl';
    public const FORCE_FULL = 'FORCE_FULL';
    public const DEBUG_TRACE = 'DEBUG_TRACE';
    public const IGNORE_DATE = 'IGNORE_DATE';
    public const EXPORT_ALLOWED_TIME_DIFF = 'EXPORT_TIME_DIFF';
    public const RAW_RESPONSE = 'SHOW_RAW_RESPONSE';
    public const MAPPER_DISABLE_AUTOCOMMIT = 'DISABLE_AUTOCOMMIT';
    public const IMPORT_METADATA_ONLY = 'IMPORT_METADATA_ONLY';
    public const MISMATCH_DEEP_SCAN = 'MISMATCH_DEEP_SCAN';
    public const DISABLE_GUID = 'DISABLE_GUID';

    private function __construct()
    {
    }
}
