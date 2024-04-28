<?php

declare(strict_types=1);

namespace App\Libs\Extends;

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
        if (ctype_digit($time)) {
            $time = '@' . $time;
        }

        parent::__construct($time, $timezone);
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
