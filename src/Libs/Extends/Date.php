<?php

declare(strict_types=1);

namespace App\Libs\Extends;

use DateTimeImmutable;
use DateTimeInterface;
use JsonSerializable;
use Stringable;

final class Date extends DateTimeImmutable implements Stringable, JsonSerializable
{
    public function __toString(): string
    {
        return $this->format(DateTimeInterface::ATOM);
    }

    public function jsonSerialize(): string
    {
        return $this->__toString();
    }
}
