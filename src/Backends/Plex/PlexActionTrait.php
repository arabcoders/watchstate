<?php

declare(strict_types=1);

namespace App\Backends\Plex;

use App\Backends\Common\Context;
use App\Backends\Common\GuidInterface as iGuid;
use App\Backends\Common\Response;
use App\Backends\Plex\Action\GetLibrariesList;
use App\Backends\Plex\Action\GetMetaData;
use App\Backends\Plex\Action\GetWebUrl;
use App\Libs\Container;
use App\Libs\Entity\StateEntity;
use App\Libs\Entity\StateInterface as iState;
use App\Libs\Exceptions\Backends\InvalidArgumentException;
use App\Libs\Exceptions\Backends\RuntimeException;
use App\Libs\Guid;
use App\Libs\Options;
use Psr\Http\Message\UriInterface as iUri;

trait PlexActionTrait
{
    private array $typeMapper = [
        PlexClient::TYPE_SHOW => iState::TYPE_SHOW,
        PlexClient::TYPE_MOVIE => iState::TYPE_MOVIE,
        PlexClient::TYPE_EPISODE => iState::TYPE_EPISODE,
    ];

    /**
     * Create {@see StateEntity} Object based on given data.
     *
     * @param Context $context
     * @param iGuid $guid
     * @param array $item Plex API item.
     * @param array $opts options
     *
     * @return iState Return object on successful creation.
     * @throws InvalidArgumentException if no date was set on object.
     * @throws RuntimeException if request failed.
     */
    protected function createEntity(Context $context, iGuid $guid, array $item, array $opts = []): iState
    {
        // -- Handle watched/updated column in a special way to support mark as unplayed.
        if (null !== ($opts['override'][iState::COLUMN_WATCHED] ?? null)) {
            $isPlayed = (bool)$opts['override'][iState::COLUMN_WATCHED];
            $date = $opts['override'][iState::COLUMN_UPDATED] ?? ag($item, 'addedAt');
        } else {
            $isPlayed = (bool)ag($item, 'viewCount', false);
            $date = ag($item, true === $isPlayed ? ['lastViewedAt', 'addedAt'] : 'addedAt');
        }

        // -- For Progress action we need to use the latest date.
        if (true === (bool)($opts['latest_date'] ?? false)) {
            $date = max(ag($item, 'lastViewedAt', 0), ag($item, 'addedAt', 0), ag($item, 'updatedAt', 0));
        }

        $type = (string)ag($item, 'type', '');
        $type = $this->typeMapper[$type] ?? $this->typeMapper[strtolower($type)] ?? $type;

        if (null === $date) {
            if (iState::TYPE_SHOW !== $type) {
                throw new InvalidArgumentException('No date was set on object');
            }
            $date = 0;
        }

        $year = (int)ag($item, ['grandParentYear', 'parentYear', 'year'], 0);
        if (0 === $year && null !== ($airDate = ag($item, 'originallyAvailableAt'))) {
            $year = (int)makeDate($airDate)->format('Y');
        }

        if (null === ag($item, 'Guid')) {
            $item['Guid'] = [['id' => ag($item, 'guid')]];
        } else {
            $item['Guid'][] = ['id' => ag($item, 'guid')];
        }

        $logContext = [
            'backend' => $context->backendName,
            'item' => [
                'id' => ag($item, 'ratingKey'),
                'type' => ag($item, 'type'),
                'title' => match ($type) {
                    iState::TYPE_MOVIE, iState::TYPE_SHOW => sprintf(
                        '%s (%s)',
                        ag($item, ['title', 'originalTitle'], '??'),
                        0 === $year ? '0000' : $year,
                    ),
                    iState::TYPE_EPISODE => sprintf(
                        '%s - (%sx%s)',
                        ag($item, ['grandparentTitle', 'originalTitle', 'title'], '??'),
                        str_pad((string)ag($item, 'parentIndex', 0), 2, '0', STR_PAD_LEFT),
                        str_pad((string)ag($item, 'index', 0), 3, '0', STR_PAD_LEFT),
                    ),
                    default => throw new InvalidArgumentException(
                        r("Unexpected Content type '{type}' was received.", [
                            'type' => $type
                        ])
                    ),
                },
                'year' => 0 === $year ? '0000' : $year,
                'plex_id' => str_starts_with(ag($item, 'guid', ''), 'plex://') ? ag($item, 'guid') : 'none',
            ],
        ];

        if (iState::TYPE_EPISODE === $type && true === (bool)ag($opts, Options::DISABLE_GUID, false)) {
            $guids = [];
        } else {
            $guids = $guid->get(guids: ag($item, 'Guid', []), context: $logContext);
        }

        $builder = [
            iState::COLUMN_TYPE => $type,
            iState::COLUMN_UPDATED => (int)$date,
            iState::COLUMN_WATCHED => (int)$isPlayed,
            iState::COLUMN_VIA => $context->backendName,
            iState::COLUMN_TITLE => ag($item, ['title', 'originalTitle'], '??'),
            iState::COLUMN_GUIDS => $guids,
            iState::COLUMN_META_DATA => [
                $context->backendName => [
                    iState::COLUMN_ID => (string)ag($item, 'ratingKey'),
                    iState::COLUMN_TYPE => $type,
                    iState::COLUMN_WATCHED => true === $isPlayed ? '1' : '0',
                    iState::COLUMN_VIA => $context->backendName,
                    iState::COLUMN_TITLE => ag($item, ['title', 'originalTitle'], '??'),
                    iState::COLUMN_GUIDS => $guid->parse(guids: ag($item, 'Guid', []), context: $logContext),
                    iState::COLUMN_META_DATA_RATING => (string)ag($item, 'userRating', '0'),
                    iState::COLUMN_META_DATA_ADDED_AT => (string)ag($item, 'addedAt'),
                ],
            ],
            iState::COLUMN_EXTRA => [],
        ];

        $metadata = &$builder[iState::COLUMN_META_DATA][$context->backendName];
        $metadataExtra = &$metadata[iState::COLUMN_META_DATA_EXTRA];

        if (null !== ($library = ag($item, 'librarySectionID', $opts[iState::COLUMN_META_LIBRARY] ?? null))) {
            $metadata[iState::COLUMN_META_LIBRARY] = (string)$library;
        }

        if (iState::TYPE_EPISODE === $type) {
            $builder[iState::COLUMN_SEASON] = (int)ag($item, 'parentIndex', 0);
            $builder[iState::COLUMN_EPISODE] = (int)ag($item, 'index', 0);

            $metadata[iState::COLUMN_META_SHOW] = (string)ag($item, ['grandparentRatingKey', 'parentRatingKey'], '??');

            $metadata[iState::COLUMN_TITLE] = ag($item, 'grandparentTitle', '??');
            $metadata[iState::COLUMN_SEASON] = (string)$builder[iState::COLUMN_SEASON];
            $metadata[iState::COLUMN_EPISODE] = (string)$builder[iState::COLUMN_EPISODE];

            $metadataExtra[iState::COLUMN_META_DATA_EXTRA_TITLE] = $builder[iState::COLUMN_TITLE];
            $builder[iState::COLUMN_TITLE] = $metadata[iState::COLUMN_TITLE];

            if (null !== ($parentId = ag($item, ['grandparentRatingKey', 'parentRatingKey']))) {
                $builder[iState::COLUMN_PARENT] = $this->getEpisodeParent(
                    context: $context,
                    guid: $guid,
                    id: $parentId
                );
                $metadata[iState::COLUMN_PARENT] = $builder[iState::COLUMN_PARENT];
            }
        }

        if (0 !== $year) {
            $builder[iState::COLUMN_YEAR] = (int)$year;
            $metadata[iState::COLUMN_YEAR] = (string)$year;
        }

        if (null !== ($mediaPath = ag($item, 'Media.0.Part.0.file')) && !empty($mediaPath)) {
            $metadata[iState::COLUMN_META_PATH] = (string)$mediaPath;
        }

        if (null === $mediaPath && null !== ($showPath = ag($item, 'Location.0.path')) && !empty($showPath)) {
            $metadata[iState::COLUMN_META_PATH] = (string)$showPath;
        }

        if (null !== ($PremieredAt = ag($item, 'originallyAvailableAt'))) {
            $metadataExtra[iState::COLUMN_META_DATA_EXTRA_DATE] = makeDate($PremieredAt)->format('Y-m-d');
        }

        if (true === $isPlayed) {
            $metadata[iState::COLUMN_META_DATA_PLAYED_AT] = (string)$date;
            $metadata[iState::COLUMN_META_DATA_PROGRESS] = "0";
        }

        if (false === $isPlayed && null !== ($progress = ag($item, 'viewOffset', null))) {
            // -- Plex reports play progress in milliseconds already no need to convert.
            $metadata[iState::COLUMN_META_DATA_PROGRESS] = (string)$progress;
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
     * @throws RuntimeException if request failed.
     */
    protected function getItemDetails(Context $context, string|int $id, array $opts = []): array
    {
        $response = $this->getItemInfo(context: $context, id: $id, opts: $opts);

        if ($response->isSuccessful()) {
            return $response->response;
        }

        throw new RuntimeException(message: $response->error->format(), previous: $response->error->previous);
    }

    /**
     * Get item details.
     *
     * @param Context $context
     * @param string|int $id
     * @param array $opts
     * @return Response
     */
    protected function getItemInfo(Context $context, string|int $id, array $opts = []): Response
    {
        return Container::get(GetMetaData::class)(context: $context, id: $id, opts: $opts);
    }

    /**
     * Get episode parent external ids.
     *
     * @param Context $context
     * @param iGuid $guid
     * @param int|string $id
     * @param array $logContext
     *
     * @return array
     * @throws RuntimeException
     */
    protected function getEpisodeParent(Context $context, iGuid $guid, int|string $id, array $logContext = []): array
    {
        $cacheKey = PlexClient::TYPE_SHOW . '.' . $id;

        if (true === $context->cache->has($cacheKey)) {
            return $context->cache->get($cacheKey);
        }

        $json = ag($this->getItemDetails(context: $context, id: $id), 'MediaContainer.Metadata.0', []);

        $year = (int)ag($json, ['grandParentYear', 'parentYear', 'year'], 0);
        if (0 === $year && null !== ($airDate = ag($json, 'originallyAvailableAt'))) {
            $year = (int)makeDate($airDate)->format('Y');
        }

        $logContext['item'] = [
            'id' => ag($json, 'ratingKey'),
            'title' => sprintf(
                '%s (%s)',
                ag($json, ['title', 'originalTitle'], '??'),
                0 === $year ? '0000' : $year,
            ),
            'year' => 0 === $year ? '0000' : $year,
            'type' => ag($json, 'type', 'unknown'),
        ];

        if (null === ($type = ag($json, 'type')) || PlexClient::TYPE_SHOW !== $type) {
            return [];
        }

        if (null === ($json['Guid'] ?? null)) {
            $json['Guid'] = [['id' => $json['guid']]];
        } else {
            $json['Guid'][] = ['id' => $json['guid']];
        }

        if (false === $guid->has(guids: $json['Guid'], context: $logContext)) {
            $context->cache->set($cacheKey, []);
            return [];
        }

        $gContext = ag_set(
            $logContext,
            'item.plex_id',
            str_starts_with(ag($json, 'guid', ''), 'plex://') ? ag($json, 'guid') : 'none'
        );

        $context->cache->set(
            $cacheKey,
            Guid::fromArray(
                payload: $guid->get(guids: $json['Guid'], context: [...$gContext]),
                context: ['backend' => $context->backendName, ...$logContext]
            )->getAll()
        );

        return $context->cache->get($cacheKey);
    }

    /**
     * Get Backend Libraries details.
     * @throws RuntimeException
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
            $arr[(int)$item['id']] = $item['raw'];
        }

        return $arr;
    }

    /**
     * Get A web URL for the specified item.
     *
     * @param Context $context The backend context.
     * @param string $type The item type.
     * @param int $id The item ID.
     *
     * @return iUri The web URL.
     * @throws RuntimeException
     */
    protected function getWebUrl(Context $context, string $type, int $id): iUri
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
        return true === in_array(
                PlexClient::TYPE_MAPPER[$type] ?? PlexClient::TYPE_MAPPER[strtolower($type)] ?? $type,
                iState::TYPES_LIST,
                true
            );
    }
}
