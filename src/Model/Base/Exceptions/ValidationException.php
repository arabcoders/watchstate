<?php

declare(strict_types=1);

namespace App\Model\Base\Exceptions;

use InvalidArgumentException;

/**
 * All Validation Should Extend this class.
 */
class ValidationException extends InvalidArgumentException {}
