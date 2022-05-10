<?php

declare(strict_types=1);

namespace App\Libs;

use JsonException;
use RuntimeException;

final class Guid
{
    public const GUID_PLEX = 'guid_plex';
    public const GUID_IMDB = 'guid_imdb';
    public const GUID_TVDB = 'guid_tvdb';
    public const GUID_TMDB = 'guid_tmdb';
    public const GUID_TVMAZE = 'guid_tvmaze';
    public const GUID_TVRAGE = 'guid_tvrage';
    public const GUID_ANIDB = 'guid_anidb';

    public const SUPPORTED = [
        Guid::GUID_PLEX => 'string',
        Guid::GUID_IMDB => 'string',
        Guid::GUID_TVDB => 'string',
        Guid::GUID_TMDB => 'string',
        Guid::GUID_TVMAZE => 'string',
        Guid::GUID_TVRAGE => 'string',
        Guid::GUID_ANIDB => 'string',
    ];

    private const LOOKUP_KEY = '%s://%s';

    private array $data = [];

    /**
     * Create List of db => external id list.
     *
     * @param array $guids Key/value pair of db => external id. For example, [ "guid_imdb" => "tt123456789" ]
     *
     * @throws RuntimeException if key/value is of unexpected type or unsupported.
     */
    public function __construct(array $guids)
    {
        foreach ($guids as $key => $value) {
            if (null === $value || null === (Guid::SUPPORTED[$key] ?? null)) {
                continue;
            }

            if ($value === ($this->data[$key] ?? null)) {
                continue;
            }

            if (!is_string($key)) {
                throw new RuntimeException(
                    sprintf(
                        'Unexpected offset type was given. Was expecting \'string\' but got \'%s\' instead.',
                        get_debug_type($key)
                    ),
                );
            }

            if (null === (Guid::SUPPORTED[$key] ?? null)) {
                throw new RuntimeException(
                    sprintf(
                        'Unexpected key. Was expecting one of \'%s\', but got \'%s\' instead.',
                        implode(', ', array_keys(Guid::SUPPORTED)),
                        $key
                    ),
                );
            }

            if (Guid::SUPPORTED[$key] !== ($valueType = get_debug_type($value))) {
                throw new RuntimeException(
                    sprintf(
                        'Unexpected value type for \'%s\'. Was Expecting \'%s\' but got \'%s\' instead.',
                        $key,
                        Guid::SUPPORTED[$key],
                        $valueType
                    )
                );
            }

            $this->data[$key] = $value;
        }
    }

    /**
     * Create new instance from array payload.
     *
     * @param array $payload Key/value pair of db => external id. For example, [ "guid_imdb" => "tt123456789" ]
     *
     * @return static
     */
    public static function fromArray(array $payload): self
    {
        return new self($payload);
    }

    /**
     * Create new instance from json payload.
     *
     * @param string $payload Key/value pair of db => external id. For example, { "guid_imdb" : "tt123456789" }
     *
     * @return static
     * @throws JsonException if decoding JSON payload fails.
     */
    public static function fromJson(string $payload): self
    {
        return new self(json_decode(json: $payload, associative: true, flags: JSON_THROW_ON_ERROR));
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
     * Return list of External ids.
     *
     * @return array
     */
    public function getAll(): array
    {
        return $this->data;
    }
}
