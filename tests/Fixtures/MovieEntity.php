<?php

declare(strict_types=1);

use App\Libs\Entity\StateInterface as iFace;
use App\Libs\Guid;

return [
    iFace::COLUMN_ID => null,
    iFace::COLUMN_TYPE => iFace::TYPE_MOVIE,
    iFace::COLUMN_UPDATED => 1,
    iFace::COLUMN_WATCHED => 1,
    iFace::COLUMN_VIA => 'home_plex',
    iFace::COLUMN_TITLE => 'Movie Title',
    iFace::COLUMN_YEAR => 2020,
    iFace::COLUMN_SEASON => null,
    iFace::COLUMN_EPISODE => null,
    iFace::COLUMN_PARENT => [],
    iFace::COLUMN_GUIDS => [
        Guid::GUID_PLEX => '1000',
        Guid::GUID_IMDB => '1100',
        Guid::GUID_TVDB => '1200',
        Guid::GUID_TMDB => '1300',
        Guid::GUID_TVMAZE => '1400',
        Guid::GUID_TVRAGE => '1500',
        Guid::GUID_ANIDB => '1600',
        ...Guid::makeVirtualGuid('home_plex', '121'),
    ],
    iFace::COLUMN_META_DATA => [
        'home_plex' => [
            iFace::COLUMN_ID => 121,
            iFace::COLUMN_TYPE => iFace::TYPE_MOVIE,
            iFace::COLUMN_WATCHED => 1,
            iFace::COLUMN_YEAR => '2020',
            iFace::COLUMN_META_DATA_EXTRA => [
                iFace::COLUMN_META_DATA_EXTRA_DATE => '2020-01-03',
            ],
            iFace::COLUMN_META_DATA_ADDED_AT => 1,
            iFace::COLUMN_META_DATA_PLAYED_AT => 2,
        ],
    ],
    iFace::COLUMN_EXTRA => [
        iFace::COLUMN_EXTRA_EVENT => 'media.scrobble',
        iFace::COLUMN_EXTRA_DATE => 1,
    ],
];
