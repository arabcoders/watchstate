<?php

declare(strict_types=1);

namespace App\Libs;

/**
 * Volatile messaging between classes.
 * This should not be used for anything important.
 * Data is mutable, and can be changed by anything.
 * Messages are not persistent and will be removed once the execution is done.
 */
final class Message
{
    /**
     * @var array $data holds the messages.
     */
    private static array $data = [];

    /**
     * Add message to store.
     *
     * @param string $key Message key.
     * @param mixed $value value.
     */
    public static function add(string $key, mixed $value): void
    {
        self::$data = ag_set(self::$data, $key, $value);
    }

    /**
     * Get message.
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
     * Get all stored messages.
     *
     * @return array
     */
    public static function getAll(): array
    {
        return self::$data;
    }

    /**
     * increment key value.
     *
     * @param string $key message key.
     * @param int $increment value. default to 1
     */
    public static function increment(string $key, int $increment = 1): void
    {
        self::$data = ag_set(self::$data, $key, $increment + (int)ag(self::$data, $key, 0));
    }

    /**
     * Reset stored data.
     */
    public static function reset(): void
    {
        self::$data = [];
    }
}
