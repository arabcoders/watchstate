<?php

declare(strict_types=1);

namespace Tests\Libs\Prune;

use App\Libs\Config;
use App\Libs\Prune\CachePruner;
use App\Libs\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;

final class CachePrunerTest extends TestCase
{
    private const string VERSION = 'v1.10.11';

    protected function setUp(): void
    {
        parent::setUp();

        $this->initTempApp();
        Config::save('version', self::VERSION);
    }

    public function test_global(): void
    {
        Config::save('cache.prefix', 'watchstate');

        $root = (string) Config::get('cache.path');
        mkdir($root, 0o755, true);

        $old = [
            'v1.10.10.watchstate',
            'dev-20261110-1a2b3c4.watchstate',
            'master-20261110-1a2b3c4.watchstate',
            'dev-master.watchstate',
        ];

        $preserved = [
            'v1.10.11.watchstate',
            'v1.10.10.other',
            'v1.10.10',
        ];

        foreach ([...$old, ...$preserved] as $namespace) {
            $this->seed($root, $namespace);
        }

        mkdir($root . '/cache-not-managed');
        file_put_contents($root . '/v1.10.8.watchstate', 'keep');
        mkdir((string) Config::get('path') . '/symlink-target');
        symlink((string) Config::get('path') . '/symlink-target', $root . '/v1.9.9.watchstate');

        $pruner = new CachePruner(new NullLogger());

        $pruner(false);

        foreach ($old as $namespace) {
            $this->assertPreserved($root, $namespace);
        }

        $pruner(true);

        foreach ($old as $namespace) {
            self::assertDirectoryDoesNotExist($root . '/' . $namespace);
        }

        foreach ($preserved as $namespace) {
            $this->assertPreserved($root, $namespace);
        }

        self::assertDirectoryExists($root . '/cache-not-managed');
        self::assertFileExists($root . '/v1.10.8.watchstate');
        self::assertTrue(is_link($root . '/v1.9.9.watchstate'));
        self::assertDirectoryExists((string) Config::get('path') . '/symlink-target');
    }

    public function test_global_invalid_prefix(): void
    {
        Config::save('cache.prefix', 'watch-state');

        $root = (string) Config::get('cache.path');
        mkdir($root, 0o755, true);

        $old = [
            'v1.10.10',
            'dev-20261110-1a2b3c4',
            'master-20261110-1a2b3c4',
            'dev-master',
        ];

        $preserved = [
            'v1.10.11',
            'v1.10.10.watch-state',
        ];

        foreach ([...$old, ...$preserved] as $namespace) {
            $this->seed($root, $namespace);
        }

        new CachePruner(new NullLogger())(true);

        foreach ($old as $namespace) {
            self::assertDirectoryDoesNotExist($root . '/' . $namespace);
        }

        foreach ($preserved as $namespace) {
            $this->assertPreserved($root, $namespace);
        }
    }

    public function test_user(): void
    {
        Config::save('cache.prefix', 'watchstate');

        $alice = $this->userRoot('alice');
        $old = [
            'v1.10.10.alice',
            'dev-20261110-1a2b3c4.alice',
            'master-20261110-1a2b3c4.alice',
            'dev-master.alice',
        ];

        $preserved = [
            'v1.10.11.alice',
            'v1.10.10.bob',
        ];

        foreach ([...$old, ...$preserved] as $namespace) {
            $this->seed($alice, $namespace);
        }

        $invalid = $this->userRoot('bad-user');
        $suffix = md5('bad-user');
        $this->seed($invalid, 'v1.10.10.' . $suffix);
        $this->seed($invalid, 'v1.10.11.' . $suffix);

        new CachePruner(new NullLogger())(true);

        foreach ($old as $namespace) {
            self::assertDirectoryDoesNotExist($alice . '/' . $namespace);
        }

        foreach ($preserved as $namespace) {
            $this->assertPreserved($alice, $namespace);
        }

        self::assertDirectoryDoesNotExist($invalid . '/v1.10.10.' . $suffix);
        $this->assertPreserved($invalid, 'v1.10.11.' . $suffix);
    }

    public function test_missing_roots(): void
    {
        $pruner = new CachePruner(new NullLogger());

        $pruner(true);

        mkdir((string) Config::get('path') . '/users/alice', 0o755, true);

        $pruner(true);

        self::assertDirectoryDoesNotExist((string) Config::get('cache.path'));
        self::assertDirectoryDoesNotExist($this->userRoot('alice'));
    }

    private function seed(string $root, string $namespace): void
    {
        $cache = new FilesystemAdapter(namespace: $namespace, directory: $root);
        $item = $cache->getItem('fixture');
        $item->set('value');
        self::assertTrue($cache->save($item));
    }

    private function assertPreserved(string $root, string $namespace): void
    {
        self::assertDirectoryExists($root . '/' . $namespace);
        self::assertTrue(
            new FilesystemAdapter(namespace: $namespace, directory: $root)->hasItem('fixture'),
        );
    }

    private function userRoot(string $user): string
    {
        return (string) Config::get('path') . '/users/' . $user . '/cache';
    }
}
