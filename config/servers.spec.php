<?php

/**
 * Last update: 2024-05-17
 *
 * servers.yaml backend spec.
 *
 * This file defines the backend spec.
 * The dot (.) means the string past the dot is sub key of the string preceding it.
 */

use App\Libs\Exceptions\ValidationException;

return [
    [
        'key' => 'name',
        'type' => 'string',
        'visible' => true,
        'description' => 'The name of the backend.',
        'immutable' => true,
    ],
    [
        'key' => 'type',
        'type' => 'string',
        'visible' => true,
        'description' => 'The type of the backend.',
        'choices' => ['plex', 'emby', 'jellyfin'],
        'immutable' => true,
    ],
    [
        'key' => 'url',
        'type' => 'string',
        'visible' => true,
        'description' => 'The URL of the backend.',
    ],
    [
        'key' => 'token',
        'type' => 'string',
        'visible' => true,
        'description' => 'The API token of the backend.',
    ],
    [
        'key' => 'uuid',
        'type' => 'string',
        'visible' => true,
        'description' => 'The unique identifier of the backend.',
    ],
    [
        'key' => 'user',
        'type' => 'string',
        'visible' => true,
        'description' => 'The user ID of the backend.',
    ],
    [
        'key' => 'export.enabled',
        'type' => 'bool',
        'visible' => true,
        'description' => 'Whether enable export function to the backend.',
    ],
    [
        'key' => 'export.lastSync',
        'type' => 'int',
        'visible' => true,
        'description' => 'The last time data was exported to the backend.',
    ],
    [
        'key' => 'import.enabled',
        'type' => 'bool',
        'visible' => true,
        'description' => 'Whether to enable import function to the backend.',
    ],
    [
        'key' => 'import.lastSync',
        'type' => 'int',
        'visible' => true,
        'description' => 'The last time data was imported from the backend.',
    ],
    [
        'key' => 'webhook.token',
        'type' => 'string',
        'visible' => true,
        'description' => 'Webhook token for the backend.',
        'deprecated' => true,
    ],
    [
        'key' => 'webhook.match.user',
        'type' => 'bool',
        'visible' => true,
        'description' => 'Whether to strictly match the user ID of the backend When receiving webhook events.',
    ],
    [
        'key' => 'webhook.match.uuid',
        'type' => 'bool',
        'visible' => true,
        'description' => 'Whether to strictly match the unique identifier of the backend When receiving webhook events.',
    ],
    [
        'key' => 'options.ignore',
        'type' => 'string',
        'visible' => true,
        'description' => 'The list of libraries ids to ignore when syncing.',
    ],
    [
        'key' => 'options.LIBRARY_SEGMENT',
        'type' => 'int',
        'visible' => true,
        'description' => 'How many items to per request.',
        'validate' => function ($value) {
            $limit = 300;
            if ((int)$value < $limit) {
                throw new ValidationException(r('The value must be greater than {limit} items.', ['limit' => $limit]));
            }
            return (int)$value;
        },
    ],
    [
        'key' => 'options.ADMIN_TOKEN',
        'type' => 'string',
        'visible' => false,
        'description' => 'Plex admin token to use to manage limited users.',
    ],
    [
        'key' => 'options.DUMP_PAYLOAD',
        'type' => 'bool',
        'visible' => false,
        'description' => 'Whether to dump the webhook payload into json file.',
    ],
    [
        'key' => 'options.DEBUG_TRACE',
        'type' => 'bool',
        'visible' => false,
        'description' => 'Whether to enable debug tracing when operations are running.',
    ],
    [
        'key' => 'options.IMPORT_METADATA_ONLY',
        'type' => 'bool',
        'visible' => false,
        'description' => 'Whether to import metadata only when syncing.',
    ],
    [
        'key' => 'options.DRY_RUN',
        'type' => 'bool',
        'visible' => false,
        'description' => 'Enable dry-run changes will not be committed in supported context.',
    ],
    [
        'key' => 'options.MAX_EPISODE_RANGE',
        'type' => 'int',
        'visible' => false,
        'description' => 'The max range a single record/episode can cover. The default is 5.'
    ],
    [
        'key' => 'options.client.timeout',
        'type' => 'int',
        'visible' => false,
        'description' => 'The http timeout per request to the backend.',
    ],
    [
        'key' => 'options.client.http_version',
        'type' => 'float',
        'visible' => false,
        'description' => 'The http version to use when making requests to the backend.',
    ],
    [
        'key' => 'options.is_limited_token',
        'type' => 'bool',
        'visible' => false,
        'description' => 'Whether the token has limited access.',
    ],
    [
        'key' => 'options.PLEX_USER_PIN',
        'type' => 'int',
        'visible' => false,
        'description' => 'Plex user PIN.',
    ],
];

