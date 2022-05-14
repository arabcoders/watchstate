<?php

declare(strict_types=1);

use App\Libs\Entity\StateInterface;

return [
    'id' => null,
    'type' => StateInterface::TYPE_EPISODE,
    'updated' => 1,
    'watched' => 1,
    'via' => 'Plex@Home',
    'title' => 'Series Title',
    'year' => 2020,
    'season' => 1,
    'episode' => 2,
    'parent' => [
        'guid_imdb' => '510',
        'guid_tvdb' => '520',
    ],
    'guids' => [
        'guid_plex' => '6000',
        'guid_imdb' => '6100',
        'guid_tvdb' => '6200',
        'guid_tmdb' => '6300',
        'guid_tvmaze' => '6400',
        'guid_tvrage' => '6500',
        'guid_anidb' => '6600',
    ],
    'extra' => [
        'title' => 'Episode Title',
        'date' => '2020-01-03',
        'webhook' => [
            'event' => 'media.scrobble'
        ],
    ],
    'suids' => [
        'jf@home' => '77f92c9c8f14b343bedee95d7fcded3a',
        'plex@home' => 121,
    ],
];
