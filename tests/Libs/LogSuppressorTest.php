<?php
/** @noinspection PhpVoidFunctionResultUsedInspection */

/** @noinspection PhpMultipleClassDeclarationsInspection */

declare(strict_types=1);

namespace Tests\Libs;

use App\Libs\LogSuppressor;
use App\Libs\TestCase;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger;

class LogSuppressorTest extends TestCase
{
    private Logger|null $logger = null;
    protected TestHandler|null $handler = null;

    private array $testData = [
        'A7434c91d3440' => [
            'rule' => 'Random string',
            'type' => 'contains',
            'example' => 'this Random string can be anywhere',
        ],
        'A7434c91d3441' => [
            'rule' => '/some random \'(\d+)\'/',
            'type' => 'regex',
            'example' => 'some random \'123\'',
        ],
        'A7434c91d3442' => [
            'rule' => '',
            'type' => 'contains',
        ],
    ];

    private LogSuppressor|null $suppressor = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->handler = new TestHandler(level: Level::Info);
        $this->suppressor = (new LogSuppressor($this->testData))->withHandler($this->handler);
        $this->logger = new Logger('test', handlers: [$this->suppressor]);
    }

    public function test_isSuppressed_type_contains(): void
    {
        $this->assertTrue(
            $this->suppressor->isSuppressed('Random string'),
            'type:contains must match if the entire message is the same as well.'
        );
        $this->assertTrue(
            $this->suppressor->isSuppressed('Any where Random string is found it will be suppressed'),
            'type:contains must match any where in message.'
        );
        $this->assertTrue(
            $this->suppressor->isSuppressed('the locator string is a Random string'),
            'type:contains should also match at the end of the message.'
        );
        $this->assertFalse(
            $this->suppressor->isSuppressed('random string is a random string'),
            'type:contains although the string is present, the case is different.'
        );

        $this->assertFalse($this->suppressor->isSuppressed(''));
    }

    public function test_isSuppressed_type_regex(): void
    {
        $this->assertTrue(
            $this->suppressor->isSuppressed('some random \'123\''),
            'type:regex must match if the entire message is the same as well.'
        );
        $this->assertTrue(
            $this->suppressor->isSuppressed('Any where some random \'123\' is found it will be suppressed'),
            'type:regex must match any where in message.'
        );
        $this->assertTrue(
            $this->suppressor->isSuppressed('the locator string is a some random \'123\''),
            'type:regex should also match at the end of the message.'
        );
        $this->assertFalse(
            $this->suppressor->isSuppressed('some Random \'1234\''),
            'type:regex although the string is present, the case is different.'
        );
    }

    public function test_isSuppressed_empty(): void
    {
        $this->assertFalse($this->suppressor->isSuppressed(''));
    }

    public function test_isSuppressed_with_zero_count(): void
    {
        $suppress = new LogSuppressor([]);
        $this->assertFalse($suppress->isSuppressed('Random string'));
    }

    public function test_logger_handle_wrapper()
    {
        $this->logger->info('Random string');
        $this->logger->info('the locator string is a some random \'123\'');
        $this->assertCount(0, $this->handler->getRecords());

        $this->logger->info('random string');
        $this->assertCount(1, $this->handler->getRecords());
    }

    public function test_isHandling()
    {
        $this->logger->info('test');
        $this->assertFalse(
            $this->suppressor->isHandling($this->handler->getRecords()[0]->with(level: Level::Debug)),
            'Level is below the handler level.'
        );
        $this->handler->clear();

        $this->logger->notice('test');
        $this->assertTrue(
            $this->suppressor->isHandling($this->handler->getRecords()[0]),
            'Level is at or above the handler level.'
        );
    }

    public function test_handleBatch()
    {
        $this->handler->clear();
        $this->logger->info('test');
        $records = $this->handler->getRecords();
        $records[] = $records[0]->with(message: 'Random string');
        $records[] = $records[0]->with(
            message: r('the locator string is a some random \'{number}\'', ['number' => rand(1, 100)])
        );
        $this->handler->clear();
        $this->assertCount(0, $this->handler->getRecords());

        $this->suppressor->handleBatch($records);
        $this->assertCount(1, $this->handler->getRecords());

        $this->assertNull($this->suppressor->close(), 'Close should not throw an error.');
    }
}
