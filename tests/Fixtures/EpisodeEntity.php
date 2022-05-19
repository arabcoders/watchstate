<?php

declare(strict_types=1);

use App\Libs\Entity\StateInterface as iFace;
use App\Libs\Guid;

return [
    iFace::COLUMN_ID => null,
    iFace::COLUMN_TYPE => iFace::TYPE_EPISODE,
    iFace::COLUMN_UPDATED => 1,
    iFace::COLUMN_WATCHED => 1,
    iFace::COLUMN_VIA => 'home_plex',
    iFace::COLUMN_TITLE => 'Series Title',
    iFace::COLUMN_YEAR => 2020,
    iFace::COLUMN_SEASON => 1,
    iFace::COLUMN_EPISODE => 2,
    iFace::COLUMN_PARENT => [
        Guid::GUID_IMDB => '510',
        Guid::GUID_TVDB => '520',
    ],
    iFace::COLUMN_GUIDS => [
        Guid::GUID_PLEX => '6000',
        Guid::GUID_IMDB => '6100',
        Guid::GUID_TVDB => '6200',
        Guid::GUID_TMDB => '6300',
        Guid::GUID_TVMAZE => '6400',
        Guid::GUID_TVRAGE => '6500',
        Guid::GUID_ANIDB => '6600',
    ],
    iFace::COLUMN_META_DATA => [
        'home_plex' => [
            iFace::COLUMN_ID => 121,
            iFace::COLUMN_TYPE => iFace::TYPE_EPISODE,
            iFace::COLUMN_UPDATED => 1,
            iFace::COLUMN_WATCHED => 1,
            iFace::COLUMN_TITLE => 'Series Title',
            iFace::COLUMN_YEAR => '2020',
            iFace::COLUMN_SEASON => '1',
            iFace::COLUMN_EPISODE => '2',
            iFace::COLUMN_META_DATA_EXTRA => [
                iFace::COLUMN_META_DATA_EXTRA_DATE => '2020-01-03',
                iFace::COLUMN_META_DATA_EXTRA_TITLE => 'Episode Title',
                iFace::COLUMN_META_DATA_EXTRA_EVENT => 'media.scrobble'
            ],
        ],
    ],
    iFace::COLUMN_EXTRA => [],
];
