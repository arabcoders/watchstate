<?php

declare(strict_types=1);

namespace App\Libs;

use InvalidArgumentException;
use Psr\Log\LoggerInterface;
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

    private const VALIDATE_GUID = [
        Guid::GUID_IMDB => [
            'pattern' => '/tt(\d+)/i',
            'example' => 'tt(number)',
        ]
    ];

    private const BACKEND_GUID = 'guidv_';

    private const LOOKUP_KEY = '%s://%s';

    private array $data = [];

    private static LoggerInterface|null $logger = null;

    /**
     * Create List of db => external id list.
     *
     * @param array $guids Key/value pair of db => external id. For example, [ "guid_imdb" => "tt123456789" ]
     * @param bool $includeVirtual Whether to consider virtual guids.
     * @param array $context
     */
    public function __construct(array $guids, bool $includeVirtual = true, array $context = [])
    {
        $supported = self::getSupported(includeVirtual: $includeVirtual);

        foreach ($guids as $key => $value) {
            if (null === $value || null === ($supported[$key] ?? null)) {
                continue;
            }

            if ($value === ($this->data[$key] ?? null)) {
                continue;
            }

            if (false === is_string($key)) {
                $this->getLogger()->warning(
                    'Ignoring [%(backend)] %(item.type) [%(item.title)] external id. Unexpected key type [%(given)] was given.',
                    [
                        'key' => (string)$key,
                        'given' => get_debug_type($key),
                        ...$context,
                    ]
                );
                continue;
            }

            if (null === ($supported[$key] ?? null)) {
                $this->getLogger()->warning(
                    'Ignoring [%(backend)] %(item.type) [%(item.title)] [%(key)] external id. Not supported.',
                    [
                        'key' => $key,
                        ...$context,
                    ]
                );
                continue;
            }

            if ($supported[$key] !== ($valueType = get_debug_type($value))) {
                $this->getLogger()->warning(
                    'Ignoring [%(backend)] %(item.type) [%(item.title)] [%(key)] external id. Unexpected value type.',
                    [
                        'key' => $key,
                        'condition' => [
                            'expecting' => $supported[$key],
                            'actual' => $valueType,
                        ],
                        ...$context,
                    ]
                );
                continue;
            }

            if (null !== (self::VALIDATE_GUID[$key] ?? null)) {
                if (1 !== preg_match(self::VALIDATE_GUID[$key]['pattern'], $value)) {
                    $this->getLogger()->warning(
                        'Ignoring [%(backend)] %(item.type) [%(item.title)] [%(key)] external id. Unexpected value expecting [%(expected)] but got [%(given)].',
                        [
                            'key' => $key,
                            'expected' => self::VALIDATE_GUID[$key]['example'],
                            'given' => $value,
                            ...$context,
                        ]
                    );
                    continue;
                }
            }

            $this->data[$key] = $value;
        }
    }

    /**
     * Set Logger.
     *
     * @param LoggerInterface $logger
     * @return void
     */
    public static function setLogger(LoggerInterface $logger): void
    {
        self::$logger = $logger;
    }

    /**
     * Make Virtual external id that point to backend://(backend_id)
     *
     * @param string $backend backend name.
     * @param string $value backend id
     *
     * @return array<string,string>
     */
    public static function makeVirtualGuid(string $backend, string $value): array
    {
        return [self::BACKEND_GUID . $backend => $value];
    }

    /**
     * Get Supported External ids sources.
     *
     * @param bool $includeVirtual Whether to include virtual ids.
     *
     * @return array<string,string>
     */
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
     * Create new instance from array.
     *
     * @param array $payload array of [ 'key' => 'value' ] pairs of [ 'db_source' => 'external id' ].
     * @param bool $includeVirtual Whether to include parsing of Virtual guids.
     * @param array $context
     *
     * @return self
     */
    public static function fromArray(array $payload, bool $includeVirtual = true, array $context = []): self
    {
        return new self(guids: $payload, includeVirtual: $includeVirtual, context: $context);
    }

    /**
     * Validate id value against expected format.
     *
     * @param string $db guid source
     * @param string|int $id guid source id.
     *
     * @return bool
     *
     * @throws RuntimeException When source db not supported.
     * @throws InvalidArgumentException When id validation fails.
     */
    public static function validate(string $db, string|int $id): bool
    {
        $db = after($db, 'guid_');

        $lookup = 'guid_' . $db;

        if (false === array_key_exists($lookup, self::SUPPORTED)) {
            throw new RuntimeException(
                sprintf(
                    'Invalid db \'%s\' source was given. Expecting \'%s\'.',
                    $db,
                    implode(', ', array_map(fn($f) => after($f, 'guid_'), array_keys(self::SUPPORTED)))
                )
            );
        }

        if (null === (self::VALIDATE_GUID[$lookup] ?? null)) {
            return true;
        }

        if (1 !== preg_match(self::VALIDATE_GUID[$lookup]['pattern'], $id)) {
            throw new InvalidArgumentException(
                sprintf(
                    'Invalid value id for db source \'%s\'. Expecting \'%s\' but got \'%s\'.',
                    $db,
                    self::VALIDATE_GUID[$lookup]['example'],
                    $id
                )
            );
        }

        return true;
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

    /**
     * Get Instance of logger.
     *
     * @return LoggerInterface
     */
    private function getLogger(): LoggerInterface
    {
        if (null === self::$logger) {
            self::$logger = Container::get(LoggerInterface::class);
        }

        return self::$logger;
    }
}
