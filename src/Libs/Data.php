<?php

declare(strict_types=1);

namespace App\Libs;

final class Data
{
    private static array $data = [];

    public static function addBucket(string $bucket): void
    {
        self::$data[$bucket] = [];
    }

    public static function add(string $bucket, string $key, mixed $value): void
    {
        if (!isset(self::$data[$bucket])) {
            self::$data[$bucket] = [];
        }

        self::$data[$bucket][$key] = $value;
    }

    public static function increment(string $bucket, string $key, int $increment = 1): void
    {
        if (!isset(self::$data[$bucket])) {
            self::$data[$bucket] = [];
        }

        self::$data[$bucket][$key] = (self::$data[$bucket][$key] ?? 0) + $increment;
    }

    public static function append(string $bucket, string $key, mixed $value): void
    {
        if (!isset(self::$data[$bucket])) {
            self::$data[$bucket] = [];
        }

        if (!isset(self::$data[$bucket][$key])) {
            self::$data[$bucket][$key] = [];
        }

        if (!is_array(self::$data[$bucket][$key]) && !empty(self::$data[$bucket][$key])) {
            self::$data[$bucket][$key] = [self::$data[$bucket][$key]];
        }

        self::$data[$bucket][$key][] = $value;
    }

    public static function get(null|string $filter = null, mixed $default = null): mixed
    {
        return ag(self::$data, $filter, $default);
    }

    public static function reset(): void
    {
        self::$data = [];
    }
}
