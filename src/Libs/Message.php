<?php

declare(strict_types=1);

namespace App\Libs;

/**
 * Volatile messaging between classes.
 * This should not be used for anything important.
 * Data is mutable, and can be change by anything.
 * Messages are not persistent and will be removed
 * once the execution is done.
 */
final class Message
{
    private static array $data = [];

    /**
     * Get Message.
     *
     * @param string $key message key.
     * @param mixed|null $default default value
     *
     * @return mixed
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        return ag(self::$data, $key, $default);
    }

    /**
     * Get All Stored Messages.
     *
     * @return array
     */
    public static function getAll(): array
    {
        return self::$data;
    }

    /**
     * Add Message to Store.
     *
     * @param string $key Message key.
     * @param mixed $value value.
     *
     * @return void
     */
    public static function add(string $key, mixed $value): void
    {
        self::$data = ag_set(self::$data, $key, $value);
    }

    /**
     * increment key value increment parameter value.
     *
     * @param string $key message key.
     * @param int $increment value. default to 1
     *
     * @return void
     */
    public static function increment(string $key, int $increment = 1): void
    {
        self::$data = ag_set(self::$data, $key, $increment + (int)ag(self::$data, $key, 0));
    }

    /**
     * Reset Stored data.
     *
     * @return void
     */
    public static function reset(): void
    {
        self::$data = [];
    }
}
