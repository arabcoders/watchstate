<?php

declare(strict_types=1);

namespace App\Libs\Exceptions\Backends;

/**
 * Class UnexpectedVersionException
 *
 * This exception is thrown when the requested method is not implemented by the backend.
 */
class NotImplementedException extends BackendException {}
