<?php

declare(strict_types=1);

namespace App\Backends\Plex;

use App\Backends\Common\Context;
use App\Libs\Container;
use App\Libs\Entity\StateEntity;
use App\Libs\Entity\StateInterface as iFace;
use App\Libs\Guid;
use RuntimeException;

trait PlexActionTrait
{
    protected function createEntity(Context $context, PlexGuid $guid, array $item, array $opts = []): StateEntity
    {
        // -- Handle watched/updated column in a special way to support mark as unplayed.
        if (null !== ($opts['override'][iFace::COLUMN_WATCHED] ?? null)) {
            $isPlayed = (bool)$opts['override'][iFace::COLUMN_WATCHED];
            $date = $opts['override'][iFace::COLUMN_UPDATED] ?? ag($item, 'addedAt');
        } else {
            $isPlayed = (bool)ag($item, 'viewCount', false);
            $date = ag($item, true === $isPlayed ? 'lastViewedAt' : 'addedAt');
        }

        if (null === $date) {
            throw new RuntimeException('No date was set on object.');
        }

        if (null === ag($item, 'Guid')) {
            $item['Guid'] = [['id' => ag($item, 'guid')]];
        } else {
            $item['Guid'][] = ['id' => ag($item, 'guid')];
        }

        $type = ag($item, 'type');

        $guids = $guid->get(ag($item, 'Guid', []), context: [
            'item' => [
                'id' => ag($item, 'ratingKey'),
                'type' => ag($item, 'type'),
                'title' => match ($type) {
                    iFace::TYPE_MOVIE => sprintf(
                        '%s (%s)',
                        ag($item, ['title', 'originalTitle'], '??'),
                        ag($item, 'year', '0000')
                    ),
                    iFace::TYPE_EPISODE => sprintf(
                        '%s - (%sx%s)',
                        ag($item, ['grandparentTitle', 'originalTitle', 'title'], '??'),
                        str_pad((string)ag($item, 'parentIndex', 0), 2, '0', STR_PAD_LEFT),
                        str_pad((string)ag($item, 'index', 0), 3, '0', STR_PAD_LEFT),
                    ),
                },
                'year' => ag($item, ['grandParentYear', 'parentYear', 'year']),
                'plex_id' => str_starts_with(ag($item, 'guid', ''), 'plex://') ? ag($item, 'guid') : 'none',
            ],
        ]);

        $guids += Guid::makeVirtualGuid($context->backendName, (string)ag($item, 'ratingKey'));

        $builder = [
            iFace::COLUMN_TYPE => $type,
            iFace::COLUMN_UPDATED => (int)$date,
            iFace::COLUMN_WATCHED => (int)$isPlayed,
            iFace::COLUMN_VIA => $context->backendName,
            iFace::COLUMN_TITLE => ag($item, ['title', 'originalTitle'], '??'),
            iFace::COLUMN_GUIDS => $guids,
            iFace::COLUMN_META_DATA => [
                $context->backendName => [
                    iFace::COLUMN_ID => (string)ag($item, 'ratingKey'),
                    iFace::COLUMN_TYPE => $type,
                    iFace::COLUMN_WATCHED => true === $isPlayed ? '1' : '0',
                    iFace::COLUMN_VIA => $context->backendName,
                    iFace::COLUMN_TITLE => ag($item, ['title', 'originalTitle'], '??'),
                    iFace::COLUMN_GUIDS => $guid->parse(ag($item, 'Guid', [])),
                    iFace::COLUMN_META_DATA_ADDED_AT => (string)ag($item, 'addedAt'),
                ],
            ],
            iFace::COLUMN_EXTRA => [],
        ];

        $metadata = &$builder[iFace::COLUMN_META_DATA][$context->backendName];
        $metadataExtra = &$metadata[iFace::COLUMN_META_DATA_EXTRA];

        if (null !== ($library = ag($item, 'librarySectionID', $opts['library'] ?? null))) {
            $metadata[iFace::COLUMN_META_LIBRARY] = (string)$library;
        }

        if (iFace::TYPE_EPISODE === $type) {
            $builder[iFace::COLUMN_SEASON] = (int)ag($item, 'parentIndex', 0);
            $builder[iFace::COLUMN_EPISODE] = (int)ag($item, 'index', 0);

            $metadata[iFace::COLUMN_META_SHOW] = (string)ag($item, ['grandparentRatingKey', 'parentRatingKey'], '??');

            $metadata[iFace::COLUMN_TITLE] = ag($item, 'grandparentTitle', '??');
            $metadata[iFace::COLUMN_SEASON] = (string)$builder[iFace::COLUMN_SEASON];
            $metadata[iFace::COLUMN_EPISODE] = (string)$builder[iFace::COLUMN_EPISODE];

            $metadataExtra[iFace::COLUMN_META_DATA_EXTRA_TITLE] = $builder[iFace::COLUMN_TITLE];
            $builder[iFace::COLUMN_TITLE] = $metadata[iFace::COLUMN_TITLE];

            if (null !== ($parentId = ag($item, ['grandparentRatingKey', 'parentRatingKey']))) {
                $builder[iFace::COLUMN_PARENT] = $this->getEpisodeParent($parentId);
                $metadata[iFace::COLUMN_PARENT] = $builder[iFace::COLUMN_PARENT];
            }
        }

        if (null !== ($mediaYear = ag($item, ['grandParentYear', 'parentYear', 'year'])) && !empty($mediaYear)) {
            $builder[iFace::COLUMN_YEAR] = (int)$mediaYear;
            $metadata[iFace::COLUMN_YEAR] = (string)$mediaYear;
        }

        if (null !== ($mediaPath = ag($item, 'Media.0.Part.0.file')) && !empty($mediaPath)) {
            $metadata[iFace::COLUMN_META_PATH] = (string)$mediaPath;
        }

        if (null !== ($PremieredAt = ag($item, 'originallyAvailableAt'))) {
            $metadataExtra[iFace::COLUMN_META_DATA_EXTRA_DATE] = makeDate($PremieredAt)->format('Y-m-d');
        }

        if (true === $isPlayed) {
            $metadata[iFace::COLUMN_META_DATA_PLAYED_AT] = (string)$date;
        }

        unset($metadata, $metadataExtra);

        if (null !== ($opts['override'] ?? null)) {
            $builder = array_replace_recursive($builder, $opts['override'] ?? []);
        }

        return Container::get(iFace::class)::fromArray($builder);
    }
}
