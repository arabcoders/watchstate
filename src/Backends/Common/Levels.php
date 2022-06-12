<?php

declare(strict_types=1);

namespace App\Backends\Common;

enum Levels: string
{
    /**
     * Detailed debug information
     */
    case DEBUG = 'DEBUG';

    /**
     * Interesting events
     *
     * Examples: User logs in, SQL logs.
     */
    case INFO = 'INFO';

    /**
     * Uncommon events
     */
    case NOTICE = 'NOTICE';

    /**
     * Exceptional occurrences that are not errors
     *
     * Examples: Use of deprecated APIs, poor use of an API,
     * undesirable things that are not necessarily wrong.
     */
    case WARNING = 'WARNING';

    /**
     * Runtime errors
     */
    case ERROR = 'ERROR';

    /**
     * Critical conditions
     *
     * Example: Application component unavailable, unexpected exception.
     */
    case CRITICAL = 'CRITICAL';

    /**
     * Action must be taken immediately
     *
     * Example: Entire website down, database unavailable, etc.
     * This should trigger the SMS alerts and wake you up.
     */
    case ALERT = 'ALERT';

    /**
     * Urgent alert.
     */
    case EMERGENCY = 'EMERGENCY';

}
