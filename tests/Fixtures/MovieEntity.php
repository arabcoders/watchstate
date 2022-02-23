<?php

declare(strict_types=1);

use App\Libs\Entity\StateInterface;

return [
    'id' => null,
    'type' => StateInterface::TYPE_MOVIE,
    'updated' => 1,
    'watched' => 1,
    'meta' => [
        'via' => 'JF@Home',
        'title' => 'Movie Title',
        'year' => 2020,
        'webhook' => [
            'event' => 'ItemAdded'
        ]
    ],
    'guid_plex' => StateInterface::TYPE_MOVIE . '/1000',
    'guid_imdb' => StateInterface::TYPE_MOVIE . '/1100',
    'guid_tvdb' => StateInterface::TYPE_MOVIE . '/1200',
    'guid_tmdb' => StateInterface::TYPE_MOVIE . '/1300',
    'guid_tvmaze' => StateInterface::TYPE_MOVIE . '/1400',
    'guid_tvrage' => StateInterface::TYPE_MOVIE . '/1500',
    'guid_anidb' => StateInterface::TYPE_MOVIE . '/1600',
];
