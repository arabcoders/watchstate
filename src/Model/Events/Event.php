<?php

declare(strict_types=1);

namespace App\Model\Events;

use App\libs\Extends\Date;
use App\Model\Base\Transformers\ArrayTransformer;
use App\Model\Base\Transformers\DateTransformer;
use App\Model\Base\Transformers\EnumTransformer;
use App\Model\Events\EventsTable as EntityTable;
use App\Model\Events\EventValidation as EntityValidation;

final class Event extends EntityTable
{
    protected string $primaryKey = EntityTable::TABLE_PRIMARY_KEY;

    /**
     * @uses EntityTable::COLUMN_ID
     */
    public string|null $id = null;

    /**
     * @uses EntityTable::COLUMN_STATUS
     */
    public EventStatus $status = EventStatus::PENDING;

    /**
     * @uses EntityTable::COLUMN_REFERENCE
     */
    public string|null $reference = null;

    /**
     * @uses EntityTable::COLUMN_EVENT
     */
    public string $event = '';

    /**
     * @uses EntityTable::COLUMN_EVENT_DATA
     */
    public array $event_data = [];

    /**
     * @uses EntityTable::COLUMN_OPTIONS
     */
    public array $options = [];

    /**
     * @uses EntityTable::COLUMN_ATTEMPTS
     */
    public int $attempts = 0;

    /**
     * @uses EntityTable::COLUMN_LOGS
     */
    public array $logs = [];

    /**
     * @uses EntityTable::COLUMN_CREATED_AT
     */
    public Date|string $created_at = '';

    /**
     * @uses EntityTable::COLUMN_UPDATED_AT
     */
    public Date|string|null $updated_at = null;

    protected function init(array &$data, bool &$isCustom, array &$options): void
    {
        $this->transform = [
            EntityTable::COLUMN_STATUS => EnumTransformer::create(EventStatus::class),
            EntityTable::COLUMN_EVENT_DATA => ArrayTransformer::class,
            EntityTable::COLUMN_LOGS => ArrayTransformer::class,
            EntityTable::COLUMN_OPTIONS => ArrayTransformer::class,
            EntityTable::COLUMN_CREATED_AT => DateTransformer::class,
            EntityTable::COLUMN_UPDATED_AT => DateTransformer::create(nullable: true),
        ];
    }

    public function getStatusText(): string
    {
        return ucfirst(strtolower($this->status->name));
    }

    public function validate(): bool
    {
        if ($this->isCustom) {
            return false;
        }

        return new EntityValidation($this)->isValid();
    }

}
