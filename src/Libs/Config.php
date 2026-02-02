<?php

declare(strict_types=1);

namespace App\Libs;

use Closure;

/**
 * Class Config
 *
 * This class provides methods to manage configuration settings.
 */
final class Config
{
    /**
     * @var array<string,mixed> $config - An array variable used to store configuration settings.
     */
    private static array $config = [];

    /**
     * Initialize the configuration array with the specified value.
     *
     * @param Closure|array $data The value to initialize the configuration array with.
     */
    public static function init(Closure|array $data): void
    {
        self::$config = get_value($data);
    }

    /**
     * Retrieve a value from the configuration array based on the specified key.
     *
     * @param string $key Dot notation key.
     * @param mixed $default (optional) The default value to return if the key is not found. Default is null.
     *
     * @return mixed The value associated with the specified key, or the default value if the key is not found.
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        return ag(self::$config, $key, $default);
    }

    /**
     * Append the give configuration to the existing configuration array.
     *
     * @param Closure|array $data The array or Closure containing the values to append.
     *
     * @return bool Returns true if the values were successfully appended to the configuration array.
     */
    public static function append(Closure|array $data): bool
    {
        $data = get_value($data);

        foreach ((array) $data as $key => $val) {
            self::$config = ag_set(self::$config, $key, $val);
        }

        return true;
    }

    /**
     * Retrieves all items from the configuration.
     *
     * @return array<string,mixed> An array containing all the configurations.
     */
    public static function getAll(): array
    {
        return self::$config;
    }

    /**
     * Checks if given key exists in the configuration.
     *
     * @param string $key Dot notation key.
     *
     * @return bool Returns true if the key exists, false otherwise.
     */
    public static function has(string $key): bool
    {
        return ag_exists(self::$config, $key);
    }

    /**
     * Save/Update configuration value using specified key.
     *
     * @param string $key Dot notation key.
     * @param mixed $value The value to be saved.
     */
    public static function save(string $key, mixed $value): void
    {
        self::$config = ag_set(self::$config, $key, $value);
    }

    /**
     * Remove given key from the configuration.
     *
     * @param string $key Dot notation key.
     */
    public static function remove(string $key): void
    {
        self::$config = ag_delete(self::$config, $key);
    }

    /**
     * Clear all configuration values.
     */
    public static function reset(): void
    {
        self::$config = [];
    }
}
