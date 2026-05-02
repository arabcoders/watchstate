<?php

declare(strict_types=1);

namespace Tests\Libs;

use App\Libs\Config;
use App\Libs\TestCase;
use Tests\fixtures\Prune\FakePruner;
use Tests\fixtures\Prune\MethodPruner;

final class PruneHelpersTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        require_once __DIR__ . '/../fixtures/Prune/FakePruner.php';
        require_once __DIR__ . '/../fixtures/Prune/AnotherPruner.php';
        require_once __DIR__ . '/../fixtures/Prune/MethodPruner.php';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->initTempApp();
        Config::save('prune.paths', [ROOT_PATH . '/tests/fixtures/Prune']);
        Config::save('prune.cache.time', 0);

        FakePruner::$calls = [];
        MethodPruner::$calls = [];
    }

    public function test_discover(): void
    {
        $pruners = discover_pruners();

        self::assertSame(['another_pruner', 'fake_pruner', 'method_pruner'], array_keys($pruners));
        self::assertSame('another_pruner', $pruners['another_pruner']['name']);
        self::assertSame('0 5 * * *', $pruners['another_pruner']['cron']);
        self::assertSame('Another test pruner.', $pruners['another_pruner']['desc']);
        self::assertFalse($pruners['another_pruner']['enabled']);

        self::assertSame('fake_pruner', $pruners['fake_pruner']['name']);
        self::assertSame('* * * * *', $pruners['fake_pruner']['cron']);
        self::assertSame('Fake test pruner.', $pruners['fake_pruner']['desc']);
        self::assertTrue($pruners['fake_pruner']['enabled']);

        self::assertSame('method_pruner', $pruners['method_pruner']['name']);
        self::assertSame('*/15 * * * *', $pruners['method_pruner']['cron']);
        self::assertSame('Method-based test pruner.', $pruners['method_pruner']['desc']);
    }

    public function test_call(): void
    {
        $pruners = discover_pruners();

        $pruners['fake_pruner']['item']->call(true);
        $pruners['method_pruner']['item']->call(false);

        self::assertSame([true], FakePruner::$calls);
        self::assertSame([false], MethodPruner::$calls);
    }

    public function test_paths(): void
    {
        Config::save('prune.paths', [ROOT_PATH . '/src/Commands/Prune']);
        Config::save('prune.cache.time', 0);

        $pruners = discover_pruners([ROOT_PATH . '/tests/fixtures/Prune']);

        self::assertArrayHasKey('fake_pruner', $pruners);
        self::assertArrayHasKey('method_pruner', $pruners);
        self::assertArrayNotHasKey('command_sessions', $pruners);
    }
}
