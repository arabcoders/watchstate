<?php

declare(strict_types=1);

namespace App\Model\Events;

use App\libs\Extends\Date;
use App\Libs\Extends\JsonlFormatter;
use App\Libs\Options;
use App\Model\Base\Transformers\ArrayTransformer;
use App\Model\Base\Transformers\DateTransformer;
use App\Model\Base\Transformers\EnumTransformer;
use App\Model\Events\EventsTable as EntityTable;
use App\Model\Events\EventValidation as EntityValidation;
use Monolog\Level;

final class Event extends EntityTable
{
    public const int MAX_LOG_ENTRIES = 200;

    /**
     * Lazily initialised JSONL formatter shared across all event instances.
     */
    private static ?JsonlFormatter $jsonlFormatter = null;

    protected string $primaryKey = EntityTable::TABLE_PRIMARY_KEY;

    /**
     * @implements EntityTable::COLUMN_ID
     */
    public ?string $id = null;

    /**
     * @implements EntityTable::COLUMN_STATUS
     */
    public EventStatus $status = EventStatus::PENDING;

    /**
     * @implements EntityTable::COLUMN_REFERENCE
     */
    public ?string $reference = null;

    /**
     * @implements EntityTable::COLUMN_EVENT
     */
    public string $event = '';

    /**
     * @implements EntityTable::COLUMN_EVENT_DATA
     */
    public array $event_data = [];

    /**
     * @implements EntityTable::COLUMN_OPTIONS
     */
    public array $options = [];

    /**
     * @implements EntityTable::COLUMN_ATTEMPTS
     */
    public int $attempts = 0;

    /**
     * @implements EntityTable::COLUMN_LOGS
     */
    public array $logs = [];

    /**
     * @implements EntityTable::COLUMN_CREATED_AT
     */
    public Date|string $created_at = '';

    /**
     * @implements EntityTable::COLUMN_UPDATED_AT
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

    /**
     * Add a formatted log entry if the current event options allow it.
     *
     * @param array<string, mixed> $context
     *
     * @return bool True when the log entry was persisted.
     */
    public function addLog(Level $level, string $message, array $context = []): bool
    {
        if (false === (bool) ($this->options[Options::DEBUG_TRACE] ?? false) && $level->value < Level::Info->value) {
            return false;
        }

        self::$jsonlFormatter ??= new JsonlFormatter();

        $formatted = r_array(text: $message, context: $context, opts: ['log_behavior' => true]);

        $this->addRawLog(self::$jsonlFormatter->formatValues(
            channel: 'event',
            level: $level,
            message: $formatted['message'],
            context: $context,
        ));

        return true;
    }

    /**
     * Add a raw log entry without applying any level-based filtering.
     */
    public function addRawLog(string $log): void
    {
        if (count($this->logs) >= self::MAX_LOG_ENTRIES) {
            array_shift($this->logs);
        }

        $this->logs[] = $log;
    }

    /**
     * Remove all log entries for this event.
     */
    public function clearLogs(): void
    {
        $this->logs = [];
    }

    public function validate(): bool
    {
        if ($this->isCustom) {
            return false;
        }

        return new EntityValidation($this)->isValid();
    }
}
