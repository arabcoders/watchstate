<?php

declare(strict_types=1);

namespace Tests\Commands\Database;

use App\Commands\Database\LegacyCommand;
use App\Libs\Database\PackageMigrationFactory;
use App\Libs\Database\PdoFactory;
use App\Libs\TestCase;
use Monolog\Logger;
use PDO;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

final class LegacyCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->initTempApp();
    }

    public function test_all_targets(): void
    {
        mkdir(self::$tmpPath . '/users/alice', 0o755, true);
        mkdir(self::$tmpPath . '/users/bob', 0o755, true);

        $this->createLegacyDb(self::$tmpPath . '/db/' . PdoFactory::OLD_DB_FILE, 'main-ref');
        $this->createLegacyDb(self::$tmpPath . '/users/alice/' . PdoFactory::OLD_USER_DB_FILE, 'alice-ref');
        $this->createLegacyDb(self::$tmpPath . '/users/bob/' . PdoFactory::OLD_USER_DB_FILE, 'bob-ref');

        $tester = $this->makeTester();
        $status = $tester->execute([
            '--execute' => true,
        ]);

        self::assertSame(LegacyCommand::SUCCESS, $status);
        $this->assertMigratedDb(self::$tmpPath . '/db/' . PdoFactory::DB_FILE, 'main-ref');
        $this->assertMigratedDb(self::$tmpPath . '/users/alice/' . PdoFactory::DB_FILE, 'alice-ref');
        $this->assertMigratedDb(self::$tmpPath . '/users/bob/' . PdoFactory::DB_FILE, 'bob-ref');

        self::assertFileDoesNotExist(self::$tmpPath . '/db/' . PdoFactory::OLD_DB_FILE);
        self::assertFileExists(self::$tmpPath . '/db/' . PdoFactory::OLD_DB_FILE . '.migrated');
        self::assertFileExists(self::$tmpPath . '/users/alice/' . PdoFactory::OLD_USER_DB_FILE . '.migrated');
        self::assertFileExists(self::$tmpPath . '/users/bob/' . PdoFactory::OLD_USER_DB_FILE . '.migrated');
    }

    public function test_selected_user(): void
    {
        mkdir(self::$tmpPath . '/users/alice', 0o755, true);
        mkdir(self::$tmpPath . '/users/bob', 0o755, true);

        $this->createLegacyDb(self::$tmpPath . '/users/alice/' . PdoFactory::OLD_USER_DB_FILE, 'alice-ref');
        $this->createLegacyDb(self::$tmpPath . '/users/bob/' . PdoFactory::OLD_USER_DB_FILE, 'bob-ref');

        $tester = $this->makeTester();
        $status = $tester->execute([
            '--execute' => true,
            '--user' => 'alice',
        ]);

        self::assertSame(LegacyCommand::SUCCESS, $status);
        self::assertFileDoesNotExist(self::$tmpPath . '/db/' . PdoFactory::DB_FILE);
        $this->assertMigratedDb(self::$tmpPath . '/users/alice/' . PdoFactory::DB_FILE, 'alice-ref');
        self::assertFileDoesNotExist(self::$tmpPath . '/users/bob/' . PdoFactory::DB_FILE);
        self::assertFileExists(self::$tmpPath . '/users/bob/' . PdoFactory::OLD_USER_DB_FILE);
    }

    public function test_skip_non_empty(): void
    {
        $legacy = self::$tmpPath . '/db/' . PdoFactory::OLD_DB_FILE;
        $target = self::$tmpPath . '/db/' . PdoFactory::DB_FILE;

        $this->createLegacyDb($legacy, 'main-ref');
        $this->createV2DbWithState($target, 'existing-ref');

        $tester = $this->makeTester();
        $status = $tester->execute([
            '--execute' => true,
        ]);

        self::assertSame(LegacyCommand::SUCCESS, $status);
        self::assertStringContainsString('WARNING main: Target', $tester->getDisplay());
        self::assertFileExists($legacy);
        self::assertFileDoesNotExist($legacy . '.migrated');
        $this->assertExistingV2Db($target, 'existing-ref');
    }

    public function test_force_replace(): void
    {
        $legacy = self::$tmpPath . '/db/' . PdoFactory::OLD_DB_FILE;
        $target = self::$tmpPath . '/db/' . PdoFactory::DB_FILE;

        $this->createLegacyDb($legacy, 'main-ref');
        $this->createV2DbWithState($target, 'existing-ref');

        $tester = $this->makeTester();
        $status = $tester->execute([
            '--execute' => true,
            '--force' => true,
        ]);

        self::assertSame(LegacyCommand::SUCCESS, $status);
        $this->assertMigratedDb($target, 'main-ref');
        self::assertFileExists($legacy . '.migrated');
    }

    public function test_remove_backups(): void
    {
        mkdir(self::$tmpPath . '/users/alice', 0o755, true);

        $mainBackup = self::$tmpPath . '/db/' . PdoFactory::OLD_DB_FILE . '.migrated';
        $userBackup = self::$tmpPath . '/users/alice/' . PdoFactory::OLD_USER_DB_FILE . '.migrated';

        $this->createLegacyDb(substr($mainBackup, 0, -9), 'main-ref');
        rename(substr($mainBackup, 0, -9), $mainBackup);

        $this->createLegacyDb(substr($userBackup, 0, -9), 'alice-ref');
        rename(substr($userBackup, 0, -9), $userBackup);

        $tester = $this->makeTester();
        $status = $tester->execute([
            '--remove' => true,
            '--execute' => true,
        ]);

        self::assertSame(LegacyCommand::SUCCESS, $status);
        self::assertFileDoesNotExist($mainBackup);
        self::assertFileDoesNotExist($userBackup);
    }

    private function makeTester(): CommandTester
    {
        $application = new Application();
        $application->addCommand(new LegacyCommand(new PdoFactory(), new PackageMigrationFactory(), new Logger('test')));

        return new CommandTester($application->find(LegacyCommand::ROUTE));
    }

    private function createLegacyDb(string $file, string $reference): void
    {
        $dir = dirname($file);
        if (false === is_dir($dir)) {
            mkdir($dir, 0o755, true);
        }

        $pdo = new PDO('sqlite:' . $file);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $pdo->exec('CREATE TABLE events (id TEXT PRIMARY KEY, status INTEGER NOT NULL DEFAULT 0, reference TEXT NULL, event TEXT NOT NULL, event_data TEXT NOT NULL DEFAULT "{}", options TEXT NOT NULL DEFAULT "{}", attempts INTEGER NOT NULL DEFAULT 0, logs TEXT NOT NULL DEFAULT "{}", created_at INTEGER NOT NULL, updated_at INTEGER NULL)');
        $pdo->exec('CREATE TABLE playlists (id INTEGER PRIMARY KEY AUTOINCREMENT, backend TEXT NOT NULL, backend_id TEXT NOT NULL, title TEXT NOT NULL, type TEXT NOT NULL DEFAULT "video", summary TEXT NULL, is_editable INTEGER NOT NULL DEFAULT 1, is_smart INTEGER NOT NULL DEFAULT 0, is_public INTEGER NOT NULL DEFAULT 0, item_count INTEGER NOT NULL DEFAULT 0, sync_id TEXT NULL, content_hash TEXT NOT NULL DEFAULT "", remote_updated_at INTEGER NOT NULL DEFAULT 0, deleted_at INTEGER NULL, metadata TEXT NOT NULL DEFAULT "{}", created_at INTEGER NOT NULL, updated_at INTEGER NOT NULL, synced_at INTEGER NOT NULL)');
        $pdo->exec('CREATE TABLE playlist_items (id INTEGER PRIMARY KEY AUTOINCREMENT, playlist_id INTEGER NOT NULL, position INTEGER NOT NULL, state_id INTEGER NULL, backend_item_id TEXT NULL, backend_entry_id TEXT NULL, item_type TEXT NULL, title TEXT NOT NULL, metadata TEXT NOT NULL DEFAULT "{}", created_at INTEGER NOT NULL, updated_at INTEGER NOT NULL)');
        $pdo->exec('CREATE TABLE state (id INTEGER PRIMARY KEY AUTOINCREMENT, type TEXT NOT NULL, updated INTEGER NOT NULL, watched INTEGER NOT NULL DEFAULT 0, via TEXT NOT NULL, title TEXT NOT NULL, year INTEGER NULL, season INTEGER NULL, episode INTEGER NULL, parent TEXT NULL, guids TEXT NULL, metadata TEXT NULL, extra TEXT NULL, created_at INTEGER NOT NULL DEFAULT 0, updated_at INTEGER NOT NULL DEFAULT 0)');

        $pdo->exec("INSERT INTO events (id, status, reference, event, event_data, options, attempts, logs, created_at, updated_at) VALUES ('event-1', 1, " . $pdo->quote($reference) . ", 'task.test', '{\"ok\":true}', '{}', 0, '{}', 100, 101)");
        $pdo->exec("INSERT INTO playlists (id, backend, backend_id, title, type, summary, is_editable, is_smart, is_public, item_count, sync_id, content_hash, remote_updated_at, deleted_at, metadata, created_at, updated_at, synced_at) VALUES (7, 'plex', 'pl-1', 'Playlist', 'video', NULL, 1, 0, 0, 1, 'sync-1', 'hash', 100, NULL, '{\"kind\":\"playlist\"}', 100, 101, 102)");
        $pdo->exec("INSERT INTO state (id, type, updated, watched, via, title, year, season, episode, parent, guids, metadata, extra, created_at, updated_at) VALUES (11, 'movie', 100, 1, 'plex', 'Movie', 2024, NULL, NULL, '{\"guid_plex\":\"parent-1\"}', '{\"guid_plex\":\"movie-1\"}', '{\"plex\":{\"id\":1}}', '{\"plex\":{\"path\":\"/media/movie.mkv\"}}', 100, 101)");
        $pdo->exec("INSERT INTO playlist_items (id, playlist_id, position, state_id, backend_item_id, backend_entry_id, item_type, title, metadata, created_at, updated_at) VALUES (13, 7, 1, 11, 'item-1', 'entry-1', 'movie', 'Movie', '{\"rank\":1}', 100, 101)");
    }

    private function createV2DbWithState(string $file, string $reference): void
    {
        $pdo = (new PdoFactory())->createForFile($file);
        $migrations = new PackageMigrationFactory();

        if (false === $migrations->isMigrated($pdo)) {
            $migrations->migrate($pdo, dryRun: false);
        }

        $pdo->exec("INSERT INTO events (id, status, reference, event, event_data, options, attempts, logs, created_at, updated_at) VALUES ('existing', 1, " . $pdo->quote($reference) . ", 'task.test', '{}', '{}', 0, '{}', 1, 1)");
    }

    private function assertMigratedDb(string $file, string $reference): void
    {
        self::assertFileExists($file);

        $pdo = new PDO('sqlite:' . $file);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        self::assertSame($reference, $pdo->query("SELECT reference FROM events WHERE id = 'event-1'")?->fetchColumn());
        self::assertSame('11', (string) $pdo->query("SELECT id FROM state WHERE title = 'Movie'")?->fetchColumn());
        self::assertSame('7', (string) $pdo->query("SELECT id FROM playlists WHERE backend_id = 'pl-1'")?->fetchColumn());
        self::assertSame('13', (string) $pdo->query("SELECT id FROM playlist_items WHERE backend_item_id = 'item-1'")?->fetchColumn());
    }

    private function assertExistingV2Db(string $file, string $reference): void
    {
        self::assertFileExists($file);

        $pdo = new PDO('sqlite:' . $file);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        self::assertSame($reference, $pdo->query("SELECT reference FROM events WHERE id = 'existing'")?->fetchColumn());
        self::assertFalse($pdo->query("SELECT reference FROM events WHERE id = 'event-1'")?->fetchColumn());
    }
}
