<?php

declare(strict_types=1);

namespace App\Backends\Jellyfin\Action;

use App\Backends\Common\Context;
use App\Backends\Common\GuidInterface as iGuid;
use App\Backends\Jellyfin\JellyfinClient as JFC;
use App\Libs\Entity\StateInterface as iState;
use App\Libs\Exceptions\Backends\InvalidArgumentException;
use App\Libs\Mappers\ImportInterface as iImport;
use App\Libs\Options;
use Psr\Http\Message\StreamInterface;
use Throwable;

/**
 * Class Backup
 *
 * This class is responsible for performing backup operations on Jellyfin backend.
 *
 * @extends Import
 */
class Backup extends Import
{
    private const int JSON_FLAGS = JSON_INVALID_UTF8_IGNORE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

    /**
     * @var string Action name.
     */
    protected string $action = 'jellyfin.backup';

    /**
     * Process given item.
     *
     * @param Context $context The context object
     * @param iGuid $guid The GUID object
     * @param iImport $mapper The import object
     * @param array $item The item to process
     * @param array $logContext The log context (optional)
     * @param array $opts The options (optional)
     */
    protected function process(
        Context $context,
        iGuid $guid,
        iImport $mapper,
        array $item,
        array $logContext = [],
        array $opts = [],
    ): void {
        if (JFC::TYPE_SHOW === ($type = ag($item, 'Type'))) {
            $this->processShow(context: $context, guid: $guid, item: $item, logContext: $logContext);
            return;
        }

        $writer = ag($opts, 'writer');

        try {
            if ($context->trace) {
                $this->logger->debug('Processing [{backend}] payload.', [
                    'backend' => $context->backendName,
                    ...$logContext,
                    'payload' => $item,
                ]);
            }

            try {
                $logContext['item'] = [
                    'backend' => $context->backendName,
                    'id' => ag($item, 'Id'),
                    'title' => match ($type) {
                        JFC::TYPE_MOVIE => r('{title} ({year})', [
                            'title' => ag($item, ['Name', 'OriginalTitle'], '??'),
                            'year' => ag($item, 'ProductionYear', '0000'),
                        ]),
                        JFC::TYPE_EPISODE => r('{title} - ({season}x{episode})', [
                            'title' => ag($item, 'SeriesName', '??'),
                            'season' => str_pad((string)ag($item, 'ParentIndexNumber', 0), 2, '0', STR_PAD_LEFT),
                            'episode' => str_pad((string)ag($item, 'IndexNumber', 0), 3, '0', STR_PAD_LEFT),
                        ]),
                        default => throw new InvalidArgumentException(
                            r('Unexpected Content type [{type}] was received.', [
                                'type' => $type
                            ])
                        ),
                    },
                    'type' => $type,
                ];
            } catch (InvalidArgumentException $e) {
                $this->logger->info($e->getMessage(), [
                    'backend' => $context->backendName,
                    ...$logContext,
                    'body' => $item,
                ]);
                return;
            }

            $entity = $this->createEntity(
                context: $context,
                guid: $guid,
                item: $item,
                opts: $opts + ['library' => ag($logContext, 'library.id')]
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

            if ($entity->hasPlayProgress()) {
                $arr[iState::COLUMN_META_DATA_PROGRESS] = $entity->getPlayProgress();
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

            if (($writer instanceof StreamInterface) && false === (bool)ag($opts, Options::DRY_RUN, false)) {
                $writer->write(PHP_EOL . json_encode($arr, self::JSON_FLAGS) . ',');
            }
        } catch (Throwable $e) {
            $this->logger->error(
                message: 'Exception [{error.kind}] was thrown unhandled during [{client}: {backend}] backup. Error [{error.message} @ {error.file}:{error.line}].',
                context: [
                    'backend' => $context->backendName,
                    'client' => $context->clientName,
                    'error' => [
                        'kind' => $e::class,
                        'line' => $e->getLine(),
                        'message' => $e->getMessage(),
                        'file' => after($e->getFile(), ROOT_PATH),
                    ],
                    ...$logContext,
                    'exception' => [
                        'file' => $e->getFile(),
                        'line' => $e->getLine(),
                        'kind' => get_class($e),
                        'message' => $e->getMessage(),
                        'trace' => $e->getTrace(),
                    ],
                ]
            );
        }
    }
}
