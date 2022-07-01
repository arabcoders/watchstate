<?php

declare(strict_types=1);

namespace App\Backends\Plex\Action;

use App\Backends\Common\Context;
use App\Backends\Common\GuidInterface as iGuid;
use App\Backends\Plex\PlexClient;
use App\Libs\Entity\StateInterface as iState;
use App\Libs\Mappers\ImportInterface as iImport;
use SplFileObject;
use Throwable;

final class Backup extends Import
{
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

        if (null === ($writer = ag($opts, 'writer'))) {
            throw new \RuntimeException('No writer was found.');
        }

        if (false === ($writer instanceof SplFileObject)) {
            throw new \RuntimeException('Writer is not SplFileObject.');
        }

        try {
            $year = (int)ag($item, ['grandParentYear', 'parentYear', 'year'], 0);
            if (0 === $year && null !== ($airDate = ag($item, 'originallyAvailableAt'))) {
                $year = (int)makeDate($airDate)->format('Y');
            }

            $logContext['item'] = [
                'backend' => $context->backendName,
                'id' => ag($item, 'ratingKey'),
                'title' => match ($type) {
                    PlexClient::TYPE_MOVIE => sprintf(
                        '%s (%s)',
                        ag($item, ['title', 'originalTitle'], '??'),
                        0 === $year ? '0000' : $year,
                    ),
                    PlexClient::TYPE_EPISODE => sprintf(
                        '%s - (%sx%s)',
                        ag($item, ['grandparentTitle', 'originalTitle', 'title'], '??'),
                        str_pad((string)ag($item, 'parentIndex', 0), 2, '0', STR_PAD_LEFT),
                        str_pad((string)ag($item, 'index', 0), 3, '0', STR_PAD_LEFT),
                    ),
                },
                'type' => $type,
            ];

            if ($context->trace) {
                $this->logger->debug('Processing [%(backend)] %(item.type) [%(item.title)] payload.', [
                    'backend' => $context->backendName,
                    ...$logContext,
                    'payload' => $item,
                ]);
            }

            $entity = $this->createEntity(
                context: $context,
                guid:    $guid,
                item:    $item,
                opts:    $opts
            );

            $arr = [
                iState::COLUMN_TYPE => $entity->type,
                iState::COLUMN_WATCHED => (int)$entity->isWatched(),
                iState::COLUMN_UPDATED => makeDate($entity->updated)->getTimestamp(),
                iState::COLUMN_META_SHOW => '',
                iState::COLUMN_TITLE => trim($entity->title),
            ];

            if ($entity->isEpisode()) {
                $arr[iState::COLUMN_META_SHOW] = trim($entity->title);
                $arr[iState::COLUMN_TITLE] = trim(
                    ag(
                        $entity->getMetadata($entity->via),
                        iState::COLUMN_META_DATA_EXTRA . '.' .
                        iState::COLUMN_META_DATA_EXTRA_TITLE,
                        $entity->season . 'x' . $entity->episode,
                    )
                );
                $arr[iState::COLUMN_SEASON] = $entity->season;
                $arr[iState::COLUMN_EPISODE] = $entity->episode;
            } else {
                unset($arr[iState::COLUMN_META_SHOW]);
            }

            $arr[iState::COLUMN_YEAR] = $entity->year;

            $arr[iState::COLUMN_GUIDS] = array_filter(
                $entity->getGuids(),
                fn($key) => str_contains($key, 'guid_'),
                ARRAY_FILTER_USE_KEY
            );
            if ($entity->isEpisode()) {
                $arr[iState::COLUMN_PARENT] = array_filter(
                    $entity->getParentGuids(),
                    fn($key) => str_contains($key, 'guid_'),
                    ARRAY_FILTER_USE_KEY
                );
            }

            if (true !== (bool)ag($opts, 'no_enhance') && null !== ($fromDb = $mapper->get($entity))) {
                $arr[iState::COLUMN_GUIDS] = array_replace_recursive(
                    array_filter(
                        $fromDb->getGuids(),
                        fn($key) => str_contains($key, 'guid_'),
                        ARRAY_FILTER_USE_KEY
                    ),
                    $arr[iState::COLUMN_GUIDS]
                );
                if ($entity->isEpisode()) {
                    $arr[iState::COLUMN_PARENT] = array_replace_recursive(
                        array_filter(
                            $fromDb->getParentGuids(),
                            fn($key) => str_contains($key, 'guid_'),
                            ARRAY_FILTER_USE_KEY
                        ),
                        $arr[iState::COLUMN_PARENT]
                    );
                }
            }

            $writer->fwrite(
                PHP_EOL . json_encode(
                    $arr,
                    JSON_INVALID_UTF8_IGNORE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
                ) . ','
            );
        } catch (Throwable $e) {
            $this->logger->error(
                'Unhandled exception was thrown during handling of [%(backend)] [%(library.title)] [%(item.title)] backup.',
                [
                    'backend' => $context->backendName,
                    ...$logContext,
                    'exception' => [
                        'file' => $e->getFile(),
                        'line' => $e->getLine(),
                        'kind' => get_class($e),
                        'message' => $e->getMessage(),
                        'trace' => $context->trace ? $e->getTrace() : [],
                    ],
                ]
            );
        }
    }
}
