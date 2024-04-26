<?php

/**
 * Last update: 2024-04-26
 *
 * servers.yaml backend spec.
 * This file defines the backend spec.
 * The dot (.) notation means the key is subarray of the parent key.
 * the boolean at the end of the key means if the key should be visible in the config:edit/view command.
 */
return [
    'name' => true,
    'type' => true,
    'url' => true,
    'token' => true,
    'uuid' => true,
    'user' => true,
    'export.enabled' => true,
    'export.lastSync' => true,
    'import.enabled' => true,
    'import.lastSync' => true,
    'webhook.token' => true,
    'webhook.match.user' => true,
    'webhook.match.uuid' => true,
    'options.ignore' => true,
    'options.LIBRARY_SEGMENT' => true,
    'options.ADMIN_TOKEN' => false,
    'options.DUMP_PAYLOAD' => false,
    'options.DEBUG_TRACE' => false,
    'options.IMPORT_METADATA_ONLY' => false,
    'options.DRY_RUN' => false,
    'options.client.timeout' => false,
    'options.use_old_progress_endpoint' => false,
];

