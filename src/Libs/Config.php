<?php

declare(strict_types=1);

namespace App\Libs;

use Closure;

final class Config
{
    private static array $config = [];

    public static function init(array|Closure $array): void
    {
        self::$config = getValue($array);
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return ag(self::$config, $key, $default);
    }

    public static function append(array|Closure $array): bool
    {
        $array = getValue($array);

        foreach ((array)$array as $key => $val) {
            self::$config = ag_set(self::$config, $key, $val);
        }

        return true;
    }

    public static function getAll(): array
    {
        return self::$config;
    }

    public static function has(string $key): bool
    {
        return ag_exists(self::$config, $key);
    }

    public static function save(string $key, $value): void
    {
        self::$config = ag_set(self::$config, $key, $value);
    }

    public static function remove(string $key): void
    {
        self::$config = ag_delete(self::$config, $key);
    }
}
