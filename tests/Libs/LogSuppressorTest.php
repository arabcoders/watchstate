<?php
/** @noinspection PhpMultipleClassDeclarationsInspection */

declare(strict_types=1);

namespace Tests\Libs;

use App\Libs\LogSuppressor;
use App\Libs\TestCase;
use Monolog\Handler\TestHandler;
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
        $this->handler = new TestHandler();
        $this->suppressor = new LogSuppressor($this->testData);
        $this->logger = new Logger('logger');
        $this->logger->pushHandler($this->suppressor->withHandler($this->handler));
        parent::setUp();
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
}
