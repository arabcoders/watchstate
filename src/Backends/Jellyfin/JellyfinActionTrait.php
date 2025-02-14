<?php

declare(strict_types=1);

namespace App\Backends\Jellyfin;

use App\Backends\Common\Context;
use App\Backends\Common\GuidInterface as iGuid;
use App\Backends\Jellyfin\Action\GetLibrariesList;
use App\Backends\Jellyfin\Action\GetMetaData;
use App\Backends\Jellyfin\Action\GetWebUrl;
use App\Libs\Container;
use App\Libs\Entity\StateInterface as iState;
use App\Libs\Exceptions\Backends\InvalidArgumentException;
use App\Libs\Exceptions\Backends\RuntimeException;
use App\Libs\Guid;
use App\Libs\Options;
use Psr\Http\Message\UriInterface as iUri;

/**
 * Trait JellyfinActionTrait
 *
 * Common methods for interacting with Jellyfin API.
 */
trait JellyfinActionTrait
{
    /**
     * Create {@see iState} Object based on given data.
     *
     * @param Context $context Context object.
     * @param iGuid $guid Guid object.
     * @param array $item Jellyfin/emby API item.
     * @param array $opts (Optional) options.
     *
     * @return iState Return object on successful creation.
     * @throws InvalidArgumentException When no date was set on object.
     * @throws RuntimeException When API call was not successful.
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

        // -- For Progress action we need to use the latest date.
        if (true === (bool)($opts['latest_date'] ?? false)) {
            if (null !== ($_lastPlayed = ag($item, 'UserData.LastPlayedDate'))) {
                $date = $_lastPlayed;
            }
        }

        $type = ag($item, 'Type', '');
        $type = JellyfinClient::TYPE_MAPPER[$type] ?? $type;

        if (null === $date) {
            if (iState::TYPE_SHOW !== $type) {
                throw new InvalidArgumentException(r("'{type}' No date was set on object.", ['type' => $type]));
            }
            $date = 0;
        }

        $logContext = [
            'client' => $context->clientName,
            'backend' => $context->backendName,
            'user' => $context->userContext->name,
            'item' => [
                'id' => (string)ag($item, 'Id'),
                'type' => $type,
                'title' => match ($type) {
                    iState::TYPE_MOVIE, iState::TYPE_SHOW => sprintf(
                        '%s (%s)',
                        ag($item, ['Name', 'OriginalTitle'], '??'),
                        ag($item, 'ProductionYear', '0000')
                    ),
                    iState::TYPE_EPISODE => sprintf(
                        '%s - (%sx%s)',
                        ag($item, ['Name', 'OriginalTitle'], '??'),
                        str_pad((string)ag($item, 'ParentIndexNumber', 0), 2, '0', STR_PAD_LEFT),
                        str_pad((string)ag($item, 'IndexNumber', 0), 3, '0', STR_PAD_LEFT),
                    ),
                    default => throw new InvalidArgumentException(
                        r("Unexpected Content type '{type}' was received.", [
                            'type' => $type,
                        ])
                    ),
                },
                'year' => (string)ag($item, 'ProductionYear', '0000'),
            ],
        ];

        if (iState::TYPE_EPISODE === $type && true === (bool)ag($opts, Options::DISABLE_GUID, false)) {
            $guids = [];
        } else {
            $guids = $guid->get(guids: ag($item, 'ProviderIds', []), context: $logContext);
        }

        $builder = [
            iState::COLUMN_TYPE => $type,
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
                    iState::COLUMN_GUIDS => $guid->parse(
                        guids: (array)ag($item, 'ProviderIds', []),
                        context: $logContext
                    ),
                    iState::COLUMN_META_DATA_ADDED_AT => (string)makeDate(ag($item, 'DateCreated'))->getTimestamp(),
                ],
            ],
            iState::COLUMN_EXTRA => [],
        ];

        $metadata = &$builder[iState::COLUMN_META_DATA][$context->backendName];
        $metadataExtra = &$metadata[iState::COLUMN_META_DATA_EXTRA];

        // -- jellyfin/emby API does not provide library ID.
        if (null !== ($library = $opts[iState::COLUMN_META_LIBRARY] ?? null)) {
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
                    guid: $guid,
                    id: $parentId
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
            $metadata[iState::COLUMN_META_DATA_PROGRESS] = "0";
        }

        if (false === $isPlayed && null !== ($progress = ag($item, 'UserData.PlaybackPositionTicks', null))) {
            // -- Convert to play progress to milliseconds.
            $metadata[iState::COLUMN_META_DATA_PROGRESS] = (string)floor($progress / 1_00_00);
        }

        unset($metadata, $metadataExtra);

        if (null !== ($opts['override'] ?? null)) {
            $builder = array_replace_recursive($builder, $opts['override'] ?? []);
        }

        if (true === is_array($builder[iState::COLUMN_GUIDS] ?? false)) {
            $builder[iState::COLUMN_GUIDS] = Guid::fromArray(
                payload: $builder[iState::COLUMN_GUIDS],
                context: $logContext,
            )->getAll();
        }

        return Container::get(iState::class)::fromArray($builder);
    }

    /**
     * Get item details.
     *
     * @param Context $context
     * @param string|int $id
     * @param array $opts
     *
     * @return array
     * @throws RuntimeException When API call was not successful.
     */
    protected function getItemDetails(Context $context, string|int $id, array $opts = []): array
    {
        $response = Container::get(GetMetaData::class)(context: $context, id: $id, opts: $opts);

        if ($response->isSuccessful()) {
            return $response->response;
        }

        throw new RuntimeException(message: $response->error->format(), previous: $response->error->previous);
    }

    /**
     * Retrieves the parent of an episode from the cache or makes a request to obtain it.
     *
     * @param Context $context The context object.
     * @param iGuid $guid The guid object.
     * @param int|string $id The id of the episode.
     * @param array $logContext Additional log context (optional).
     *
     * @return array The parent of the episode as an array.
     * @throws RuntimeException When API call was not successful.
     */
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

        if (false === $guid->has(guids: $providersId, context: $logContext)) {
            $context->cache->set($cacheKey, []);
            return [];
        }

        $context->cache->set(
            $cacheKey,
            Guid::fromArray(
                payload: $guid->get(guids: $providersId, context: $logContext),
                context: [
                    'backend' => $context->backendName,
                    ...$logContext,
                ]
            )->getAll()
        );

        return $context->cache->get($cacheKey);
    }

    /**
     * Retrieves the backend libraries from the cache or makes a request to obtain them.
     *
     * @param Context $context The context object.
     * @param array $opts (optional) options.
     *
     * @return array The backend libraries as an associative array, where the key is the library ID and the value is the raw library data.
     * @throws RuntimeException When the API call was not successful.
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

    /**
     * Get A web URL for the specified item.
     *
     * @param Context $context The backend context.
     * @param string $type The item type.
     * @param string|int $id The item ID.
     *
     * @return iUri The web URL.
     * @throws RuntimeException
     */
    protected function getWebUrl(Context $context, string $type, string|int $id): iUri
    {
        $response = Container::get(GetWebUrl::class)(context: $context, type: $type, id: $id);

        if ($response->hasError()) {
            throw new RuntimeException(message: $response->error->format(), previous: $response->error->previous);
        }

        return $response->response;
    }

    /**
     * Check if the content type is supported WatchState.
     *
     * @param string $type The type to check.
     *
     * @return bool Returns true if the type is supported.
     */
    protected function isSupportedType(string $type): bool
    {
        return in_array(
            JellyfinClient::TYPE_MAPPER[$type] ?? JellyfinClient::TYPE_MAPPER[strtolower($type)] ?? $type,
            iState::TYPES_LIST,
            true
        );
    }
}
