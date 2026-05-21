<?php

declare(strict_types=1);

namespace App\Backends\Plex\Action;

use App\Backends\Common\Context;
use App\Backends\Common\GuidInterface as iGuid;
use App\Backends\Plex\PlexClient;
use App\Libs\Entity\StateInterface as iState;
use App\Libs\Exceptions\Backends\InvalidArgumentException;
use App\Libs\Mappers\ImportInterface as iImport;
use App\Libs\Options;
use Psr\Http\Message\StreamInterface;
use Throwable;

final class Backup extends Import
{
    protected string $action = 'plex.backup';

    private const int JSON_FLAGS = JSON_INVALID_UTF8_IGNORE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

    protected function process(
        Context $context,
        iGuid $guid,
        iImport $mapper,
        array $item,
        array $logContext = [],
        array $opts = [],
    ): void {
        if (PlexClient::TYPE_SHOW === ($type = ag($item, 'type'))) {
            $this->processShow(context: $context, guid: $guid, item: $item, logContext: $logContext);
            return;
        }

        $writer = ag($opts, 'writer');

        try {
            if ($context->trace) {
                $this->logger->debug('Parsing backup payload from \'{backend}\'.', [
                    'event_name' => 'backend.response.received',
                    'subsystem' => 'backend.backup',
                    'operation' => 'process_item',
                    'outcome' => 'received',
                    ...$logContext,
                    'response' => [
                        'body' => $item,
                    ],
                ]);
            }

            $year = (int) ag($item, ['grandParentYear', 'parentYear', 'year'], 0);
            if (0 === $year && null !== ($airDate = ag($item, 'originallyAvailableAt'))) {
                $year = (int) make_date($airDate)->format('Y');
            }

            try {
                $logContext['item'] = [
                    'backend' => $context->backendName,
                    'id' => ag($item, 'ratingKey'),
                    'title' => match ($type) {
                        PlexClient::TYPE_MOVIE => r('{title} ({year})', [
                            'title' => ag($item, ['title', 'originalTitle'], '??'),
                            'year' => 0 === $year ? '0000' : $year,
                        ]),
                        PlexClient::TYPE_EPISODE => r('{title} - ({season}x{episode})', [
                            'title' => ag($item, ['grandparentTitle', 'originalTitle', 'title'], '??'),
                            'season' => str_pad((string) ag($item, 'parentIndex', 0), 2, '0', STR_PAD_LEFT),
                            'episode' => str_pad((string) ag($item, 'index', 0), 3, '0', STR_PAD_LEFT),
                        ]),
                        default => throw new InvalidArgumentException(
                            r("Unexpected Content type '{type}' was received.", [
                                'type' => $type,
                            ]),
                        ),
                    },
                    'type' => $type,
                ];
            } catch (InvalidArgumentException $e) {
                $this->logger->info(
                    message: "Ignoring backup item from '{backend}': payload could not be parsed.",
                    context: [
                        'event_name' => 'backend.item.ignored',
                        'subsystem' => 'backend.backup',
                        'operation' => 'parse_item',
                        'outcome' => 'ignored',
                        'reason' => 'invalid_item_payload',
                        ...$logContext,
                        'response' => [
                            'body' => $item,
                        ],
                        ...exception_log($e),
                    ],
                );
                return;
            }

            $entity = $this->createEntity(
                context: $context,
                guid: $guid,
                item: $item,
                opts: array_replace_recursive($opts, [
                    'override' => [
                        iState::COLUMN_EXTRA => [
                            $context->backendName => [
                                iState::COLUMN_EXTRA_EVENT => 'task.backup',
                                iState::COLUMN_EXTRA_DATE => make_date('now'),
                            ],
                        ],
                    ],
                ]),
            );

            $arr = [
                iState::COLUMN_TYPE => $entity->type,
                iState::COLUMN_WATCHED => (int) $entity->isWatched(),
                iState::COLUMN_UPDATED => make_date($entity->updated)->getTimestamp(),
                iState::COLUMN_META_SHOW => '',
                iState::COLUMN_TITLE => trim($entity->title),
            ];

            if ($entity->isEpisode()) {
                $arr[iState::COLUMN_META_SHOW] = trim($entity->title);
                $arr[iState::COLUMN_TITLE] = trim(
                    ag(
                        $entity->getMetadata($entity->via),
                        iState::COLUMN_META_DATA_EXTRA . '.' . iState::COLUMN_META_DATA_EXTRA_TITLE,
                        $entity->season . 'x' . $entity->episode,
                    ),
                );
                $arr[iState::COLUMN_SEASON] = $entity->season;
                $arr[iState::COLUMN_EPISODE] = $entity->episode;
            } else {
                unset($arr[iState::COLUMN_META_SHOW]);
            }

            $arr[iState::COLUMN_YEAR] = $entity->year;

            $arr[iState::COLUMN_GUIDS] = array_filter(
                $entity->getGuids(),
                static fn($key) => str_contains($key, 'guid_'),
                ARRAY_FILTER_USE_KEY,
            );
            if ($entity->isEpisode()) {
                $arr[iState::COLUMN_PARENT] = array_filter(
                    $entity->getParentGuids(),
                    static fn($key) => str_contains($key, 'guid_'),
                    ARRAY_FILTER_USE_KEY,
                );
            }

            if ($entity->hasPlayProgress()) {
                $arr[iState::COLUMN_META_DATA_PROGRESS] = $entity->getPlayProgress();
            }

            if (true !== (bool) ag($opts, 'no_enhance') && null !== ($fromDb = $mapper->get($entity))) {
                $arr[iState::COLUMN_GUIDS] = array_replace_recursive(
                    array_filter(
                        $fromDb->getGuids(),
                        static fn($key) => str_contains($key, 'guid_'),
                        ARRAY_FILTER_USE_KEY,
                    ),
                    $arr[iState::COLUMN_GUIDS],
                );
                if ($entity->isEpisode()) {
                    $arr[iState::COLUMN_PARENT] = array_replace_recursive(
                        array_filter(
                            $fromDb->getParentGuids(),
                            static fn($key) => str_contains($key, 'guid_'),
                            ARRAY_FILTER_USE_KEY,
                        ),
                        $arr[iState::COLUMN_PARENT],
                    );
                }
            }

            if ($writer instanceof StreamInterface && false === (bool) ag($opts, Options::DRY_RUN, false)) {
                $writer->write(PHP_EOL . json_encode($arr, self::JSON_FLAGS) . ',');
            }
        } catch (Throwable $e) {
            $this->logger->error(
                ...lw(
                    message: "Failed to back up {item.type} '{item.title}' from '{backend}'.",
                    context: [
                        'event_name' => 'backend.operation.failed',
                        'subsystem' => 'backend.backup',
                        'operation' => 'process_item',
                        'outcome' => 'failed',
                        ...$logContext,
                        ...exception_log($e),
                    ],
                    e: $e,
                ),
            );
        }
    }
}
