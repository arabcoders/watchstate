<?php

declare(strict_types=1);

use App\Libs\Entity\StateInterface as iState;
use App\Libs\Guid;

return [
    iState::COLUMN_ID => null,
    iState::COLUMN_TYPE => iState::TYPE_MOVIE,
    iState::COLUMN_UPDATED => 1,
    iState::COLUMN_WATCHED => 1,
    iState::COLUMN_VIA => 'test_plex',
    iState::COLUMN_TITLE => 'Movie Title',
    iState::COLUMN_YEAR => 2020,
    iState::COLUMN_SEASON => null,
    iState::COLUMN_EPISODE => null,
    iState::COLUMN_PARENT => [],
    iState::COLUMN_GUIDS => [
        Guid::GUID_IMDB => 'tt1100',
        Guid::GUID_TVDB => '1200',
        Guid::GUID_TMDB => '1300',
        Guid::GUID_TVMAZE => '1400',
        Guid::GUID_TVRAGE => '1500',
        Guid::GUID_ANIDB => '1600',
    ],
    iState::COLUMN_META_DATA => [
        'test_plex' => [
            iState::COLUMN_ID => 121,
            iState::COLUMN_TYPE => iState::TYPE_MOVIE,
            iState::COLUMN_WATCHED => 1,
            iState::COLUMN_YEAR => '2020',
            iState::COLUMN_META_DATA_EXTRA => [
                iState::COLUMN_META_DATA_EXTRA_DATE => '2020-01-03',
            ],
            iState::COLUMN_META_DATA_PROGRESS => 5000,
            iState::COLUMN_META_DATA_ADDED_AT => 1,
            iState::COLUMN_META_DATA_PLAYED_AT => 2,
        ],
    ],
    iState::COLUMN_EXTRA => [
        'test_plex' => [
            iState::COLUMN_EXTRA_EVENT => 'media.scrobble',
            iState::COLUMN_EXTRA_DATE => 2,
        ],
    ],
    iState::COLUMN_CREATED_AT => 2,
    iState::COLUMN_UPDATED_AT => 2,
];
