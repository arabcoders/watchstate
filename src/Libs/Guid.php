<?php

declare(strict_types=1);

namespace App\Libs;

use InvalidArgumentException;
use JsonSerializable;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Stringable;

/**
 * The Guid class is the parser for external ids for different databases.
 *
 * This class provides methods to create and validate the list of external ids,
 * retrieve the supported external id sources, and obtain the pointers linking the
 * entity to the external ids.
 *
 * @implements JsonSerializable, Stringable
 */
final class Guid implements JsonSerializable, Stringable
{
    public const GUID_IMDB = 'guid_imdb';
    public const GUID_TVDB = 'guid_tvdb';
    public const GUID_TMDB = 'guid_tmdb';
    public const GUID_TVMAZE = 'guid_tvmaze';
    public const GUID_TVRAGE = 'guid_tvrage';
    public const GUID_ANIDB = 'guid_anidb';
    public const GUID_YOUTUBE = 'guid_youtube';
    public const GUID_CMDB = 'guid_cmdb';
    /**
     * Constant array of supported GUID types.
     *
     * This array contains the supported GUID types as keys and their respective data types as values.
     *
     * @var array
     */
    private const SUPPORTED = [
        Guid::GUID_IMDB => 'string',
        Guid::GUID_TVDB => 'string',
        Guid::GUID_TMDB => 'string',
        Guid::GUID_TVMAZE => 'string',
        Guid::GUID_TVRAGE => 'string',
        Guid::GUID_ANIDB => 'string',
        Guid::GUID_YOUTUBE => 'string',
        Guid::GUID_CMDB => 'string',
    ];
    /**
     * Constant array for validating GUIDs.
     *
     * This array contains the patterns and example formats for validating GUIDs of different types.
     * Each GUID type is mapped to an array with two keys:
     * - 'pattern' (string): The regular expression pattern to match against the GUID.
     * - 'example' (string): An example format of the GUID value.
     *
     * @var array
     */
    private const VALIDATE_GUID = [
        Guid::GUID_IMDB => [
            'pattern' => '/tt(\d+)/i',
            'example' => 'tt(number)',
        ],
        Guid::GUID_TMDB => [
            'pattern' => '/^[0-9\/]+$/i',
            'example' => '(number)',
        ],
        Guid::GUID_TVDB => [
            'pattern' => '/^[0-9\/]+$/i',
            'example' => '(number)',
        ],
        Guid::GUID_TVMAZE => [
            'pattern' => '/^[0-9\/]+$/i',
            'example' => '(number)',
        ],
        Guid::GUID_TVRAGE => [
            'pattern' => '/^[0-9\/]+$/i',
            'example' => '(number)',
        ],
        Guid::GUID_ANIDB => [
            'pattern' => '/^[0-9\/]+$/i',
            'example' => '(number)',
        ],
    ];
    /**
     * @var string LOOKUP_KEY is how we format external ids to look up a record.
     */
    private const LOOKUP_KEY = '{db}://{id}';
    /**
     * @var array $data Holds the list of supported external ids.
     */
    private array $data = [];
    /**
     * @var null|LoggerInterface $logger The logger instance used for logging.
     */
    private static LoggerInterface|null $logger = null;

    /**
     * Create list of db => external id list.
     *
     * @param array $guids A key/value a pair of db => external id. For example, [ "guid_imdb" => "tt123456789" ]
     * @param array $context
     */
    public function __construct(array $guids, array $context = [])
    {
        foreach ($guids as $key => $value) {
            if (null === $value || null === (self::SUPPORTED[$key] ?? null)) {
                continue;
            }

            if ($value === ($this->data[$key] ?? null)) {
                continue;
            }

            if (false === is_string($key)) {
                $this->getLogger()->info(
                    'Ignoring [{backend}] {item.type} [{item.title}] external id. Unexpected key type [{given}] was given.',
                    [
                        'key' => (string)$key,
                        'given' => get_debug_type($key),
                        ...$context,
                    ]
                );
                continue;
            }

            if (null === (self::SUPPORTED[$key] ?? null)) {
                $this->getLogger()->info(
                    'Ignoring [{backend}] {item.type} [{item.title}] [{key}] external id. Not supported.',
                    [
                        'key' => $key,
                        ...$context,
                    ]
                );
                continue;
            }

            if (self::SUPPORTED[$key] !== ($valueType = get_debug_type($value))) {
                $this->getLogger()->info(
                    'Ignoring [{backend}] {item.type} [{item.title}] [{key}] external id. Unexpected value type.',
                    [
                        'key' => $key,
                        'condition' => [
                            'expecting' => self::SUPPORTED[$key],
                            'actual' => $valueType,
                        ],
                        ...$context,
                    ]
                );
                continue;
            }

            if (null !== (self::VALIDATE_GUID[$key] ?? null)) {
                if (1 !== preg_match(self::VALIDATE_GUID[$key]['pattern'], $value)) {
                    $this->getLogger()->info(
                        'Ignoring [{backend}] {item.type} [{item.title}] [{key}] external id. Unexpected [{given}] value, expecting [{expected}].',
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
     * Set the logger instance for the class.
     *
     * @param LoggerInterface $logger The logger instance to be set.
     */
    public static function setLogger(LoggerInterface $logger): void
    {
        self::$logger = $logger;
    }

    /**
     * Get supported external ids sources.
     *
     * @return array<string,string>
     */
    public static function getSupported(): array
    {
        return self::SUPPORTED;
    }

    /**
     * Create new instance from array.
     *
     * @param array $payload array of [ 'key' => 'value' ] pairs of [ 'db_source' => 'external id' ].
     * @param array $context context data.
     *
     * @return self
     */
    public static function fromArray(array $payload, array $context = []): self
    {
        return new self(guids: $payload, context: $context);
    }

    /**
     * Validate id value against expected format.
     *
     * @param string $db guid source.
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
                r('Invalid db [{db}] source was given. Expecting [{db_list}].', [
                    'db' => $db,
                    'db_list' => implode(', ', array_map(fn($f) => after($f, 'guid_'), array_keys(self::SUPPORTED))),
                ])
            );
        }

        if (null === (self::VALIDATE_GUID[$lookup] ?? null)) {
            return true;
        }

        if (1 !== preg_match(self::VALIDATE_GUID[$lookup]['pattern'], $id)) {
            throw new InvalidArgumentException(
                r('Invalid [{value}] value for [{db}]. Expecting [{example}].', [
                    'db' => $db,
                    'value' => $id,
                    'example' => self::VALIDATE_GUID[$lookup]['example'],
                ])
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
            $arr[] = r(self::LOOKUP_KEY, ['db' => $key, 'id' => $value]);
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
     * Get instance of logger.
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

    /**
     * Serialize the object to a JSON string.
     *
     * @return array The serialized data as an array.
     */
    public function jsonSerialize(): array
    {
        return $this->getAll();
    }

    /**
     * Returns a JSON-encoded string representation of the object.
     *
     * @return string The JSON-encoded string representation of the object.
     */
    public function __toString(): string
    {
        return json_encode($this->getAll());
    }
}
