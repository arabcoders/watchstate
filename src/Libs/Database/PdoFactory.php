<?php

declare(strict_types=1);

namespace App\Libs\Database;

use App\Libs\Config;
use App\Libs\Exceptions\RuntimeException;
use PDO;

final class PdoFactory
{
    public const string DB_FILE = 'watchstate_v02.db';

    public const string OLD_DB_FILE = 'watchstate_v01.db';
    public const string OLD_USER_DB_FILE = 'user_v01.db';

    /**
     * Create the main application PDO connection.
     */
    public function createMain(): PDO
    {
        $inTestMode = $this->inTestMode();

        return $this->create(
            dsn: true === $inTestMode ? 'sqlite::memory:' : (string) Config::get('database.dsn'),
            file: true === $inTestMode ? null : (string) Config::get('database.file'),
            username: Config::get('database.username'),
            password: Config::get('database.password'),
        );
    }

    /**
     * Create a per-user PDO connection.
     */
    public function createUser(string $user, ?bool &$created = null): PDO
    {
        $dbFile = get_user_db($user);
        $inTestMode = $this->inTestMode();
        $created = true === $inTestMode ? true : false === file_exists($dbFile);

        return $this->create(
            dsn: r('sqlite:{src}', ['src' => true === $inTestMode ? ':memory:' : $dbFile]),
            file: true === $inTestMode ? null : $dbFile,
        );
    }

    /**
     * Create a SQLite PDO connection for the provided file path.
     */
    public function createForFile(string $file): PDO
    {
        return $this->create(
            dsn: 'sqlite:' . $file,
            file: $file,
        );
    }

    /**
     * Create a PDO connection and apply the configured driver bootstrap SQL.
     */
    public function create(
        string $dsn,
        ?string $file = null,
        ?string $username = null,
        #[\SensitiveParameter]
        ?string $password = null,
    ): PDO {
        $changePerm = false;

        if (null !== $file) {
            $changePerm = false === file_exists($file);
            $dir = dirname($file);
            if (false === is_dir($dir) && false === @mkdir($dir, 0o755, true) && false === is_dir($dir)) {
                throw new RuntimeException(r("Unable to create '{path}' directory.", ['path' => $dir]));
            }
        }

        $args = [
            'dsn' => $dsn,
            'options' => Config::get('database.options', []),
        ];

        if (null !== $username) {
            $args['username'] = $username;
        }

        if (null !== $password) {
            $args['password'] = $password;
        }

        $pdo = new PDO(...$args);

        foreach (Config::get('database.exec.' . $pdo->getAttribute(PDO::ATTR_DRIVER_NAME), []) as $cmd) {
            $pdo->exec($cmd);
        }

        if (
            null !== $file
            && true === str_starts_with($dsn, 'sqlite:')
            && true === $changePerm
            && true === in_container()
            && 777 !== (int) decoct(fileperms($file) & 0o777)
        ) {
            @chmod($file, 0o777);
        }

        return $pdo;
    }

    private function inTestMode(): bool
    {
        return true === (defined('IN_TEST_MODE') && true === IN_TEST_MODE);
    }
}
