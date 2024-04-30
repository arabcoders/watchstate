<?php

declare(strict_types=1);

namespace App\Libs\Extends;

use App\Libs\Exceptions\InvalidArgumentException;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use JsonSerializable;
use Stringable;

final class Date extends DateTimeImmutable implements Stringable, JsonSerializable
{
    public const string ATOM = DateTimeInterface::ATOM;

    public function __construct(string|int $time = 'now', ?DateTimeZone $timezone = null)
    {
        $ori = $time;

        if (ctype_digit($time)) {
            $time = '@' . $time;
        }

        try {
            parent::__construct($time, $timezone);
        } catch (\TypeError $e) {
            throw new InvalidArgumentException(
                r("DateTime constructor received invalid time argument \'{arg}\' - {ori}. {message}", [
                    'arg' => $time,
                    'ori' => $ori,
                    'message' => $e->getMessage(),
                ]), $e->getCode(), $e
            );
        }
    }

    public function __toString(): string
    {
        return $this->format(DateTimeInterface::ATOM);
    }

    public function jsonSerialize(): string
    {
        return $this->__toString();
    }
}
