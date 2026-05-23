<?php

declare(strict_types=1);

namespace Tests\Mappers\Import;

use App\Libs\Entity\StateEntity;
use App\Libs\Extends\LogMessageProcessor;
use App\Libs\Mappers\Import\RestoreMapper;
use App\Libs\TestCase;
use Monolog\Handler\TestHandler;
use Monolog\Logger;

final class RestoreMapperTest extends TestCase
{
    public function test_logs_ignored_item_without_supported_ids(): void
    {
        $handler = new TestHandler();
        $logger = new Logger('test', [$handler], [new LogMessageProcessor()]);
        $mapper = new RestoreMapper($logger, 'unused.json');
        $entity = require __DIR__ . '/../../Fixtures/MovieEntity.php';
        $entity['title'] = 'Missing GUIDs';
        $entity['guids'] = [];
        $entity['parent'] = [];

        $mapper->add(new StateEntity($entity));

        $records = $handler->getRecords();
        self::assertCount(1, $records);
        self::assertSame('mapper.restore.item.ignored', $records[0]->context['event_name']);
        self::assertSame('Missing GUIDs (2020)', $records[0]->context['item_title']);
        self::assertSame('no_supported_external_ids', $records[0]->context['reason']);
    }
}
