<?php

declare(strict_types=1);

namespace App\Backends\Jellyfin;

use App\Backends\Common\Context;
use App\Libs\Guid;
use Psr\Log\LoggerInterface;
use Throwable;

class JellyfinGuid
{
    private const GUID_MAPPER = [
        'imdb' => Guid::GUID_IMDB,
        'tmdb' => Guid::GUID_TMDB,
        'tvdb' => Guid::GUID_TVDB,
        'tvmaze' => Guid::GUID_TVMAZE,
        'tvrage' => Guid::GUID_TVRAGE,
        'anidb' => Guid::GUID_ANIDB,
    ];

    private Context|null $context = null;

    /**
     * Class to handle Jellyfin external ids Parsing.
     *
     * @param LoggerInterface $logger
     */
    public function __construct(protected LoggerInterface $logger)
    {
    }

    /**
     * Set working context.
     *
     * @param Context $context
     * @return $this a cloned version of the class will be returned.
     */
    public function withContext(Context $context): self
    {
        $cloned = clone $this;
        $cloned->context = $context;

        return $cloned;
    }

    /**
     * Parse external ids from given list in safe way.
     *
     * *DO NOT THROW OR LOG ANYTHING.*
     *
     * @param array $guids
     *
     * @return array
     */
    public function parse(array $guids): array
    {
        return $this->ListExternalIds(guids: $guids, log: false);
    }

    /**
     * Parse supported external ids from given list.
     *
     * @param array $guids
     * @param array $context
     * @return array
     */
    public function get(array $guids, array $context = []): array
    {
        return $this->ListExternalIds(guids: $guids, context: $context, log: true);
    }

    /**
     * Does the given list contain supported external ids?
     *
     * @param array $guids
     * @param array $context
     * @return bool
     */
    public function has(array $guids, array $context = []): bool
    {
        return count($this->ListExternalIds(guids: $guids, context: $context, log: false)) >= 1;
    }

    /**
     * Get All Supported external ids.
     *
     * @param array $guids
     * @param array $context
     * @param bool $log
     *
     * @return array
     */
    protected function ListExternalIds(array $guids, array $context = [], bool $log = true): array
    {
        $guid = [];

        foreach (array_change_key_case($guids, CASE_LOWER) as $key => $value) {
            if (null === (self::GUID_MAPPER[$key] ?? null) || empty($value)) {
                continue;
            }

            try {
                if (null !== ($guid[self::GUID_MAPPER[$key]] ?? null)) {
                    if (true === $log) {
                        $this->logger->info(
                            '[%(backend)] reported multiple ids for same data source [%(key): %(ids)] for %(item.type) [%(item.title)].',
                            [
                                'backend' => $this->context->backendName,
                                'key' => $key,
                                'ids' => sprintf('%s, %s', $guid[self::GUID_MAPPER[$key]], $value),
                                ...$context
                            ]
                        );
                    }

                    if (false === ctype_digit($value)) {
                        continue;
                    }

                    if ((int)$guid[self::GUID_MAPPER[$key]] < (int)$value) {
                        continue;
                    }
                }

                $guid[self::GUID_MAPPER[$key]] = $value;
            } catch (Throwable $e) {
                if (true === $log) {
                    $this->logger->error(
                        'Unhandled exception was thrown in parsing of [%(backend)] [%(agent)] identifier.',
                        [
                            'backend' => $this->context->backendName,
                            'agent' => $value,
                            'exception' => [
                                'file' => $e->getFile(),
                                'line' => $e->getLine(),
                                'kind' => get_class($e),
                                'message' => $e->getMessage(),
                            ],
                            ...$context,
                        ]
                    );
                }
                continue;
            }
        }

        ksort($guid);

        return $guid;
    }
}
