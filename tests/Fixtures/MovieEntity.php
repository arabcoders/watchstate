<?php

declare(strict_types=1);

use App\Libs\Entity\StateInterface;

return [
    'id' => null,
    'type' => StateInterface::TYPE_MOVIE,
    'updated' => 1,
    'watched' => 1,
    'via' => 'JF@Home',
    'title' => 'Movie Title',
    'year' => 2020,
    'season' => null,
    'episode' => null,
    'parent' => [],
    'guids' => [
        'guid_plex' => '1000',
        'guid_imdb' => '1100',
        'guid_tvdb' => '1200',
        'guid_tmdb' => '1300',
        'guid_tvmaze' => '1400',
        'guid_tvrage' => '1500',
        'guid_anidb' => '1600',
    ],
    'extra' => [
        'webhook' => [
            'event' => 'ItemAdded'
        ]
    ],

];
