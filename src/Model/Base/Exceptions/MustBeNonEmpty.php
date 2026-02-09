<?php

declare(strict_types=1);

namespace App\Model\Base\Exceptions;

use RuntimeException;

/**
 * MustBeNonEmpty Thrown when Value is empty.
 *
 * @package Model\Base\Exceptions
 */
class MustBeNonEmpty extends RuntimeException {}
