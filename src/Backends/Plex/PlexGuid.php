<?php

declare(strict_types=1);

namespace App\Backends\Plex;

use App\Backends\Common\Context;
use App\Backends\Common\GuidInterface as iGuid;
use App\Libs\Config;
use App\Libs\Exceptions\Backends\InvalidArgumentException;
use App\Libs\Guid;
use Psr\Log\LoggerInterface as iLogger;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;
use Throwable;

final class PlexGuid implements iGuid
{
    /**
     * @var array<string,string> Map plex guids to our guids.
     */
    private array $guidMapper = [
        'imdb' => Guid::GUID_IMDB,
        'tmdb' => Guid::GUID_TMDB,
        'tvdb' => Guid::GUID_TVDB,
        'tvmaze' => Guid::GUID_TVMAZE,
        'tvrage' => Guid::GUID_TVRAGE,
        'anidb' => Guid::GUID_ANIDB,
        'ytinforeader' => Guid::GUID_YOUTUBE,
        'cmdb' => Guid::GUID_CMDB,
    ];

    /**
     * @var array<array-key,string> List of legacy plex agents.
     */
    private array $guidLegacy = [
        'com.plexapp.agents.imdb',
        'com.plexapp.agents.tmdb',
        'com.plexapp.agents.themoviedb',
        'com.plexapp.agents.xbmcnfo',
        'com.plexapp.agents.xbmcnfotv',
        'com.plexapp.agents.thetvdb',
        'com.plexapp.agents.hama',
        'com.plexapp.agents.ytinforeader',
        'com.plexapp.agents.cmdb',
    ];

    /**
     * @var array<array-key,string> List of local plex agents.
     */
    private array $guidLocal = [
        'plex',
        'local',
        'com.plexapp.agents.none',
        'tv.plex.agents.none',
    ];

    /**
     * @var array<string,string> Map guids to their replacement.
     */
    private array $guidReplacer = [
        'com.plexapp.agents.themoviedb://' => 'com.plexapp.agents.tmdb://',
        'com.plexapp.agents.xbmcnfotv://' => 'com.plexapp.agents.tvdb://',
        'com.plexapp.agents.thetvdb://' => 'com.plexapp.agents.tvdb://',
        // -- imdb ids usually starts with tt(number)..
        'com.plexapp.agents.xbmcnfo://tt' => 'com.plexapp.agents.imdb://tt',
        // -- otherwise fallback to tmdb.
        'com.plexapp.agents.xbmcnfo://' => 'com.plexapp.agents.tmdb://',
    ];

    /**
     * @var Context|null Backend context.
     */
    private ?Context $context = null;

    /**
     * Class constructor.
     *
     * @param iLogger $logger Logger instance.
     */
    public function __construct(
        private readonly iLogger $logger,
    ) {
        $file = Config::get('guid.file', null);

        try {
            if (null !== $file && true === file_exists($file)) {
                $this->parseGUIDFile($file);
            }
        } catch (Throwable $e) {
            $this->logger->error("Failed to read or parse '{guid}' file. Error '{error}'.", [
                'guid' => $file,
                'error' => $e->getMessage(),
                'exception' => [
                    'message' => $e->getMessage(),
                    'code' => $e->getCode(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'trace' => $e->getTrace(),
                ],
            ]);
        }
    }

    /**
     * Extends WatchState GUID parsing to include external GUIDs.
     *
     * @param string $file The path to the external GUID mapping file.
     *
     * @throws InvalidArgumentException if the file does not exist or is not readable.
     * @throws InvalidArgumentException if the GUIDs file cannot be parsed.
     * @throws InvalidArgumentException if the file version is not supported.
     */
    public function parseGUIDFile(string $file): void
    {
        if (false === file_exists($file) || false === is_readable($file)) {
            throw new InvalidArgumentException(r("The file '{file}' does not exist or is not readable.", [
                'file' => $file,
            ]));
        }

        if (filesize($file) < 1) {
            $this->logger->info("The external GUID mapping file '{file}' is empty.", ['file' => $file]);
            return;
        }

        try {
            $yaml = Yaml::parseFile($file);
            if (false === is_array($yaml)) {
                throw new InvalidArgumentException(r("The GUIDs file '{file}' is not an array.", [
                    'file' => $file,
                ]));
            }
        } catch (ParseException $e) {
            throw new InvalidArgumentException(
                r("Failed to parse GUIDs file. Error '{error}'.", [
                    'error' => $e->getMessage(),
                ]),
                code: (int) $e->getCode(),
                previous: $e,
            );
        }

        $supported = array_keys(Guid::getSupported());
        $supportedVersion = Config::get('guid.version', '0.0');
        $guidVersion = (string) ag($yaml, 'version', $supportedVersion);

        if (true === version_compare($supportedVersion, $guidVersion, '<')) {
            throw new InvalidArgumentException(r("Unsupported file version '{version}'. Expecting '{supported}'.", [
                'version' => $guidVersion,
                'supported' => $supportedVersion,
            ]));
        }

        $mapping = ag($yaml, 'links', []);

        if (false === is_array($mapping)) {
            throw new InvalidArgumentException(r("The GUIDs file '{file}' links sub key is not an array.", [
                'file' => $file,
            ]));
        }

        if (count($mapping) < 1) {
            return;
        }

        $type = strtolower(PlexClient::CLIENT_NAME);

        foreach ($mapping as $key => $map) {
            if (false === is_array($map)) {
                $this->logger->warning("Ignoring 'links.{key}'. Value must be an object. '{given}' is given.", [
                    'key' => $key,
                    'given' => get_debug_type($map),
                ]);
                continue;
            }

            if ($type !== ag($map, 'type', 'not_set')) {
                continue;
            }

            if (null !== ($replace = ag($map, 'options.replace', null))) {
                if (false === is_array($replace)) {
                    $this->logger->warning(
                        "Ignoring 'links.{key}'. options.replace value must be an object. '{given}' is given.",
                        [
                            'key' => $key,
                            'given' => get_debug_type($replace),
                        ],
                    );
                    continue;
                }

                $from = ag($replace, 'from', null);
                $to = ag($replace, 'to', null);

                if (empty($from) || false === is_string($from)) {
                    $this->logger->warning(
                        "Ignoring 'links.{key}'. options.replace.from field is empty or not a string.",
                        [
                            'key' => $key,
                        ],
                    );
                    continue;
                }

                if (false === is_string($to)) {
                    $this->logger->warning("Ignoring 'links.{key}'. options.replace.to field is not a string.", [
                        'key' => $key,
                    ]);
                    continue;
                }

                $this->guidReplacer[$from] = $to;
            }

            if (null !== ($mapper = ag($map, 'map', null))) {
                if (false === is_array($mapper)) {
                    $this->logger->warning("Ignoring 'links.{key}'. map value must be an object. '{given}' is given.", [
                        'key' => $key,
                        'given' => get_debug_type($mapper),
                    ]);
                    continue;
                }

                $from = ag($mapper, 'from', null);
                $to = ag($mapper, 'to', null);

                if (empty($from) || false === is_string($from)) {
                    $this->logger->warning("Ignoring 'links.{key}'. map.from field is empty or not a string.", [
                        'key' => $key,
                    ]);
                    continue;
                }

                if (empty($to) || false === is_string($to)) {
                    $this->logger->warning("Ignoring 'links.{key}'. map.to field is empty or not a string.", [
                        'key' => $key,
                    ]);
                    continue;
                }

                if (false === str_starts_with($to, 'guid_')) {
                    $this->logger->warning(
                        "Ignoring 'links.{key}'. map.to '{to}' field does not starts with 'guid_'.",
                        [
                            'key' => $key,
                            'to' => $to,
                        ],
                    );
                    continue;
                }

                if (false === in_array($to, $supported, true)) {
                    $this->logger->warning("Ignoring 'links.{key}'. map.to field is not a supported GUID type.", [
                        'key' => $key,
                        'to' => $to,
                    ]);
                    continue;
                }

                if (false === (bool) ag($map, 'options.legacy', true)) {
                    $this->guidMapper[$from] = $to;
                    continue;
                }

                if (true === in_array($from, $this->guidLegacy, true)) {
                    $this->logger->warning("Ignoring 'links.{key}'. map.from already exists.", [
                        'key' => $key,
                        'from' => $from,
                    ]);
                    continue;
                }

                $this->guidLegacy[] = $from;
                $agentGuid = explode('://', after($from, 'agents.'));
                $this->guidMapper[$agentGuid[0]] = $to;
            }
        }
    }

    /**
     * @inheritdoc
     */
    public function withContext(Context $context): self
    {
        $cloned = clone $this;
        $cloned->context = $context;

        return $cloned;
    }

    /**
     * @inheritdoc
     */
    public function parse(array $guids, array $context = []): array
    {
        return $this->ListExternalIds(guids: $guids, context: $context, log: false);
    }

    /**
     * @inheritdoc
     */
    public function get(array $guids, array $context = []): array
    {
        return $this->ListExternalIds(guids: $guids, context: $context, log: true);
    }

    /**
     * @inheritdoc
     */
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
        return true === in_array(before(strtolower($guid), '://'), $this->guidLocal, true);
    }

    /**
     * List supported external ids.
     *
     * @param array $guids List of guids.
     * @param array $context Context data.
     * @param bool $log Log errors. default true.
     *
     * @return array List of external ids.
     */
    private function ListExternalIds(array $guids, array $context = [], bool $log = true): array
    {
        $guid = [];

        $id = ag($context, 'item.id', null);
        $type = ag($context, 'item.type', '??');
        $type = PlexClient::TYPE_MAPPER[$type] ?? $type;
        $bName = $this->context->backendName;

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
                        $this->logger->info("PlexGuid: Unable to parse '{backend}: {agent}' identifier.", [
                            'backend' => $this->context->backendName,
                            'agent' => $val,
                            ...$context,
                        ]);
                    }
                    continue;
                }

                [$key, $value] = explode('://', $val);
                $key = strtolower($key);

                if (null === ($this->guidMapper[$key] ?? null) || empty($value)) {
                    continue;
                }

                if (true === is_ignored_id($this->context->userContext, $bName, $type, $key, $value, $id)) {
                    if (true === $log) {
                        $this->logger->debug(
                            "PlexGuid: Ignoring '{client}: {backend}' external id '{source}' for {item.type} '{item.id}: {item.title}' as requested.",
                            [
                                'client' => $this->context->clientName,
                                'backend' => $bName,
                                'source' => $val,
                                'guid' => [
                                    'source' => $key,
                                    'value' => $value,
                                ],
                                ...$context,
                            ],
                        );
                    }
                    continue;
                }

                // -- Plex in their infinite wisdom, sometimes report two keys for same data source.
                if (null !== ($guid[$this->guidMapper[$key]] ?? null)) {
                    if (true === $log) {
                        $this->logger->warning(
                            "PlexGuid: '{client}: {backend}' reported multiple ids for same data source '{key}: {ids}' for {item.type} '{item.id}: {item.title}'.",
                            [
                                'client' => $this->context->clientName,
                                'backend' => $this->context->backendName,
                                'key' => $key,
                                'ids' => sprintf('%s, %s', $guid[$this->guidMapper[$key]], $value),
                                ...$context,
                            ],
                        );
                    }

                    if (false === ctype_digit($value)) {
                        continue;
                    }

                    if ((int) $guid[$this->guidMapper[$key]] < (int) $value) {
                        continue;
                    }
                }

                $guid[$this->guidMapper[$key]] = $value;
            } catch (Throwable $e) {
                if (true === $log) {
                    $this->logger->info(
                        message: "{class}: Ignoring '{user}@{backend}' invalid GUID '{agent}' for {item.type} '{item.id}: {item.title}'.",
                        context: [
                            'class' => after_last(self::class, '\\'),
                            'user' => $this->context->userContext->name,
                            'backend' => $this->context->backendName,
                            'agent' => $val,
                            ...$context,
                            ...exception_log($e),
                        ],
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
     * @param string $guid Guid to parse.
     * @param array $context Context data.
     * @param bool $log Log errors. default true.
     *
     * @return string The parsed GUID.
     * @see https://github.com/ZeroQI/Hama.bundle/issues/510
     */
    private function parseLegacyAgent(string $guid, array $context = [], bool $log = true): string
    {
        if (false === in_array(before($guid, '://'), $this->guidLegacy, true)) {
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

            $guid = strtr($guid, $this->guidReplacer);

            $agentGuid = explode('://', after($guid, 'agents.'));

            if (false === isset($agentGuid[1])) {
                return $guid;
            }

            return $agentGuid[0] . '://' . before($agentGuid[1], '?');
        } catch (Throwable $e) {
            if (true === $log) {
                $this->logger->error(
                    message: "PlexGuid: Exception '{error.kind}' was thrown unhandled during '{client}: {backend}' parsing legacy agent '{agent}' identifier. Error '{error.message}' at '{error.file}:{error.line}.",
                    context: [
                        'backend' => $this->context->backendName,
                        'client' => $this->context->clientName,
                        'error' => [
                            'kind' => $e::class,
                            'line' => $e->getLine(),
                            'message' => $e->getMessage(),
                            'file' => after($e->getFile(), ROOT_PATH),
                        ],
                        'agent' => $guid,
                        'exception' => [
                            'file' => $e->getFile(),
                            'line' => $e->getLine(),
                            'kind' => get_class($e),
                            'message' => $e->getMessage(),
                            'trace' => $e->getTrace(),
                        ],
                        ...$context,
                    ],
                );
            }
            return $guid;
        }
    }

    /**
     * Get the Plex Guid configuration.
     *
     * @return array
     */
    public function getConfig(): array
    {
        return [
            'guidMapper' => $this->guidMapper,
            'guidLegacy' => $this->guidLegacy,
            'guidLocal' => $this->guidLocal,
            'guidReplacer' => $this->guidReplacer,
        ];
    }
}
