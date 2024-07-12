<?php

declare(strict_types=1);

use App\Libs\Entity\StateInterface as iState;
use App\Libs\Guid;

return [
    iState::COLUMN_ID => null,
    iState::COLUMN_TYPE => iState::TYPE_EPISODE,
    iState::COLUMN_UPDATED => 1,
    iState::COLUMN_WATCHED => 1,
    iState::COLUMN_VIA => 'home_plex',
    iState::COLUMN_TITLE => 'Series Title',
    iState::COLUMN_YEAR => 2020,
    iState::COLUMN_SEASON => 1,
    iState::COLUMN_EPISODE => 2,
    iState::COLUMN_PARENT => [
        Guid::GUID_IMDB => 'tt510',
        Guid::GUID_TVDB => '520',
    ],
    iState::COLUMN_GUIDS => [
        Guid::GUID_IMDB => 'tt6100',
        Guid::GUID_TVDB => '6200',
        Guid::GUID_TMDB => '6300',
        Guid::GUID_TVMAZE => '6400',
        Guid::GUID_TVRAGE => '6500',
        Guid::GUID_ANIDB => '6600',
    ],
    iState::COLUMN_META_DATA => [
        'home_plex' => [
            iState::COLUMN_ID => 122,
            iState::COLUMN_TYPE => iState::TYPE_EPISODE,
            iState::COLUMN_WATCHED => 1,
            iState::COLUMN_TITLE => 'Series Title',
            iState::COLUMN_YEAR => '2020',
            iState::COLUMN_SEASON => '1',
            iState::COLUMN_EPISODE => '2',
            iState::COLUMN_META_DATA_EXTRA => [
                iState::COLUMN_META_DATA_EXTRA_DATE => '2020-01-03',
                iState::COLUMN_META_DATA_EXTRA_TITLE => 'Episode Title',
            ],
            iState::COLUMN_META_DATA_ADDED_AT => 1,
            iState::COLUMN_META_DATA_PLAYED_AT => 2,
        ],
        'home_jellyfin' => [
            iState::COLUMN_ID => 122,
            iState::COLUMN_TYPE => iState::TYPE_EPISODE,
            iState::COLUMN_WATCHED => 1,
            iState::COLUMN_TITLE => 'Series Title',
            iState::COLUMN_YEAR => '2020',
            iState::COLUMN_SEASON => '1',
            iState::COLUMN_EPISODE => '2',
            iState::COLUMN_META_DATA_EXTRA => [
                iState::COLUMN_META_DATA_EXTRA_DATE => '2020-01-03',
                iState::COLUMN_META_DATA_EXTRA_TITLE => 'to test quorum',
            ],
            iState::COLUMN_META_DATA_ADDED_AT => 1,
            iState::COLUMN_META_DATA_PLAYED_AT => 2,
        ],
        'home_emby' => [
            iState::COLUMN_ID => 122,
            iState::COLUMN_TYPE => iState::TYPE_EPISODE,
            iState::COLUMN_WATCHED => 1,
            iState::COLUMN_TITLE => 'Series Title',
            iState::COLUMN_YEAR => '2020',
            iState::COLUMN_SEASON => '1',
            iState::COLUMN_EPISODE => '2',
            iState::COLUMN_META_DATA_EXTRA => [
                iState::COLUMN_META_DATA_EXTRA_DATE => '2020-01-03',
                iState::COLUMN_META_DATA_EXTRA_TITLE => 'to test quorum',
            ],
            iState::COLUMN_META_DATA_ADDED_AT => 1,
            iState::COLUMN_META_DATA_PLAYED_AT => 2,
        ],
    ],
    iState::COLUMN_EXTRA => [
        'home_plex' => [
            iState::COLUMN_EXTRA_DATE => 1,
            iState::COLUMN_EXTRA_EVENT => 'media.scrobble'
        ],
        'home_jellyfin' => [
            iState::COLUMN_EXTRA_DATE => 1,
            iState::COLUMN_EXTRA_EVENT => 'media.scrobble'
        ],
        'home_emby' => [
            iState::COLUMN_EXTRA_DATE => 1,
            iState::COLUMN_EXTRA_EVENT => 'media.scrobble'
        ],
    ],
    iState::COLUMN_CREATED_AT => 2,
    iState::COLUMN_UPDATED_AT => 2,
];
