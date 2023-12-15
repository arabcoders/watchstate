<?php

declare(strict_types=1);

namespace App\Libs;

use Attribute;

/**
 * Class Routable
 *
 * This class represents a routable attribute. It can be used as an attribute for classes.
 * The attribute can be repeated, and it can target a class.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final class Routable
{
    /**
     * Class constructor.
     *
     * @param string $command The command string.
     *
     * @return void
     */
    public function __construct(public readonly string $command)
    {
    }
}
