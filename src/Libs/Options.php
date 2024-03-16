<?php

declare(strict_types=1);

namespace App\Libs;

/**
 * Options class represents the list of options that can be passed to the different classes.
 */
final class Options
{
    public const DRY_RUN = 'DRY_RUN';
    public const NO_CACHE = 'NO_CACHE';
    public const CACHE_TTL = 'CACHE_TTL';
    public const FORCE_FULL = 'FORCE_FULL';
    public const DEBUG_TRACE = 'DEBUG_TRACE';
    public const IGNORE_DATE = 'IGNORE_DATE';
    public const EXPORT_ALLOWED_TIME_DIFF = 'EXPORT_TIME_DIFF';
    public const RAW_RESPONSE = 'SHOW_RAW_RESPONSE';
    public const MAPPER_ALWAYS_UPDATE_META = 'ALWAYS_UPDATE_META';
    public const MAPPER_DISABLE_AUTOCOMMIT = 'DISABLE_AUTOCOMMIT';
    public const IMPORT_METADATA_ONLY = 'IMPORT_METADATA_ONLY';
    public const MISMATCH_DEEP_SCAN = 'MISMATCH_DEEP_SCAN';
    public const DISABLE_GUID = 'DISABLE_GUID';
    public const LIBRARY_SEGMENT = 'LIBRARY_SEGMENT';
    public const STATE_UPDATE_EVENT = 'STATE_UPDATE_EVENT';
    public const DUMP_PAYLOAD = 'DUMP_PAYLOAD';
    public const ADMIN_TOKEN = 'ADMIN_TOKEN';
    public const NO_THROW = 'NO_THROW';
    public const NO_LOGGING = 'NO_LOGGING';
    public const MAX_EPISODE_RANGE = 'MAX_EPISODE_RANGE';
    public const NO_PROGRESS_UPDATE = 'NO_PROGRESS_UPDATE';

    private function __construct()
    {
    }
}
