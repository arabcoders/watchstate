<?php

declare(strict_types=1);

namespace App\Backends\Jellyfin;

use App\Backends\Common\Context;
use App\Backends\Common\GuidInterface as iGuid;
use App\Backends\Jellyfin\Action\GetLibrariesList;
use App\Backends\Jellyfin\Action\GetMetaData;
use App\Libs\Container;
use App\Libs\Entity\StateEntity;
use App\Libs\Entity\StateInterface as iState;
use App\Libs\Guid;
use App\Libs\Options;
use RuntimeException;

trait JellyfinActionTrait
{
    private array $typeMapper = [
        JellyfinClient::TYPE_SHOW => iState::TYPE_SHOW,
        JellyfinClient::TYPE_MOVIE => iState::TYPE_MOVIE,
        JellyfinClient::TYPE_EPISODE => iState::TYPE_EPISODE,
    ];

    /**
     * Create {@see StateEntity} Object based on given data.
     *
     * @param Context $context
     * @param iGuid $guid
     * @param array $item Jellyfin/emby API item.
     * @param array $opts options
     *
     * @return iState Return object on successful creation.
     */
    protected function createEntity(Context $context, iGuid $guid, array $item, array $opts = []): iState
    {
        // -- Handle watched/updated column in a special way to support mark as unplayed.
        if (null !== ($opts['override'][iState::COLUMN_WATCHED] ?? null)) {
            $isPlayed = (bool)$opts['override'][iState::COLUMN_WATCHED];
            $date = $opts['override'][iState::COLUMN_UPDATED] ?? ag($item, 'DateCreated');
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
                'title' => match (ag($item, 'Type')) {
                    JellyfinClient::TYPE_MOVIE => sprintf(
                        '%s (%s)',
                        ag($item, ['Name', 'OriginalTitle'], '??'),
                        ag($item, 'ProductionYear', '0000')
                    ),
                    JellyfinClient::TYPE_EPISODE => sprintf(
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
            iState::COLUMN_TYPE => strtolower(ag($item, 'Type')),
            iState::COLUMN_UPDATED => makeDate($date)->getTimestamp(),
            iState::COLUMN_WATCHED => (int)$isPlayed,
            iState::COLUMN_VIA => $context->backendName,
            iState::COLUMN_TITLE => ag($item, ['Name', 'OriginalTitle'], '??'),
            iState::COLUMN_GUIDS => $guids,
            iState::COLUMN_META_DATA => [
                $context->backendName => [
                    iState::COLUMN_ID => (string)ag($item, 'Id'),
                    iState::COLUMN_TYPE => $type,
                    iState::COLUMN_WATCHED => true === $isPlayed ? '1' : '0',
                    iState::COLUMN_VIA => $context->backendName,
                    iState::COLUMN_TITLE => ag($item, ['Name', 'OriginalTitle'], '??'),
                    iState::COLUMN_GUIDS => $guid->parse((array)ag($item, 'ProviderIds', [])),
                    iState::COLUMN_META_DATA_ADDED_AT => (string)makeDate(ag($item, 'DateCreated'))->getTimestamp(),
                ],
            ],
            iState::COLUMN_EXTRA => [],
        ];

        $metadata = &$builder[iState::COLUMN_META_DATA][$context->backendName];
        $metadataExtra = &$metadata[iState::COLUMN_META_DATA_EXTRA];

        // -- jellyfin/emby API does not provide library ID.
        if (null !== ($library = $opts['library'] ?? null)) {
            $metadata[iState::COLUMN_META_LIBRARY] = (string)$library;
        }

        if (iState::TYPE_EPISODE === $type) {
            $builder[iState::COLUMN_SEASON] = ag($item, 'ParentIndexNumber', 0);
            $builder[iState::COLUMN_EPISODE] = ag($item, 'IndexNumber', 0);

            if (null !== ($parentId = ag($item, 'SeriesId'))) {
                $metadata[iState::COLUMN_META_SHOW] = (string)$parentId;
            }

            $metadata[iState::COLUMN_TITLE] = ag($item, 'SeriesName', '??');
            $metadata[iState::COLUMN_SEASON] = (string)$builder[iState::COLUMN_SEASON];
            $metadata[iState::COLUMN_EPISODE] = (string)$builder[iState::COLUMN_EPISODE];

            $metadataExtra[iState::COLUMN_META_DATA_EXTRA_TITLE] = $builder[iState::COLUMN_TITLE];
            $builder[iState::COLUMN_TITLE] = $metadata[iState::COLUMN_TITLE];

            if (null !== $parentId) {
                $builder[iState::COLUMN_PARENT] = $this->getEpisodeParent(
                    context: $context,
                    guid:    $guid,
                    id:      $parentId
                );
                $metadata[iState::COLUMN_PARENT] = $builder[iState::COLUMN_PARENT];
            }
        }

        if (!empty($metadata) && null !== ($mediaYear = ag($item, 'ProductionYear'))) {
            $builder[iState::COLUMN_YEAR] = (int)$mediaYear;
            $metadata[iState::COLUMN_YEAR] = (string)$mediaYear;
        }

        if (null !== ($mediaPath = ag($item, 'Path')) && !empty($mediaPath)) {
            $metadata[iState::COLUMN_META_PATH] = (string)$mediaPath;
        }

        if (null !== ($PremieredAt = ag($item, 'PremiereDate'))) {
            $metadataExtra[iState::COLUMN_META_DATA_EXTRA_DATE] = makeDate($PremieredAt)->format('Y-m-d');
        }

        if (true === $isPlayed) {
            $metadata[iState::COLUMN_META_DATA_PLAYED_AT] = (string)makeDate($date)->getTimestamp();
        }

        unset($metadata, $metadataExtra);

        if (null !== ($opts['override'] ?? null)) {
            $builder = array_replace_recursive($builder, $opts['override'] ?? []);
        }

        return Container::get(iState::class)::fromArray($builder);
    }

    /**
     * Get item details.
     *
     * @param Context $context
     * @param string|int $id
     * @param array $opts
     * @return array
     */
    protected function getItemDetails(Context $context, string|int $id, array $opts = []): array
    {
        $response = Container::get(GetMetaData::class)(context: $context, id: $id, opts: $opts);

        if ($response->isSuccessful()) {
            return $response->response;
        }

        throw new RuntimeException(message: $response->error->format(), previous: $response->error->previous);
    }

    protected function getEpisodeParent(Context $context, iGuid $guid, int|string $id, array $logContext = []): array
    {
        $cacheKey = JellyfinClient::TYPE_SHOW . '.' . $id;

        if (true === $context->cache->has($cacheKey)) {
            return $context->cache->get($cacheKey);
        }

        $json = ag($this->getItemDetails(context: $context, id: $id), []);

        $logContext['item'] = [
            'id' => ag($json, 'Id'),
            'title' => sprintf(
                '%s (%s)',
                ag($json, ['Name', 'OriginalTitle'], '??'),
                ag($json, 'ProductionYear', '0000')
            ),
            'year' => ag($json, 'ProductionYear', null),
            'type' => ag($json, 'Type'),
        ];

        if (null === ($itemType = ag($json, 'Type')) || JellyfinClient::TYPE_SHOW !== $itemType) {
            return [];
        }

        $providersId = (array)ag($json, 'ProviderIds', []);

        if (false === $guid->has($providersId)) {
            $context->cache->set($cacheKey, []);
            return [];
        }

        $context->cache->set(
            $cacheKey,
            Guid::fromArray($guid->get($providersId), context: [
                'backend' => $context->backendName,
                ...$logContext,
            ])->getAll()
        );

        return $context->cache->get($cacheKey);
    }

    /**
     * Get Backend Libraries details.
     */
    protected function getBackendLibraries(Context $context, array $opts = []): array
    {
        $opts = ag_set($opts, Options::RAW_RESPONSE, true);

        $response = Container::get(GetLibrariesList::class)(context: $context, opts: $opts);

        if (!$response->isSuccessful()) {
            throw new RuntimeException(message: $response->error->format(), previous: $response->error->previous);
        }

        $arr = [];

        foreach ($response->response as $item) {
            $arr[$item['id']] = $item['raw'];
        }

        return $arr;
    }
}
