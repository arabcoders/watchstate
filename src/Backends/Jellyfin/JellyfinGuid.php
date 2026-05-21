<?php

declare(strict_types=1);

namespace App\Backends\Jellyfin;

use App\Backends\Common\Context;
use App\Backends\Common\GuidInterface as iGuid;
use App\Backends\Emby\EmbyClient;
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
        'tv maze' => Guid::GUID_TVMAZE, //-- why emby?
        'tvrage' => Guid::GUID_TVRAGE,
        'anidb' => Guid::GUID_ANIDB,
        'ytinforeader' => Guid::GUID_YOUTUBE,
        'cmdb' => Guid::GUID_CMDB,
    ];

    private ?Context $context = null;

    /**
     * Class to handle Jellyfin external ids Parsing.
     *
     * @param LoggerInterface $logger
     */
    public function __construct(
        protected LoggerInterface $logger,
    ) {
        $this->type = strtolower(
            str_contains(static::class, 'EmbyGuid') ? EmbyClient::CLIENT_NAME : JellyfinClient::CLIENT_NAME,
        );

        $file = Config::get('guid.file', null);

        try {
            if (null !== $file && true === file_exists($file)) {
                $this->parseGUIDFile($file);
            }
        } catch (Throwable $e) {
            $this->logger->error("Failed to parse GUID mapping file '{file}' for {client}.", [
                'event_name' => 'guid.file.parse_failed',
                'subsystem' => 'guid',
                'operation' => 'load_config',
                'outcome' => 'failed',
                'client' => $this->clientName(),
                'file' => $file,
                ...exception_log($e),
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
            $this->logger->info("GUID mapping file '{file}' is empty.", [
                'event_name' => 'guid.mapping.ignored',
                'subsystem' => 'guid',
                'operation' => 'load_config',
                'outcome' => 'ignored',
                'reason' => 'empty_file',
                'reason_label' => 'mapping file is empty',
                'client' => $this->clientName(),
                'mapping_key' => null,
                'mapping_from' => null,
                'mapping_to' => null,
                'file' => $file,
            ]);
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
                'type' => $this->type,
                'file' => $file,
            ]));
        }

        if (count($mapping) < 1) {
            return;
        }

        foreach ($mapping as $key => $map) {
            if (false === is_array($map)) {
                $this->logger->warning("Ignoring GUID mapping '{mapping_key}' for {client}: {reason_label}.", [
                    'event_name' => 'guid.mapping.ignored',
                    'subsystem' => 'guid',
                    'operation' => 'parse_config',
                    'outcome' => 'ignored',
                    'reason' => 'invalid_link_value',
                    'reason_label' => 'value must be an object',
                    'client' => $this->clientName(),
                    'mapping_key' => 'links.' . $key,
                    'mapping_from' => null,
                    'mapping_to' => null,
                    'file' => $file,
                    'given' => get_debug_type($map),
                ]);
                continue;
            }

            if ($this->type !== ag($map, 'type', 'not_set')) {
                continue;
            }

            $mapper = ag($map, 'map', null);

            if (false === is_array($mapper)) {
                $this->logger->warning("Ignoring GUID mapping '{mapping_key}' for {client}: {reason_label}.", [
                    'event_name' => 'guid.mapping.ignored',
                    'subsystem' => 'guid',
                    'operation' => 'parse_config',
                    'outcome' => 'ignored',
                    'reason' => 'invalid_map_value',
                    'reason_label' => 'map must be an object',
                    'client' => $this->clientName(),
                    'mapping_key' => 'links.' . $key,
                    'mapping_from' => null,
                    'mapping_to' => null,
                    'file' => $file,
                    'given' => get_debug_type($mapper),
                ]);
                continue;
            }

            $from = ag($mapper, 'from', null);
            $to = ag($mapper, 'to', null);

            if (empty($from) || false === is_string($from)) {
                $this->logger->warning("Ignoring GUID mapping '{mapping_key}' for {client}: {reason_label}.", [
                    'event_name' => 'guid.mapping.ignored',
                    'subsystem' => 'guid',
                    'operation' => 'parse_config',
                    'outcome' => 'ignored',
                    'reason' => 'invalid_map_from',
                    'reason_label' => 'map.from must be a non-empty string',
                    'client' => $this->clientName(),
                    'mapping_key' => 'links.' . $key,
                    'mapping_from' => true === is_string($from) ? $from : null,
                    'mapping_to' => true === is_string($to) ? $to : null,
                    'file' => $file,
                ]);
                continue;
            }

            if (empty($to) || false === is_string($to)) {
                $this->logger->warning("Ignoring GUID mapping '{mapping_key}' for {client}: {reason_label}.", [
                    'event_name' => 'guid.mapping.ignored',
                    'subsystem' => 'guid',
                    'operation' => 'parse_config',
                    'outcome' => 'ignored',
                    'reason' => 'invalid_map_to',
                    'reason_label' => 'map.to must be a non-empty string',
                    'client' => $this->clientName(),
                    'mapping_key' => 'links.' . $key,
                    'mapping_from' => true === is_string($from) ? $from : null,
                    'mapping_to' => true === is_string($to) ? $to : null,
                    'file' => $file,
                ]);
                continue;
            }

            if (false === Guid::validateGUIDName($to)) {
                $this->logger->warning("Ignoring GUID mapping '{mapping_key}' for {client}: {reason_label}.", [
                    'event_name' => 'guid.mapping.ignored',
                    'subsystem' => 'guid',
                    'operation' => 'parse_config',
                    'outcome' => 'ignored',
                    'reason' => 'invalid_guid_type_name',
                    'reason_label' => "map.to must start with 'guid_'",
                    'client' => $this->clientName(),
                    'mapping_key' => 'links.' . $key,
                    'mapping_from' => $from,
                    'mapping_to' => $to,
                    'file' => $file,
                ]);
                continue;
            }

            if (false === in_array($to, $supported, true)) {
                $this->logger->warning("Ignoring GUID mapping '{mapping_key}' for {client}: {reason_label}.", [
                    'event_name' => 'guid.mapping.ignored',
                    'subsystem' => 'guid',
                    'operation' => 'parse_config',
                    'outcome' => 'ignored',
                    'reason' => 'unsupported_guid_type',
                    'reason_label' => 'map.to is not a supported GUID type',
                    'client' => $this->clientName(),
                    'mapping_key' => 'links.' . $key,
                    'mapping_from' => $from,
                    'mapping_to' => $to,
                    'file' => $file,
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
        $bName = $this->context->backendName;

        foreach (array_change_key_case($guids, CASE_LOWER) as $key => $value) {
            if (null === ($this->guidMapper[$key] ?? null) || empty($value)) {
                continue;
            }

            try {
                if (true === is_ignored_id($this->context->userContext, $bName, $type, $key, $value, $id)) {
                    if (true === $log) {
                        $this->logger->debug(
                            "Ignoring external id '{guid_source}:{guid_value}' for '#{item_id}' from '{user}@{backend}': {reason_label}.",
                            [
                                'event_name' => 'guid.external_id.ignored',
                                'subsystem' => 'guid',
                                'operation' => 'parse',
                                'outcome' => 'ignored',
                                'reason' => 'user_ignored',
                                'reason_label' => 'external id is ignored by user config',
                                'client' => $this->context->clientName,
                                'user' => $this->context->userContext->name,
                                'backend' => $bName,
                                'item_id' => $id,
                                'guid_source' => $key,
                                'guid_value' => $value,
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

                $guid[$this->guidMapper[$key]] = $value;
            } catch (Throwable $e) {
                if (true === $log) {
                    $this->logger->info(
                        message: "Ignoring external id '{guid_source}:{guid_value}' for '#{item_id}' from '{user}@{backend}': {reason_label}.",
                        context: [
                            'event_name' => 'guid.external_id.ignored',
                            'subsystem' => 'guid',
                            'operation' => 'parse',
                            'outcome' => 'ignored',
                            'reason' => 'invalid_guid',
                            'reason_label' => 'external id is invalid',
                            'client' => $this->context->clientName,
                            'user' => $this->context->userContext->name,
                            'backend' => $this->context->backendName,
                            'item_id' => $id,
                            'guid_source' => $key,
                            'guid_value' => $value,
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
     * Get the configuration.
     *
     * @return array
     */
    public function getConfig(): array
    {
        return ['guidMapper' => $this->guidMapper];
    }

    private function clientName(): string
    {
        return $this->context->clientName ?? ('emby' === $this->type ? EmbyClient::CLIENT_NAME : JellyfinClient::CLIENT_NAME);
    }
}
