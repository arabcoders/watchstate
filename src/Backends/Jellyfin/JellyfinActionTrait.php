<?php

declare(strict_types=1);

namespace App\Backends\Jellyfin;

use App\Backends\Common\Context;
use App\Libs\Container;
use App\Libs\Entity\StateEntity;
use App\Libs\Entity\StateInterface as iFace;
use App\Libs\Guid;
use RuntimeException;

trait JellyfinActionTrait
{
    private array $typeMapper = [
        JellyfinClient::TYPE_SHOW => iFace::TYPE_SHOW,
        JellyfinClient::TYPE_MOVIE => iFace::TYPE_MOVIE,
        JellyfinClient::TYPE_EPISODE => iFace::TYPE_EPISODE,
    ];

    /**
     * Create {@see StateEntity} Object based on given data.
     *
     * @param Context $context
     * @param JellyfinGuid $guid
     * @param array $item Jellyfin/emby API item.
     * @param array $opts options
     *
     * @return StateEntity Return Object on successful creation.
     */
    protected function createEntity(Context $context, JellyfinGuid $guid, array $item, array $opts = []): StateEntity
    {
        // -- Handle watched/updated column in a special way to support mark as unplayed.
        if (null !== ($opts['override'][iFace::COLUMN_WATCHED] ?? null)) {
            $isPlayed = (bool)$opts['override'][iFace::COLUMN_WATCHED];
            $date = $opts['override'][iFace::COLUMN_UPDATED] ?? ag($item, 'DateCreated');
        } else {
            $isPlayed = (bool)ag($item, 'UserData.Played', false);
            $date = ag($item, true === $isPlayed ? ['UserData.LastPlayedDate', 'DateCreated'] : 'DateCreated');
        }

        if (null === $date) {
            throw new RuntimeException('No date was set on object.');
        }

        $type = $this->typeMapper[ag($item, 'Type')] ?? ag($item, 'Type');

        $guids = $guid->get(ag($item, 'ProviderIds', []), context: [
            'item' => [
                'id' => (string)ag($item, 'Id'),
                'type' => ag($item, 'Type'),
                'title' => match ($type) {
                    iFace::TYPE_MOVIE => sprintf(
                        '%s (%s)',
                        ag($item, ['Name', 'OriginalTitle'], '??'),
                        ag($item, 'ProductionYear', '0000')
                    ),
                    iFace::TYPE_EPISODE => sprintf(
                        '%s - (%sx%s)',
                        ag($item, ['Name', 'OriginalTitle'], '??'),
                        str_pad((string)ag($item, 'ParentIndexNumber', 0), 2, '0', STR_PAD_LEFT),
                        str_pad((string)ag($item, 'IndexNumber', 0), 3, '0', STR_PAD_LEFT),
                    ),
                },
                'year' => (string)ag($item, 'ProductionYear', '0000'),
            ],
        ]);

        $guids += Guid::makeVirtualGuid($context->backendName, (string)ag($item, 'Id'));

        $builder = [
            iFace::COLUMN_TYPE => strtolower(ag($item, 'Type')),
            iFace::COLUMN_UPDATED => makeDate($date)->getTimestamp(),
            iFace::COLUMN_WATCHED => (int)$isPlayed,
            iFace::COLUMN_VIA => $context->backendName,
            iFace::COLUMN_TITLE => ag($item, ['Name', 'OriginalTitle'], '??'),
            iFace::COLUMN_GUIDS => $guids,
            iFace::COLUMN_META_DATA => [
                $context->backendName => [
                    iFace::COLUMN_ID => (string)ag($item, 'Id'),
                    iFace::COLUMN_TYPE => $type,
                    iFace::COLUMN_WATCHED => true === $isPlayed ? '1' : '0',
                    iFace::COLUMN_VIA => $context->backendName,
                    iFace::COLUMN_TITLE => ag($item, ['Name', 'OriginalTitle'], '??'),
                    iFace::COLUMN_GUIDS => array_change_key_case((array)ag($item, 'ProviderIds', []), CASE_LOWER),
                    iFace::COLUMN_META_DATA_ADDED_AT => (string)makeDate(ag($item, 'DateCreated'))->getTimestamp(),
                ],
            ],
            iFace::COLUMN_EXTRA => [],
        ];

        $metadata = &$builder[iFace::COLUMN_META_DATA][$context->backendName];
        $metadataExtra = &$metadata[iFace::COLUMN_META_DATA_EXTRA];

        // -- jellyfin/emby API does not provide library ID.
        if (null !== ($library = $opts['library'] ?? null)) {
            $metadata[iFace::COLUMN_META_LIBRARY] = (string)$library;
        }

        if (iFace::TYPE_EPISODE === $type) {
            $builder[iFace::COLUMN_SEASON] = ag($item, 'ParentIndexNumber', 0);
            $builder[iFace::COLUMN_EPISODE] = ag($item, 'IndexNumber', 0);

            if (null !== ($parentId = ag($item, 'SeriesId'))) {
                $metadata[iFace::COLUMN_META_SHOW] = (string)$parentId;
            }

            $metadata[iFace::COLUMN_TITLE] = ag($item, 'SeriesName', '??');
            $metadata[iFace::COLUMN_SEASON] = (string)$builder[iFace::COLUMN_SEASON];
            $metadata[iFace::COLUMN_EPISODE] = (string)$builder[iFace::COLUMN_EPISODE];

            $metadataExtra[iFace::COLUMN_META_DATA_EXTRA_TITLE] = $builder[iFace::COLUMN_TITLE];
            $builder[iFace::COLUMN_TITLE] = $metadata[iFace::COLUMN_TITLE];

            if (null !== $parentId) {
                $builder[iFace::COLUMN_PARENT] = $this->getEpisodeParent($parentId);
                $metadata[iFace::COLUMN_PARENT] = $builder[iFace::COLUMN_PARENT];
            }
        }

        if (!empty($metadata) && null !== ($mediaYear = ag($item, 'ProductionYear'))) {
            $builder[iFace::COLUMN_YEAR] = (int)$mediaYear;
            $metadata[iFace::COLUMN_YEAR] = (string)$mediaYear;
        }

        if (null !== ($mediaPath = ag($item, 'Path')) && !empty($mediaPath)) {
            $metadata[iFace::COLUMN_META_PATH] = (string)$mediaPath;
        }

        if (null !== ($PremieredAt = ag($item, 'PremiereDate'))) {
            $metadataExtra[iFace::COLUMN_META_DATA_EXTRA_DATE] = makeDate($PremieredAt)->format('Y-m-d');
        }

        if (true === $isPlayed) {
            $metadata[iFace::COLUMN_META_DATA_PLAYED_AT] = (string)makeDate($date)->getTimestamp();
        }

        unset($metadata, $metadataExtra);

        if (null !== ($opts['override'] ?? null)) {
            $builder = array_replace_recursive($builder, $opts['override'] ?? []);
        }

        return Container::get(iFace::class)::fromArray($builder);
    }
}
