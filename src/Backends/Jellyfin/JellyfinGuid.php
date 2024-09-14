<?php

declare(strict_types=1);

namespace App\Backends\Jellyfin;

use App\Backends\Common\Context;
use App\Backends\Common\GuidInterface as iGuid;
use App\Libs\Config;
use App\Libs\Exceptions\Backends\InvalidArgumentException;
use App\Libs\Guid;
use Psr\Log\LoggerInterface;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;
use Throwable;

class JellyfinGuid implements iGuid
{
    private string $type;

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

    private Context|null $context = null;

    /**
     * Class to handle Jellyfin external ids Parsing.
     *
     * @param LoggerInterface $logger
     */
    public function __construct(protected LoggerInterface $logger)
    {
        $this->type = str_contains(static::class, 'EmbyGuid') ? 'emby' : 'jellyfin';

        $file = Config::get('guid.file', null);

        try {
            if (null !== $file && true === file_exists($file)) {
                $this->parseGUIDFile($file);
            }
        } catch (Throwable $e) {
            $this->logger->error("{class}: Failed to read or parse '{guid}' file. Error '{error}'.", [
                'class' => afterLast(static::class, '\\'),
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
            throw new InvalidArgumentException(r("Failed to parse GUIDs file. Error '{error}'.", [
                'error' => $e->getMessage(),
            ]), code: (int)$e->getCode(), previous: $e);
        }

        $supported = array_keys(Guid::getSupported());
        $supportedVersion = Config::get('guid.version', '0.0');
        $guidVersion = (string)ag($yaml, 'version', $supportedVersion);

        if (true === version_compare($supportedVersion, $guidVersion, '<')) {
            throw new InvalidArgumentException(r("Unsupported file version '{version}'. Expecting '{supported}'.", [
                'version' => $guidVersion,
                'supported' => $supportedVersion,
            ]));
        }

        $mapping = ag($yaml, $this->type, []);

        if (false === is_array($mapping)) {
            throw new InvalidArgumentException(r("The GUIDs file '{file}' {type} sub key is not an array.", [
                'type' => $this->type,
                'file' => $file,
            ]));
        }

        if (count($mapping) < 1) {
            return;
        }

        foreach ($mapping as $key => $map) {
            if (false === is_array($map)) {
                $this->logger->warning("Ignoring '{type}.{key}'. Value must be an object. '{given}' is given.", [
                    'key' => $key,
                    'type' => $this->type,
                    'given' => get_debug_type($map),
                ]);
                continue;
            }

            $mapper = ag($map, 'map', null);

            if (false === is_array($mapper)) {
                $this->logger->warning("Ignoring '{type}.{key}'. map value must be an object. '{given}' is given.", [
                    'key' => $key,
                    'type' => $this->type,
                    'given' => get_debug_type($mapper),
                ]);
                continue;
            }

            $from = ag($mapper, 'from', null);
            $to = ag($mapper, 'to', null);

            if (empty($from) || false === is_string($from)) {
                $this->logger->warning("Ignoring '{type}.{key}'. map.from field is empty or not a string.", [
                    'type' => $this->type,
                    'key' => $key,
                ]);
                continue;
            }

            if (empty($to) || false === is_string($to)) {
                $this->logger->warning("Ignoring '{type}.{key}'. map.to field is empty or not a string.", [
                    'type' => $this->type,
                    'key' => $key,
                ]);
                continue;
            }

            if (false === Guid::validateGUIDName($to)) {
                $this->logger->warning("Ignoring '{type}.{key}'. map.to '{to}' field does not starts with 'guid_'.", [
                    'type' => $this->type,
                    'key' => $key,
                    'to' => $to,
                ]);
                continue;
            }

            if (false === in_array($to, $supported)) {
                $this->logger->warning("Ignoring '{type}.{key}'. map.to field is not a supported GUID type.", [
                    'type' => $this->type,
                    'key' => $key,
                    'to' => $to,
                ]);
                continue;
            }

            $this->guidMapper[$from] = $to;
        }
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

    public function isLocal(string $guid): bool
    {
        return false;
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

        $id = ag($context, 'item.id', null);
        $type = ag($context, 'item.type', '??');
        $type = JellyfinClient::TYPE_MAPPER[$type] ?? $type;

        foreach (array_change_key_case($guids, CASE_LOWER) as $key => $value) {
            if (null === ($this->guidMapper[$key] ?? null) || empty($value)) {
                continue;
            }

            try {
                if (true === isIgnoredId($this->context->backendName, $type, $key, $value, $id)) {
                    if (true === $log) {
                        $this->logger->debug(
                            "{class}: Ignoring '{client}: {backend}' external id '{source}' for {item.type} '{item.id}: {item.title}' as requested.",
                            [
                                'class' => afterLast(static::class, '\\'),
                                'client' => $this->context->clientName,
                                'backend' => $this->context->backendName,
                                'source' => $key . '://' . $value,
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

                $guid[$this->guidMapper[$key]] = $value;
            } catch (Throwable $e) {
                if (true === $log) {
                    $this->logger->error(
                        message: "{class}: Exception '{error.kind}' was thrown unhandled during '{client}: {backend}' parsing '{agent}' identifier. Error '{error.message}' at '{error.file}:{error.line}'.",
                        context: [
                            'class' => afterLast(static::class, '\\'),
                            'backend' => $this->context->backendName,
                            'client' => $this->context->clientName,
                            'error' => [
                                'kind' => $e::class,
                                'line' => $e->getLine(),
                                'message' => $e->getMessage(),
                                'file' => after($e->getFile(), ROOT_PATH),
                            ],
                            'agent' => $value,
                            'exception' => [
                                'file' => $e->getFile(),
                                'line' => $e->getLine(),
                                'kind' => get_class($e),
                                'message' => $e->getMessage(),
                                'trace' => $e->getTrace(),
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
     * Get the configuration.
     *
     * @return array
     */
    public function getConfig(): array
    {
        return [
            'guidMapper' => $this->guidMapper,
        ];
    }
}
