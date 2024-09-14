<?php

declare(strict_types=1);

namespace App\Libs;

use App\Libs\Exceptions\InvalidArgumentException;
use JsonSerializable;
use Monolog\Logger;
use Psr\Log\LoggerInterface as iLogger;
use Stringable;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;
use Throwable;

/**
 * The Guid class is the parser for external ids for different databases.
 *
 * This class provides methods to create and validate the list of external ids,
 * retrieve the supported external id sources, and obtain the pointers linking the
 * entity to the external ids.
 *
 * @implements JsonSerializable
 * @implements Stringable
 */
final class Guid implements JsonSerializable, Stringable
{
    public const string GUID_IMDB = 'guid_imdb';
    public const string GUID_TVDB = 'guid_tvdb';
    public const string GUID_TMDB = 'guid_tmdb';
    public const string GUID_TVMAZE = 'guid_tvmaze';
    public const string GUID_TVRAGE = 'guid_tvrage';
    public const string GUID_ANIDB = 'guid_anidb';
    public const string GUID_YOUTUBE = 'guid_youtube';
    public const string GUID_CMDB = 'guid_cmdb';

    /**
     * @var array GUID types and their respective data types.
     */
    private static array $supported = [
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
     * @var array<string, array{ pattern: string, example: string, tests: array{ valid: array<string|int>, invalid: array<string|int> } }>
     */
    private static array $validateGuid = [
        Guid::GUID_IMDB => [
            'description' => 'IMDB ID Parser.',
            'pattern' => '/^(?<guid>tt[0-9\/]+)$/i',
            'example' => 'tt(number)',
            'tests' => [
                'valid' => ['tt1234567'],
                'invalid' => ['tt1234567a', '111234567'],
            ],
        ],
        Guid::GUID_TMDB => [
            'description' => 'The tmdb ID Parser.',
            'pattern' => '/^(?<guid>[0-9\/]+)$/i',
            'example' => '(number)',
            'tests' => [
                'valid' => ['123456'],
                'invalid' => ['123456a'],
            ],
        ],
        Guid::GUID_TVDB => [
            'description' => 'The tvdb ID Parser.',
            'pattern' => '/^(?<guid>[0-9\/]+)$/i',
            'example' => '(number)',
            'tests' => [
                'valid' => ['123456'],
                'invalid' => ['123456a', 'd123456'],
            ],
        ],
        Guid::GUID_TVMAZE => [
            'description' => 'The tvMaze ID Parser.',
            'pattern' => '/^(?<guid>[0-9\/]+)$/i',
            'example' => '(number)',
            'tests' => [
                'valid' => ['123456'],
                'invalid' => ['123456a', 'd123456'],
            ],
        ],
        Guid::GUID_TVRAGE => [
            'description' => 'The tvRage ID Parser.',
            'pattern' => '/^(?<guid>[0-9\/]+)$/i',
            'example' => '(number)',
            'tests' => [
                'valid' => ['123456'],
                'invalid' => ['123456a', 'd123456'],
            ],
        ],
        Guid::GUID_ANIDB => [
            'description' => 'The anidb ID Parser.',
            'pattern' => '/^(?<guid>[0-9\/]+)$/i',
            'example' => '(number)',
            'tests' => [
                'valid' => ['123456'],
                'invalid' => ['123456a', 'd123456'],
            ],
        ],
    ];

    /**
     * @var string LOOKUP_KEY is how we format external ids to look up a record.
     */
    private const string LOOKUP_KEY = '{db}://{id}';

    /**
     * @var array $data Holds the list of supported external ids.
     */
    private array $data = [];

    /**
     * @var null|iLogger $logger The logger instance used for logging.
     */
    private static iLogger|null $logger = null;

    private static bool $checkedExternalFile = false;

    /**
     * Create list of db => external id list.
     *
     * @param array $guids A key/value a pair of db => external id. For example, [ "guid_imdb" => "tt123456789" ]
     * @param array $context
     * @param iLogger|null $logger
     */
    public function __construct(array $guids, array $context = [], iLogger|null $logger = null)
    {
        if (null !== $logger) {
            self::$logger = $logger;
        }

        if (false === self::$checkedExternalFile) {
            self::loadExternalGUID();
        }

        foreach ($guids as $key => $value) {
            if (null === $value || null === (self::$supported[$key] ?? null)) {
                continue;
            }

            if (self::$supported[$key] !== ($valueType = get_debug_type($value))) {
                $this->getLogger()->info(
                    "Ignoring '{backend}' {item.type} '{item.title}' '{key}' external id. Unexpected value type.",
                    [
                        'key' => $key,
                        'condition' => [
                            'expecting' => self::$supported[$key],
                            'actual' => $valueType,
                        ],
                        ...$context,
                    ]
                );
                continue;
            }

            if (null !== (self::$validateGuid[$key] ?? null)) {
                if (1 !== @preg_match(self::$validateGuid[$key]['pattern'], $value, $matches)) {
                    $this->getLogger()->info(
                        "Ignoring '{backend}' {item.type} '{item.title}' '{key}' external id. Unexpected value '{given}'. Expecting '{expected}'.",
                        [
                            'key' => $key,
                            'expected' => self::$validateGuid[$key]['example'],
                            'given' => $value,
                            ...$context,
                        ]
                    );
                    continue;
                }

                if (isset($matches['guid'])) {
                    $value = $matches['guid'];
                }
            }

            $this->data[$key] = $value;
        }
    }

    /**
     * Set the logger instance for the class.
     *
     * @param iLogger $logger The logger instance to be set.
     */
    public static function setLogger(iLogger $logger): void
    {
        self::$logger = $logger;
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
    public static function parseGUIDFile(string $file): void
    {
        if (false === file_exists($file) || false === is_readable($file)) {
            throw new InvalidArgumentException(r("The file '{file}' does not exist or is not readable.", [
                'file' => $file,
            ]));
        }

        if (filesize($file) < 1) {
            self::$logger?->info(r("The external GUID mapping file '{file}' is empty.", ['file' => $file]));
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
            ]), (int)$e->getCode(), $e);
        }

        $supportedVersion = ag(require __DIR__ . '/../../config/config.php', 'guid.version', '0.0');
        $guidVersion = (string)ag($yaml, 'version', Config::get('guid.version', '0.0'));

        if (true === version_compare($supportedVersion, $guidVersion, '<')) {
            throw new InvalidArgumentException(r("Unsupported file version '{version}'. Expecting '{supported}'.", [
                'version' => $guidVersion,
                'supported' => $supportedVersion,
            ]));
        }

        $guids = ag($yaml, 'guids', null);

        if (null === $guids || false === is_array($guids) || count($guids) < 1) {
            throw new InvalidArgumentException(r("The GUIDs file '{file}' does not contain any GUIDs mapping.", [
                'file' => $file,
            ]));
        }

        foreach ($guids as $key => $def) {
            if (false === is_array($def)) {
                self::$logger?->warning(
                    "Ignoring 'guids.{key}'. Value must be an object. '{given}' is given.",
                    [
                        'key' => $key,
                        'given' => get_debug_type($def),
                    ]
                );
                continue;
            }

            $name = ag($def, 'name');
            if (null === $name || false === str_starts_with($name, 'guid_')) {
                self::$logger?->warning(
                    "Ignoring 'guids.{key}'. name must start with 'guid_'. '{given}' is given.",
                    [
                        'key' => $key,
                        'given' => $name ?? 'null',
                    ]
                );
                continue;
            }

            $type = ag($def, 'type');
            if (null === $type || false === is_string($type)) {
                self::$logger?->warning(
                    "Ignoring 'guids.{key}.{name}'. type must be a string. '{given}' is given.",
                    [
                        'key' => $key,
                        'name' => $name,
                        'given' => get_debug_type($type),
                    ]
                );
                continue;
            }

            $validator = ag($def, 'validator', null);
            if (null === $validator || false === is_array($validator) || count($validator) < 1) {
                self::$logger?->warning(
                    "Ignoring 'guids.{key}.{name}'. validator key must be an object. '{given}' is given.",
                    [
                        'key' => $key,
                        'name' => $name,
                        'given' => get_debug_type($validator),
                    ]
                );
                continue;
            }

            $pattern = ag($validator, 'pattern');
            if (null === $pattern || false === @preg_match($pattern, '')) {
                self::$logger?->warning("Ignoring 'guids.{key}.{name}'. validator.pattern is empty or invalid.", [
                    'key' => $key,
                    'name' => $name,
                ]);
                continue;
            }

            $example = ag($validator, 'example');

            if (empty($example) || false === is_string($example)) {
                self::$logger?->warning("Ignoring 'guids.{key}.{name}'. validator.example is empty or not a string.", [
                    'key' => $key,
                    'name' => $name,
                ]);
                continue;
            }

            $tests = ag($validator, 'tests', []);
            if (empty($tests) || false === is_array($tests)) {
                self::$logger?->warning("Ignoring 'guids.{key}.{name}'. validator.tests key must be an object.", [
                    'key' => $key,
                    'name' => $name,
                ]);
                continue;
            }

            $valid = ag($tests, 'valid', []);
            if (empty($valid) || false === is_array($valid) || count($valid) < 1) {
                self::$logger?->warning("Ignoring 'guids.{key}.{name}'. validator.tests.valid key must be an array.", [
                    'key' => $key,
                    'name' => $name,
                ]);
                continue;
            }

            foreach ($valid as $val) {
                if (1 !== @preg_match($pattern, $val)) {
                    self::$logger?->warning(
                        "Ignoring 'guids.{key}.{name}'. validator.tests.valid value '{val}' does not match pattern.",
                        [
                            'key' => $key,
                            'name' => $name,
                            'val' => $val,
                        ]
                    );
                    continue 2;
                }
            }

            $invalid = ag($tests, 'invalid', []);
            if (empty($invalid) || false === is_array($invalid) || count($invalid) < 1) {
                self::$logger?->warning(
                    "Ignoring 'guids.{key}.{name}'. validator.tests.invalid key must be an array.",
                    [
                        'key' => $key,
                        'name' => $name,
                    ]
                );
                continue;
            }

            foreach ($invalid as $val) {
                if (1 === @preg_match($pattern, $val)) {
                    self::$logger?->warning(
                        "Ignoring 'guids.{key}.{name}'. validator.tests.invalid value '{val}' matches pattern.",
                        [
                            'key' => $key,
                            'name' => $name,
                            'val' => $val,
                        ]
                    );
                    continue 2;
                }
            }

            self::$supported[$name] = $type;
            self::$validateGuid[$name] = [
                'description' => ag($validator, 'description', fn() => r("The {name} ID Parser.", [
                    'name' => after($name, 'guid_')
                ])),
                'pattern' => $pattern,
                'example' => $example,
                'tests' => [
                    'valid' => $valid,
                    'invalid' => $invalid,
                ],
            ];
        }
    }

    /**
     * Get supported external ids sources.
     *
     * @return array<string,string>
     */
    public static function getSupported(): array
    {
        if (false === self::$checkedExternalFile) {
            self::loadExternalGUID();
        }

        return self::$supported;
    }

    /**
     * Get validators for external ids.
     *
     * @return array<string, array{
     *          pattern: string,
     *          example: string,
     *          tests: array{ valid: array<string|int>, invalid: array<string|int> }
     * }>
     */
    public static function getValidators(): array
    {
        if (false === self::$checkedExternalFile) {
            self::loadExternalGUID();
        }

        return self::$validateGuid;
    }

    /**
     * Create new instance from array.
     *
     * @param array $payload array of [ 'key' => 'value' ] pairs of [ 'db_source' => 'external id' ].
     * @param array $context context data.
     * @param Logger|null $logger logger instance.
     *
     * @return self
     */
    public static function fromArray(array $payload, array $context = [], Logger|null $logger = null): self
    {
        return new self(guids: $payload, context: $context, logger: $logger);
    }

    /**
     * Validate id value against expected format.
     *
     * @param string $db guid source.
     * @param string|int $id guid source id.
     *
     * @return bool
     *
     * @throws InvalidArgumentException if the db source is not supported or the value validation fails.
     */
    public static function validate(string $db, string|int $id): bool
    {
        $db = after($db, 'guid_');

        $lookup = 'guid_' . $db;

        if (false === array_key_exists($lookup, self::$supported)) {
            throw new InvalidArgumentException(r("Invalid db '{db}' source was given. Expecting '{db_list}'.", [
                'db' => $db,
                'db_list' => implode(', ', array_map(fn($f) => after($f, 'guid_'), array_keys(self::$supported))),
            ]));
        }

        if (null === (self::$validateGuid[$lookup] ?? null)) {
            return true;
        }

        if (1 !== @preg_match(self::$validateGuid[$lookup]['pattern'], $id)) {
            throw new InvalidArgumentException(r("Invalid value '{value}' for '{db}' GUID. Expecting '{example}'.", [
                'db' => $db,
                'value' => $id,
                'example' => self::$validateGuid[$lookup]['example'],
            ]));
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
     * @return iLogger
     */
    private function getLogger(): iLogger
    {
        if (null === self::$logger) {
            self::$logger = Container::get(iLogger::class);
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

    private static function loadExternalGUID(): void
    {
        $file = Config::get('guid.file', null);

        try {
            if (null !== $file && true === file_exists($file)) {
                self::parseGUIDFile($file);
            }
        } catch (Throwable $e) {
            self::$logger?->error("Failed to read or parse '{guid}' file. Error '{error}'.", [
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
        } finally {
            self::$checkedExternalFile = true;
        }
    }

    /**
     * This is for testing purposes only. do not use in production.
     * @return void
     */
    public static function reparse(): void
    {
        self::$checkedExternalFile = false;
    }
}
