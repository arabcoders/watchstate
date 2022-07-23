<?php

declare(strict_types=1);

namespace App\Backends\Plex;

use App\Backends\Common\Context;
use App\Backends\Common\GuidInterface as iGuid;
use App\Libs\Guid;
use Psr\Log\LoggerInterface;
use Throwable;

final class PlexGuid implements iGuid
{
    private const GUID_MAPPER = [
        'imdb' => Guid::GUID_IMDB,
        'tmdb' => Guid::GUID_TMDB,
        'tvdb' => Guid::GUID_TVDB,
        'tvmaze' => Guid::GUID_TVMAZE,
        'tvrage' => Guid::GUID_TVRAGE,
        'anidb' => Guid::GUID_ANIDB,
    ];

    private const GUID_LEGACY = [
        'com.plexapp.agents.imdb',
        'com.plexapp.agents.tmdb',
        'com.plexapp.agents.themoviedb',
        'com.plexapp.agents.xbmcnfo',
        'com.plexapp.agents.xbmcnfotv',
        'com.plexapp.agents.thetvdb',
        'com.plexapp.agents.hama',
    ];

    private const GUID_LOCAL = [
        'plex',
        'local',
        'com.plexapp.agents.none',
    ];

    private const GUID_LEGACY_REPLACER = [
        'com.plexapp.agents.themoviedb://' => 'com.plexapp.agents.tmdb://',
        'com.plexapp.agents.xbmcnfotv://' => 'com.plexapp.agents.tvdb://',
        'com.plexapp.agents.thetvdb://' => 'com.plexapp.agents.tvdb://',
        // -- imdb ids usually starts with tt(number)..
        'com.plexapp.agents.xbmcnfo://tt' => 'com.plexapp.agents.imdb://tt',
        // -- otherwise fallback to tmdb.
        'com.plexapp.agents.xbmcnfo://' => 'com.plexapp.agents.tmdb://',
    ];

    private Context|null $context = null;

    /**
     * Class to handle Plex external ids Parsing.
     *
     * @param LoggerInterface $logger
     */
    public function __construct(protected LoggerInterface $logger)
    {
    }

    public function withContext(Context $context): self
    {
        $cloned = clone $this;
        $cloned->context = $context;

        return $cloned;
    }

    public function parse(array $guids, array $context = []): array
    {
        return $this->ListExternalIds(guids: $guids, context: $context, log: false);
    }

    public function get(array $guids, array $context = []): array
    {
        return $this->ListExternalIds(guids: $guids, context: $context, log: true);
    }

    public function has(array $guids, array $context = []): bool
    {
        return count($this->ListExternalIds(guids: $guids, context: $context, log: false)) >= 1;
    }

    /**
     * Is the given identifier a local plex id?
     *
     * @param string $guid
     *
     * @return bool
     */
    public function isLocal(string $guid): bool
    {
        return true === in_array(before(strtolower($guid), '://'), self::GUID_LOCAL);
    }

    /**
     * List Supported External Ids.
     *
     * @param array $guids
     * @param array $context
     * @param bool $log Log errors. default true.
     * @return array
     */
    private function ListExternalIds(array $guids, array $context = [], bool $log = true): array
    {
        $guid = [];

        $id = ag($context, 'item.id', null);
        $type = ag($context, 'item.type', '??');
        $type = PlexClient::TYPE_MAPPER[$type] ?? $type;

        foreach (array_column($guids, 'id') as $val) {
            try {
                if (empty($val)) {
                    continue;
                }

                if (true === str_starts_with($val, 'com.plexapp.agents.')) {
                    // -- DO NOT accept plex relative unique ids, we generate our own.
                    if (substr_count($val, '/') >= 3) {
                        continue;
                    }
                    $val = $this->parseLegacyAgent(guid: $val, context: $context, log: $log);
                }

                if (false === str_contains($val, '://')) {
                    if (true === $log) {
                        $this->logger->info(
                            'Unable to parse [%(backend)] [%(agent)] identifier.',
                            [
                                'backend' => $this->context->backendName,
                                'agent' => $val ?? null,
                                ...$context
                            ]
                        );
                    }
                    continue;
                }

                [$key, $value] = explode('://', $val);
                $key = strtolower($key);

                if (null === (self::GUID_MAPPER[$key] ?? null) || empty($value)) {
                    continue;
                }

                if (true === isIgnoredId($this->context->backendName, $type, $key, $value, $id)) {
                    if (true === $log) {
                        $this->logger->debug(
                            'Ignoring [%(backend)] external id [%(source)] for %(item.type) [%(item.title)] as requested.',
                            [
                                'backend' => $this->context->backendName,
                                'source' => $val,
                                'guid' => [
                                    'source' => $key,
                                    'value' => $value,
                                ],
                                ...$context
                            ]
                        );
                    }
                    continue;
                }

                // -- Plex in their infinite wisdom, sometimes report two keys for same data source.
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
                            'agent' => $val ?? null,
                            'exception' => [
                                'file' => $e->getFile(),
                                'line' => $e->getLine(),
                                'kind' => get_class($e),
                                'message' => $e->getMessage(),
                                'trace' => $this->context->trace ? $e->getTrace() : [],
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

    /**
     * Parse legacy plex agents.
     *
     * @param string $guid
     * @param array $context
     * @param bool $log Log errors. default true.
     *
     * @return string
     * @see https://github.com/ZeroQI/Hama.bundle/issues/510
     */
    private function parseLegacyAgent(string $guid, array $context = [], bool $log = true): string
    {
        if (false === in_array(before($guid, '://'), self::GUID_LEGACY)) {
            return $guid;
        }

        try {
            // -- Handle hama plex agent. This is multi source agent.
            if (true === str_starts_with($guid, 'com.plexapp.agents.hama')) {
                $hamaRegex = '/(?P<source>(anidb|tvdb|tmdb|tsdb|imdb))\d?-(?P<id>[^\[\]]*)/';

                if (1 !== preg_match($hamaRegex, after($guid, '://'), $matches)) {
                    return $guid;
                }

                if (null === ($source = ag($matches, 'source')) || null === ($sourceId = ag($matches, 'id'))) {
                    return $guid;
                }

                return str_replace('tsdb', 'tmdb', $source) . '://' . before($sourceId, '?');
            }

            $guid = strtr($guid, self::GUID_LEGACY_REPLACER);

            $agentGuid = explode('://', after($guid, 'agents.'));

            return $agentGuid[0] . '://' . before($agentGuid[1], '?');
        } catch (Throwable $e) {
            if (true === $log) {
                $this->logger->error(
                    'Unhandled exception was thrown in parsing of [%(backend)] legacy agent [%(agent)] identifier.',
                    [
                        'backend' => $this->context->backendName,
                        'agent' => $guid,
                        'exception' => [
                            'file' => $e->getFile(),
                            'line' => $e->getLine(),
                            'kind' => get_class($e),
                            'message' => $e->getMessage(),
                            'trace' => $this->context->trace ? $e->getTrace() : [],
                        ],
                        ...$context,
                    ]
                );
            }
            return $guid;
        }
    }
}
