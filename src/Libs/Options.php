<?php

declare(strict_types=1);

namespace App\Libs;

/**
 * Options class represents the list of options that can be passed to the different classes.
 */
final class Options
{
    public const string DRY_RUN = 'DRY_RUN';
    public const string NO_CACHE = 'NO_CACHE';
    public const string CACHE_TTL = 'CACHE_TTL';
    public const string FORCE_FULL = 'FORCE_FULL';
    public const string DEBUG_TRACE = 'DEBUG_TRACE';
    public const string IGNORE_DATE = 'IGNORE_DATE';
    public const string EXPORT_ALLOWED_TIME_DIFF = 'EXPORT_TIME_DIFF';
    public const string RAW_RESPONSE = 'SHOW_RAW_RESPONSE';
    public const string MAPPER_ALWAYS_UPDATE_META = 'ALWAYS_UPDATE_META';
    public const string MAPPER_DISABLE_AUTOCOMMIT = 'DISABLE_AUTOCOMMIT';
    public const string IMPORT_METADATA_ONLY = 'IMPORT_METADATA_ONLY';
    public const string MISMATCH_DEEP_SCAN = 'MISMATCH_DEEP_SCAN';
    public const string DISABLE_GUID = 'DISABLE_GUID';
    public const string LIBRARY_SEGMENT = 'LIBRARY_SEGMENT';
    public const string STATE_UPDATE_EVENT = 'STATE_UPDATE_EVENT';
    public const string DUMP_PAYLOAD = 'DUMP_PAYLOAD';
    public const string ADMIN_TOKEN = 'ADMIN_TOKEN';
    public const string PLEX_USER_UUID = 'plex_user_uuid';
    public const string NO_THROW = 'NO_THROW';
    public const string NO_LOGGING = 'NO_LOGGING';
    public const string MAX_EPISODE_RANGE = 'MAX_EPISODE_RANGE';
    public const string IGNORE = 'ignore';

    private function __construct()
    {
    }
}
