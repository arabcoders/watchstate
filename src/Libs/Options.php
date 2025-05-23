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
    public const string LIBRARY_SEGMENT = 'LIBRARY_SEGMENT';
    public const string STATE_UPDATE_EVENT = 'STATE_UPDATE_EVENT';
    public const string DUMP_PAYLOAD = 'DUMP_PAYLOAD';
    public const string ADMIN_TOKEN = 'ADMIN_TOKEN';
    public const string PLEX_USER_UUID = 'plex_user_uuid';
    public const string PLEX_USER_NAME = 'plex_user_name';
    public const string PLEX_EXTERNAL_USER = 'plex_external_user';
    public const string PLEX_GUEST_USER = 'plex_guest_user';
    public const string NO_THROW = 'NO_THROW';
    public const string NO_LOGGING = 'NO_LOGGING';
    public const string MAX_EPISODE_RANGE = 'MAX_EPISODE_RANGE';
    public const string IGNORE = 'ignore';
    public const string IS_LIMITED_TOKEN = 'is_limited_token';
    public const string TO_ENTITY = 'TO_ENTITY';
    public const string NO_FALLBACK = 'NO_FALLBACK';
    public const string LIMIT_RESULTS = 'LIMIT_RESULTS';
    public const string NO_CHECK = 'NO_CHECK';
    public const string LOG_WRITER = 'LOG_WRITER';
    public const string PLEX_USER_PIN = 'PLEX_USER_PIN';
    public const string ADMIN_PLEX_USER_PIN = 'PLEX_USER_PIN';
    public const string REQUEST_ID = 'REQUEST_ID';
    public const string ONLY_LIBRARY_ID = 'ONLY_LIBRARY_ID';
    public const string ALT_NAME = 'ALT_NAME';
    public const string ALT_ID = 'ALT_ID';
    public const string CONTEXT_USER = 'CONTEXT_USER';
    public const string GET_TOKENS = 'tokens';
    public const string LOG_CONTEXT = 'LOG_CONTEXT';
    public const string DELAY_BY = 'DELAY_BY';
    public const string RAW_RESPONSE_CALLBACK = 'RAW_RESPONSE_CALLBACK';
    public const string INTERNAL_REQUEST = 'INTERNAL_REQUEST';
    public const string IS_GENERIC = 'IS_GENERIC';

    private function __construct()
    {
    }
}
