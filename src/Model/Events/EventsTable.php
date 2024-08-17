<?php

declare(strict_types=1);

namespace App\Model\Events;

use App\Model\Base\BasicModel;

abstract class EventsTable extends BasicModel
{
    public const string TABLE_NAME = 'events';
    public const string TABLE_PRIMARY_KEY = self::COLUMN_ID;

    public const string COLUMN_ID = 'id';
    public const string COLUMN_STATUS = 'status';
    public const string COLUMN_EVENT = 'event';
    public const string COLUMN_EVENT_DATA = 'event_data';
    public const string COLUMN_OPTIONS = 'options';
    public const string COLUMN_ATTEMPTS = 'attempts';
    public const string COLUMN_LOGS = 'logs';
    public const string COLUMN_CREATED_AT = 'created_at';
    public const string COLUMN_UPDATED_AT = 'updated_at';
}
