<?php

declare(strict_types=1);

namespace App\Libs;

use RuntimeException;

final class Guid
{
    private const LOOKUP_KEY = '%s://%s';

    public const GUID_PLEX = 'guid_plex';
    public const GUID_IMDB = 'guid_imdb';
    public const GUID_TVDB = 'guid_tvdb';
    public const GUID_TMDB = 'guid_tmdb';
    public const GUID_TVMAZE = 'guid_tvmaze';
    public const GUID_TVRAGE = 'guid_tvrage';
    public const GUID_ANIDB = 'guid_anidb';

    public const SUPPORTED = [
        self::GUID_PLEX => 'string',
        self::GUID_IMDB => 'string',
        self::GUID_TVDB => 'string',
        self::GUID_TMDB => 'string',
        self::GUID_TVMAZE => 'string',
        self::GUID_TVRAGE => 'string',
        self::GUID_ANIDB => 'string',
    ];

    private array $data = [];

    public function __construct(array $guids)
    {
        foreach ($guids as $key => $value) {
            if (null === $value || null === (self::SUPPORTED[$key] ?? null)) {
                continue;
            }
            $this->updateGuid($key, $value);
        }
    }

    public static function fromArray(array $guids): self
    {
        return new self($guids);
    }

    public static function fromJson(string $guids): self
    {
        return new self(json_decode($guids, true));
    }

    public function getPointers(): array
    {
        $arr = [];

        foreach ($this->data as $key => $value) {
            $arr[] = sprintf(self::LOOKUP_KEY, $key, $value);
        }

        return $arr;
    }

    public function getGuids(): array
    {
        return $this->data;
    }

    private function updateGuid(mixed $key, mixed $value): void
    {
        if ($value === ($this->data[$key] ?? null)) {
            return;
        }

        if (!is_string($key)) {
            throw new RuntimeException(
                sprintf(
                    'Unexpected offset type was given. Was expecting \'string\' but got \'%s\' instead.',
                    get_debug_type($key)
                ),
            );
        }

        if (null === (self::SUPPORTED[$key] ?? null)) {
            throw new RuntimeException(
                sprintf(
                    'Unexpected offset key. Was expecting one of \'%s\', but got \'%s\' instead.',
                    implode(', ', array_keys(self::SUPPORTED)),
                    $key
                ),
            );
        }

        if (self::SUPPORTED[$key] !== ($valueType = get_debug_type($value))) {
            throw new RuntimeException(
                sprintf(
                    'Unexpected value type for \'%s\'. Was Expecting \'%s\' but got \'%s\' instead.',
                    $key,
                    self::SUPPORTED[$key],
                    $valueType
                )
            );
        }

        $this->data[$key] = $value;
    }
}
