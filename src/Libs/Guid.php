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
    private const array DEFAULT_SUPPORTED = [
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
     * @var array GUID types and their respective data types.
     */
    private static array $supported = self::DEFAULT_SUPPORTED;

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
    private const array DEFAULT_VALIDATE_GUID = [
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
     * Constant array for validating GUIDs.
     *
     * This array contains the patterns and example formats for validating GUIDs of different types.
     * Each GUID type is mapped to an array with two keys:
     * - 'pattern' (string): The regular expression pattern to match against the GUID.
     * - 'example' (string): An example format of the GUID value.
     *
     * @var array<string, array{ pattern: string, example: string, tests: array{ valid: array<string|int>, invalid: array<string|int> } }>
     */
    private static array $validateGuid = self::DEFAULT_VALIDATE_GUID;

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
    private static ?iLogger $logger = null;

    private static bool $checkedExternalFile = false;

    /**
     * Create list of db => external id list.
     *
     * @param array $guids A key/value a pair of db => external id. For example, [ "guid_imdb" => "tt123456789" ]
     * @param array $context
     * @param iLogger|null $logger
     */
    public function __construct(array $guids, array $context = [], ?iLogger $logger = null)
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
                        'event_name' => 'guid.external_id.ignored',
                        'subsystem' => 'guid',
                        'operation' => 'parse',
                        'outcome' => 'ignored',
                        'reason' => 'unexpected_value_type',
                        'reason_label' => 'external id value type is invalid',
                        'client' => self::clientName($context),
                        'user' => ag($context, 'user'),
                        'backend' => ag($context, 'backend'),
                        'remote_id' => ag($context, 'item.remote_id'),
                        'guid_source' => $key,
                        'guid_value' => is_scalar($value) || null === $value ? $value : get_debug_type($value),
                        'key' => $key,
                        'condition' => [
                            'expecting' => self::$supported[$key],
                            'actual' => $valueType,
                        ],
                        ...$context,
                    ],
                );
                continue;
            }

            if (null !== (self::$validateGuid[$key] ?? null)) {
                if (1 !== @preg_match(self::$validateGuid[$key]['pattern'], $value, $matches)) {
                    $this->getLogger()->info(
                        "Ignoring '{backend}' {item.type} '{item.title}' '{key}' external id. Unexpected value '{given}'. Expecting '{expected}'.",
                        [
                            'event_name' => 'guid.external_id.ignored',
                            'subsystem' => 'guid',
                            'operation' => 'parse',
                            'outcome' => 'ignored',
                            'reason' => 'invalid_guid',
                            'reason_label' => 'external id is invalid',
                            'client' => self::clientName($context),
                            'user' => ag($context, 'user'),
                            'backend' => ag($context, 'backend'),
                            'remote_id' => ag($context, 'item.remote_id'),
                            'guid_source' => $key,
                            'guid_value' => $value,
                            'key' => $key,
                            'expected' => self::$validateGuid[$key]['example'],
                            'given' => $value,
                            ...$context,
                        ],
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
            self::$logger?->info("GUID mapping file '{file}' is empty.", [
                'event_name' => 'guid.mapping.ignored',
                'subsystem' => 'guid',
                'operation' => 'load_config',
                'outcome' => 'ignored',
                'reason' => 'empty_file',
                'reason_label' => 'mapping file is empty',
                'client' => 'guid',
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
                (int) $e->getCode(),
                $e,
            );
        }

        $supportedVersion = ag(require __DIR__ . '/../../config/config.php', 'guid.version', '0.0');
        $guidVersion = (string) ag($yaml, 'version', Config::get('guid.version', '0.0'));

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
                self::$logger?->warning("Ignoring GUID mapping '{mapping_key}' for {client}: {reason_label}.", [
                    'event_name' => 'guid.mapping.ignored',
                    'subsystem' => 'guid',
                    'operation' => 'parse_config',
                    'outcome' => 'ignored',
                    'reason' => 'invalid_link_value',
                    'reason_label' => 'value must be an object',
                    'client' => 'guid',
                    'mapping_key' => 'guids.' . $key,
                    'mapping_from' => null,
                    'mapping_to' => null,
                    'given' => get_debug_type($def),
                    'file' => $file,
                ]);
                continue;
            }

            $name = ag($def, 'name');
            if (null === $name || false === self::validateGUIDName($name)) {
                self::$logger?->warning("Ignoring GUID mapping '{mapping_key}' for {client}: {reason_label}.", [
                    'event_name' => 'guid.mapping.ignored',
                    'subsystem' => 'guid',
                    'operation' => 'parse_config',
                    'outcome' => 'ignored',
                    'reason' => 'invalid_guid_type_name',
                    'reason_label' => "name must start with 'guid_'",
                    'client' => 'guid',
                    'mapping_key' => 'guids.' . $key,
                    'mapping_from' => null,
                    'mapping_to' => true === is_string($name) ? $name : null,
                    'given' => $name ?? 'null',
                    'file' => $file,
                ]);
                continue;
            }

            $type = ag($def, 'type');
            if (null === $type || false === is_string($type)) {
                self::$logger?->warning("Ignoring GUID mapping '{mapping_key}' for {client}: {reason_label}.", [
                    'event_name' => 'guid.mapping.ignored',
                    'subsystem' => 'guid',
                    'operation' => 'parse_config',
                    'outcome' => 'ignored',
                    'reason' => 'invalid_map_value',
                    'reason_label' => 'type must be a string',
                    'client' => 'guid',
                    'mapping_key' => 'guids.' . $key,
                    'mapping_from' => null,
                    'mapping_to' => $name,
                    'given' => get_debug_type($type),
                    'file' => $file,
                ]);
                continue;
            }

            $validator = ag($def, 'validator', null);
            if (null === $validator || false === is_array($validator) || count($validator) < 1) {
                self::$logger?->warning("Ignoring GUID mapping '{mapping_key}' for {client}: {reason_label}.", [
                    'event_name' => 'guid.mapping.ignored',
                    'subsystem' => 'guid',
                    'operation' => 'parse_config',
                    'outcome' => 'ignored',
                    'reason' => 'invalid_map_value',
                    'reason_label' => 'validator key must be an object',
                    'client' => 'guid',
                    'mapping_key' => 'guids.' . $key,
                    'mapping_from' => null,
                    'mapping_to' => $name,
                    'given' => get_debug_type($validator),
                    'file' => $file,
                ]);
                continue;
            }

            $pattern = ag($validator, 'pattern');
            if (null === $pattern || false === @preg_match($pattern, '')) {
                self::$logger?->warning("Ignoring GUID mapping '{mapping_key}' for {client}: {reason_label}.", [
                    'event_name' => 'guid.mapping.ignored',
                    'subsystem' => 'guid',
                    'operation' => 'parse_config',
                    'outcome' => 'ignored',
                    'reason' => 'invalid_map_from',
                    'reason_label' => 'validator.pattern is empty or invalid',
                    'client' => 'guid',
                    'mapping_key' => 'guids.' . $key,
                    'mapping_from' => true === is_string($pattern) ? $pattern : null,
                    'mapping_to' => $name,
                    'file' => $file,
                ]);
                continue;
            }

            $example = ag($validator, 'example');

            if (empty($example) || false === is_string($example)) {
                self::$logger?->warning("Ignoring GUID mapping '{mapping_key}' for {client}: {reason_label}.", [
                    'event_name' => 'guid.mapping.ignored',
                    'subsystem' => 'guid',
                    'operation' => 'parse_config',
                    'outcome' => 'ignored',
                    'reason' => 'invalid_map_to',
                    'reason_label' => 'validator.example is empty or not a string',
                    'client' => 'guid',
                    'mapping_key' => 'guids.' . $key,
                    'mapping_from' => $pattern,
                    'mapping_to' => true === is_string($example) ? $example : $name,
                    'file' => $file,
                ]);
                continue;
            }

            $tests = ag($validator, 'tests', []);
            if (empty($tests) || false === is_array($tests)) {
                self::$logger?->warning("Ignoring GUID mapping '{mapping_key}' for {client}: {reason_label}.", [
                    'event_name' => 'guid.mapping.ignored',
                    'subsystem' => 'guid',
                    'operation' => 'parse_config',
                    'outcome' => 'ignored',
                    'reason' => 'invalid_map_value',
                    'reason_label' => 'validator.tests key must be an object',
                    'client' => 'guid',
                    'mapping_key' => 'guids.' . $key,
                    'mapping_from' => $pattern,
                    'mapping_to' => $name,
                    'file' => $file,
                ]);
                continue;
            }

            $valid = ag($tests, 'valid', []);
            if (empty($valid) || false === is_array($valid) || count($valid) < 1) {
                self::$logger?->warning("Ignoring GUID mapping '{mapping_key}' for {client}: {reason_label}.", [
                    'event_name' => 'guid.mapping.ignored',
                    'subsystem' => 'guid',
                    'operation' => 'parse_config',
                    'outcome' => 'ignored',
                    'reason' => 'invalid_map_from',
                    'reason_label' => 'validator.tests.valid key must be an array',
                    'client' => 'guid',
                    'mapping_key' => 'guids.' . $key,
                    'mapping_from' => $pattern,
                    'mapping_to' => $name,
                    'file' => Config::get('guid.file'),
                ]);
                continue;
            }

            foreach ($valid as $val) {
                if (1 === @preg_match($pattern, $val)) {
                    continue;
                }

                self::$logger?->warning(
                    "Ignoring GUID mapping '{mapping_key}' for {client}: {reason_label}.",
                    [
                        'event_name' => 'guid.mapping.ignored',
                        'subsystem' => 'guid',
                        'operation' => 'parse_config',
                        'outcome' => 'ignored',
                        'reason' => 'invalid_map_from',
                        'reason_label' => 'validator.tests.valid value does not match pattern',
                        'client' => 'guid',
                        'mapping_key' => 'guids.' . $key,
                        'mapping_from' => (string) $val,
                        'mapping_to' => $name,
                        'file' => $file,
                    ],
                );
                continue 2;
            }

            $invalid = ag($tests, 'invalid', []);
            if (empty($invalid) || false === is_array($invalid) || count($invalid) < 1) {
                self::$logger?->warning(
                    "Ignoring GUID mapping '{mapping_key}' for {client}: {reason_label}.",
                    [
                        'event_name' => 'guid.mapping.ignored',
                        'subsystem' => 'guid',
                        'operation' => 'parse_config',
                        'outcome' => 'ignored',
                        'reason' => 'invalid_map_to',
                        'reason_label' => 'validator.tests.invalid key must be an array',
                        'client' => 'guid',
                        'mapping_key' => 'guids.' . $key,
                        'mapping_from' => $pattern,
                        'mapping_to' => $name,
                        'file' => $file,
                    ],
                );
                continue;
            }

            foreach ($invalid as $val) {
                if (1 !== @preg_match($pattern, $val)) {
                    continue;
                }

                self::$logger?->warning(
                    "Ignoring GUID mapping '{mapping_key}' for {client}: {reason_label}.",
                    [
                        'event_name' => 'guid.mapping.ignored',
                        'subsystem' => 'guid',
                        'operation' => 'parse_config',
                        'outcome' => 'ignored',
                        'reason' => 'invalid_map_to',
                        'reason_label' => 'validator.tests.invalid value matches pattern',
                        'client' => 'guid',
                        'mapping_key' => 'guids.' . $key,
                        'mapping_from' => (string) $val,
                        'mapping_to' => $name,
                        'file' => $file,
                    ],
                );
                continue 2;
            }

            self::$supported[$name] = $type;
            self::$validateGuid[$name] = [
                'description' => ag($validator, 'description', static fn() => r('The {name} ID Parser.', [
                    'name' => after($name, 'guid_'),
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
    public static function fromArray(array $payload, array $context = [], ?Logger $logger = null): self
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
                'db_list' => implode(
                    ', ',
                    array_map(static fn($f) => after($f, 'guid_'), array_keys(self::$supported)),
                ),
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
            self::$logger?->error("Failed to parse GUID mapping file '{file}' for {client}.", [
                'event_name' => 'guid.file.parse_failed',
                'subsystem' => 'guid',
                'operation' => 'load_config',
                'outcome' => 'failed',
                'client' => 'guid',
                'file' => $file,
                ...exception_log($e),
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
        self::$supported = self::DEFAULT_SUPPORTED;
        self::$validateGuid = self::DEFAULT_VALIDATE_GUID;
    }

    /**
     * Validate Externally Added GUID Names.
     *
     * @param string $name The name to validate.
     *
     * @return bool True if the name is valid, false otherwise.
     */
    public static function validateGUIDName(string $name): bool
    {
        return str_starts_with($name, 'guid_') && is_valid_name($name);
    }

    private static function clientName(array $context): string
    {
        $client = ag($context, 'client');

        if (true === is_string($client) && '' !== $client) {
            return $client;
        }

        return 'guid';
    }
}
