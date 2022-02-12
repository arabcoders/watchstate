<?php

declare(strict_types=1);

namespace App\Libs\Scheduler;

use Cron\CronExpression;
use DateTimeImmutable;
use DateTimeInterface;
use Exception;
use InvalidArgumentException;

final class TaskTimer
{
    /**
     * Set the Task execution time.
     */
    public static function at(string $expression): CronExpression
    {
        return new CronExpression($expression);
    }

    /**
     * Run the Task at a specific date.
     *
     * @throws Exception When string to DateTimeImmutable Conversion fails.
     */
    public static function fromDate(DateTimeInterface|string $date): CronExpression
    {
        if (!($date instanceof DateTimeInterface)) {
            $date = new DateTimeImmutable($date);
        }

        return self::at("{$date->format('i')} {$date->format('H')} {$date->format('d')} {$date->format('m')} *");
    }

    /**
     * Set the execution time to every minute.
     *
     * @param int|string|null $minute When set, specifies that the Task will be run every $minute minutes
     */
    public static function everyMinute(int|string|null $minute = null): CronExpression
    {
        $minuteExpression = '*';
        if ($minute !== null) {
            $c = self::validateCronSequence($minute);
            $minuteExpression = '*/' . $c['minute'];
        }

        return self::at($minuteExpression . ' * * * *');
    }

    /**
     * Set the execution time to every hour.
     *
     * @param int|string $minute default [0].
     */
    public static function hourly(int|string $minute = 0): CronExpression
    {
        $c = self::validateCronSequence($minute);

        return self::at("{$c['minute']} * * * *");
    }

    /**
     * Set the execution time to once a day.
     *
     * @param int|string $hour Default [0]
     * @param int|string $minute Default [0]
     */
    public static function daily(string|int $hour = 0, string|int $minute = 0): CronExpression
    {
        if (is_string($hour)) {
            $parts = explode(':', $hour);
            $hour = $parts[0];
            $minute = $parts[1] ?? '0';
        }

        $c = self::validateCronSequence($minute, $hour);

        return self::at("{$c['minute']} {$c['hour']} * * *");
    }

    /**
     * Set the execution time to once a week.
     *
     * @param int|string $weekday Default [0]
     * @param int|string $hour Default [0]
     * @param int|string $minute Default [0]
     */
    public static function weekly(int|string $weekday = 0, int|string $hour = 0, int|string $minute = 0): CronExpression
    {
        if (is_string($hour)) {
            $parts = explode(':', $hour);
            $hour = $parts[0];
            $minute = $parts[1] ?? '0';
        }

        $c = self::validateCronSequence($minute, $hour, null, null, $weekday);

        return self::at("{$c['minute']} {$c['hour']} * * {$c['weekday']}");
    }

    /**
     * Set the execution time to once a month.
     *
     * @param int|string $month Default [*]
     * @param int|string $day Default [1]
     * @param int|string $hour Default [0]
     * @param int|string $minute Default [0]
     */
    public static function monthly(
        int|string $month = '*',
        int|string $day = 1,
        int|string $hour = 0,
        int|string $minute = 0
    ): CronExpression {
        if (is_string($hour)) {
            $parts = explode(':', $hour);
            $hour = $parts[0];
            $minute = $parts[1] ?? '0';
        }
        $c = self::validateCronSequence($minute, $hour, $day, $month);
        return self::at("{$c['minute']} {$c['hour']} {$c['day']} {$c['month']} *");
    }

    /**
     * Set the execution time to every Sunday.
     *
     * @param int|string $hour Default [0]
     * @param int|string $minute Default [0]
     */
    public static function sunday(int|string $hour = 0, int|string $minute = 0): CronExpression
    {
        return self::weekly(0, $hour, $minute);
    }

    /**
     * Set the execution time to every Monday.
     *
     * @param int|string $hour Default [0]
     * @param int|string $minute Default [0]
     */
    public static function monday(int|string $hour = 0, int|string $minute = 0): CronExpression
    {
        return self::weekly(1, $hour, $minute);
    }

    /**
     * Set the execution time to every Tuesday.
     *
     * @param int|string $hour Default [0]
     * @param int|string $minute Default [0]
     */
    public static function tuesday(int|string $hour = 0, int|string $minute = 0): CronExpression
    {
        return self::weekly(2, $hour, $minute);
    }

    /**
     * Set the execution time to every Wednesday.
     *
     * @param int|string $hour Default [0]
     * @param int|string $minute Default [0]
     */
    public static function wednesday(int|string $hour = 0, int|string $minute = 0): CronExpression
    {
        return self::weekly(3, $hour, $minute);
    }

    /**
     * Set the execution time to every Thursday.
     *
     * @param int|string $hour Default [0]
     * @param int|string $minute Default [0]
     */
    public static function thursday(int|string $hour = 0, int|string $minute = 0): CronExpression
    {
        return self::weekly(4, $hour, $minute);
    }

    /**
     * Set the execution time to every Friday.
     *
     * @param int|string $hour Default [0]
     * @param int|string $minute Default [0]
     */
    public static function friday(int|string $hour = 0, int|string $minute = 0): CronExpression
    {
        return self::weekly(5, $hour, $minute);
    }

    /**
     * Set the execution time to every Saturday.
     *
     * @param int|string $hour Default [0]
     * @param int|string $minute Default [0]
     */
    public static function saturday(int|string $hour = 0, int|string $minute = 0): CronExpression
    {
        return self::weekly(6, $hour, $minute);
    }

    /**
     * Validate sequence of cron expression.
     *
     * @param int|string|null $minute
     * @param int|string|null $hour
     * @param int|string|null $day
     * @param int|string|null $month
     * @param int|string|null $weekday
     * @return array
     */
    private static function validateCronSequence(
        int|string|null $minute = null,
        int|string|null $hour = null,
        int|string|null $day = null,
        int|string|null $month = null,
        int|string|null $weekday = null
    ): array {
        return [
            'minute' => self::validateCronRange($minute, 0, 59),
            'hour' => self::validateCronRange($hour, 0, 23),
            'day' => self::validateCronRange($day, 1, 31),
            'month' => self::validateCronRange($month, 1, 12),
            'weekday' => self::validateCronRange($weekday, 0, 6),
        ];
    }

    /**
     * Validate sequence of cron expression.
     *
     * @param int|string|null $value
     * @param int $min
     * @param int $max
     * @return int|string
     */
    private static function validateCronRange(int|string|null $value, int $min, int $max): int|string
    {
        if (null === $value || '*' === $value) {
            return '*';
        }

        if (!is_numeric($value) || !($value >= $min && $value <= $max)) {
            throw new InvalidArgumentException(
                "Invalid value: it should be '*' or between {$min} and {$max}."
            );
        }

        return (int)$value;
    }

    private function __construct()
    {
    }
}
