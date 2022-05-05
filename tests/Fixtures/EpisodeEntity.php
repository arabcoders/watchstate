<?php

declare(strict_types=1);

use App\Libs\Entity\StateInterface;

return [
    'id' => null,
    'type' => StateInterface::TYPE_EPISODE,
    'updated' => 0,
    'watched' => 1,
    'meta' => [
        'via' => 'Plex@Home',
        'series' => 'Series Title',
        'year' => 2020,
        'season' => 1,
        'episode' => 2,
        'title' => 'Episode Title',
        'date' => '2020-01-03',
        'webhook' => [
            'event' => 'media.scrobble'
        ],
        'parent' => [
            'guid_imdb' => '510',
            'guid_tvdb' => '520',
        ],
    ],
    'guid_plex' => StateInterface::TYPE_EPISODE . '/6000',
    'guid_imdb' => StateInterface::TYPE_EPISODE . '/6100',
    'guid_tvdb' => StateInterface::TYPE_EPISODE . '/6200',
    'guid_tmdb' => StateInterface::TYPE_EPISODE . '/6300',
    'guid_tvmaze' => StateInterface::TYPE_EPISODE . '/6400',
    'guid_tvrage' => StateInterface::TYPE_EPISODE . '/6500',
    'guid_anidb' => StateInterface::TYPE_EPISODE . '/6600',
];
