<?php

declare(strict_types=1);

namespace App\Libs;

use JsonException;
use RuntimeException;

final class Guid
{
    public const GUID_IMDB = 'guid_imdb';
    public const GUID_TVDB = 'guid_tvdb';
    public const GUID_TMDB = 'guid_tmdb';
    public const GUID_TVMAZE = 'guid_tvmaze';
    public const GUID_TVRAGE = 'guid_tvrage';
    public const GUID_ANIDB = 'guid_anidb';

    private const SUPPORTED = [
        Guid::GUID_IMDB => 'string',
        Guid::GUID_TVDB => 'string',
        Guid::GUID_TMDB => 'string',
        Guid::GUID_TVMAZE => 'string',
        Guid::GUID_TVRAGE => 'string',
        Guid::GUID_ANIDB => 'string',
    ];

    private const BACKEND_GUID = 'guidv_';

    private const LOOKUP_KEY = '%s://%s';

    private array $data = [];

    /**
     * Create List of db => external id list.
     *
     * @param array $guids Key/value pair of db => external id. For example, [ "guid_imdb" => "tt123456789" ]
     * @param bool $includeVirtual Whether to consider virtual Guids.
     *
     * @throws RuntimeException if key/value is of unexpected type or unsupported.
     */
    public function __construct(array $guids, bool $includeVirtual = true)
    {
        $supported = self::getSupported(includeVirtual: $includeVirtual);

        foreach ($guids as $key => $value) {
            if (null === $value || null === ($supported[$key] ?? null)) {
                continue;
            }

            if ($value === ($this->data[$key] ?? null)) {
                continue;
            }

            if (!is_string($key)) {
                throw new RuntimeException(
                    sprintf(
                        'Unexpected key type was given. Was expecting \'string\' but got \'%s\' instead.',
                        get_debug_type($key)
                    ),
                );
            }

            if (null === ($supported[$key] ?? null)) {
                throw new RuntimeException(
                    sprintf(
                        'Unexpected key \'%s\'. Expecting \'%s\'.',
                        $key,
                        implode(', ', array_keys($supported))
                    ),
                );
            }

            if ($supported[$key] !== ($valueType = get_debug_type($value))) {
                throw new RuntimeException(
                    sprintf(
                        'Unexpected value type for \'%s\'. Expecting \'%s\' but got \'%s\' instead.',
                        $key,
                        $supported[$key],
                        $valueType
                    )
                );
            }

            $this->data[$key] = $value;
        }
    }

    public static function makeVirtualGuid(string $backend, string $value): array
    {
        return [self::BACKEND_GUID . $backend => $value];
    }

    public static function getSupported(bool $includeVirtual = false): array
    {
        static $list = null;

        if (false === $includeVirtual) {
            return self::SUPPORTED;
        }

        if (null !== $list) {
            return $list;
        }

        $list = self::SUPPORTED;

        foreach (array_keys((array)Config::get('servers', [])) as $name) {
            $list[self::BACKEND_GUID . $name] = 'string';
        }

        return $list;
    }

    /**
     * Create new instance from array payload.
     *
     * @param array $payload Key/value pair of db => external id. For example, [ "guid_imdb" => "tt123456789" ]
     *
     * @return static
     */
    public static function fromArray(array $payload, bool $includeVirtual = true): self
    {
        return new self(guids: $payload, includeVirtual: $includeVirtual);
    }

    /**
     * Create new instance from json payload.
     *
     * @param string $payload Key/value pair of db => external id. For example, { "guid_imdb" : "tt123456789" }
     *
     * @return static
     * @throws JsonException if decoding JSON payload fails.
     */
    public static function fromJson(string $payload, bool $includeVirtual = true): self
    {
        return new self(
            guids:          json_decode(json: $payload, associative: true, flags: JSON_THROW_ON_ERROR),
            includeVirtual: $includeVirtual
        );
    }

    /**
     * Return suitable pointers to link entity to external id.
     *
     * @return array
     */
    public function getPointers(): array
    {
        $arr = [];

        foreach ($this->data as $key => $value) {
            $arr[] = sprintf(self::LOOKUP_KEY, $key, $value);
        }

        return $arr;
    }

    /**
     * Return list of external ids.
     *
     * @return array
     */
    public function getAll(): array
    {
        return $this->data;
    }
}
