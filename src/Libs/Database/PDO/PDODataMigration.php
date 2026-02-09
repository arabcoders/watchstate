<?php

declare(strict_types=1);

namespace App\Libs\Database\PDO;

use App\Libs\Config;
use App\Libs\Database\DBLayer;
use App\Libs\Entity\StateInterface as iFace;
use App\Libs\Guid;
use PDO;
use Psr\Log\LoggerInterface;

/**
 * Class PDODataMigration
 *
 * This class is responsible for migrating data from old database schema to new versions.
 */
final class PDODataMigration
{
    /**
     * @var string Declares the version of the software.
     */
    private readonly string $version;

    /**
     * @var string $dbPath The path to the SQLite database file.
     */
    private readonly string $dbPath;

    /**
     * Creates a variable $jFlags with the following JSON options:
     * - JSON_UNESCAPED_SLASHES: don't escape slashes
     * - JSON_UNESCAPED_UNICODE: encode multibyte Unicode characters literally
     * - JSON_INVALID_UTF8_IGNORE: ignore invalid UTF-8 characters
     * - JSON_THROW_ON_ERROR: throw an exception on JSON errors
     *
     * @var int $jFlags The bit mask that represents the JSON options
     */
    private int $jFlags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_IGNORE | JSON_THROW_ON_ERROR;

    /**
     * Class constructor.
     *
     * @param DBLayer $db The PDO instance to use for database connection.
     * @param LoggerInterface $logger The logger instance to use for logging.
     */
    public function __construct(
        private DBLayer $db,
        private LoggerInterface $logger,
    ) {
        $this->version = Config::get('database.version');
        $this->dbPath = dirname(after(Config::get('database.dsn'), 'sqlite:'));
    }

    /**
     * Sets the logger instance for logging.
     *
     * @param LoggerInterface $logger The logger instance to use for logging.
     *
     * @return self The current instance.
     */
    public function setLogger(LoggerInterface $logger): self
    {
        $this->logger = $logger;
        return $this;
    }

    /**
     * Get the result from the automatic method call based on the version variable.
     *
     * @return mixed The result from the automatic method call or an empty string if the method doesn't exist or the version variable doesn't match.
     */
    public function automatic(): mixed
    {
        foreach (get_class_methods($this) as $method) {
            if ($this->version !== $method) {
                continue;
            }
            return $this->{$method}();
        }

        return '';
    }

    /**
     * pre-alpha table design.
     *
     * --------------------------
     * CREATE TABLE IF NOT EXISTS "state"
     * (
     *  "id"          integer NOT NULL PRIMARY KEY AUTOINCREMENT,
     *  "type"        text    NOT NULL,
     *  "updated"     integer NOT NULL,
     *  "watched"     integer NOT NULL DEFAULT 0,
     *  "meta"        text    NULL,
     *  "guid_plex"   text    NULL,
     *  "guid_imdb"   text    NULL,
     *  "guid_tvdb"   text    NULL,
     *  "guid_tmdb"   text    NULL,
     *  "guid_tvmaze" text    NULL,
     *  "guid_tvrage" text    NULL,
     *  "guid_anidb"  text    NULL
     * );
     * --------------------------
     *
     * @param string|null $oldDBFile
     * @return mixed
     */
    public function v0(?string $oldDBFile = null): mixed
    {
        $automatic = $oldDBFile ?? $this->dbPath . DIRECTORY_SEPARATOR . 'watchstate.db';

        if (true !== file_exists($automatic)) {
            return false;
        }

        $this->logger->notice('PDODataMigration: Migrating database data from pre-alpha version to v0');

        $oldDB = new PDO(
            dsn: r('sqlite:{file}', ['file' => $automatic]),
            options: [
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_STRINGIFY_FETCHES => false,
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY,
            ],
        );

        if (!$this->db->inTransaction()) {
            $this->db->start();
        }

        $columns = implode(', ', iFace::ENTITY_KEYS);
        $binds = ':' . implode(', :', iFace::ENTITY_KEYS);

        /** @noinspection SqlInsertValues */
        $insert = $this->db->prepare("INSERT INTO state ({$columns}) VALUES({$binds})");

        $stmt = $oldDB->query('SELECT * FROM state');

        foreach ($stmt as $row) {
            $row['meta'] = json_decode(
                json: $row['meta'] ?? '[]',
                associative: true,
                flags: JSON_INVALID_UTF8_IGNORE,
            );

            $extra = [
                iFace::COLUMN_EXTRA_DATE => $row[iFace::COLUMN_UPDATED],
            ];

            $metadata = [
                iFace::COLUMN_TYPE => $row[iFace::COLUMN_TYPE],
                iFace::COLUMN_WATCHED => $row[iFace::COLUMN_WATCHED],
                iFace::COLUMN_TITLE => ag($row['meta'], ['series', 'title'], '??'),
                iFace::COLUMN_SEASON => ag($row['meta'], iFace::COLUMN_SEASON, null),
                iFace::COLUMN_EPISODE => ag($row['meta'], iFace::COLUMN_EPISODE, null),
                iFace::COLUMN_META_DATA_EXTRA => [],
            ];

            if (null !== ($date = ag($row['meta'], iFace::COLUMN_META_DATA_EXTRA_DATE))) {
                $metadata[iFace::COLUMN_META_DATA_EXTRA][iFace::COLUMN_META_DATA_EXTRA_DATE] = $date;
            }

            if (iFace::TYPE_EPISODE === $row[iFace::COLUMN_TYPE] && null !== ($title = ag($row['meta'], 'title'))) {
                $metadata[iFace::COLUMN_META_DATA_EXTRA][iFace::COLUMN_META_DATA_EXTRA_TITLE] = $title;
            }

            if (null !== ($whEvent = ag($row['meta'], 'webhook.event'))) {
                $extra[iFace::COLUMN_EXTRA_EVENT] = $whEvent;
            }

            $metadata[iFace::COLUMN_YEAR] = ag($row['meta'], iFace::COLUMN_YEAR, null);

            if (0 === $metadata[iFace::COLUMN_YEAR] || null === $metadata[iFace::COLUMN_YEAR]) {
                $metadata[iFace::COLUMN_YEAR] = '0000';
            }

            $insert->execute([
                iFace::COLUMN_ID => $row[iFace::COLUMN_ID],
                iFace::COLUMN_TYPE => $row[iFace::COLUMN_TYPE],
                iFace::COLUMN_UPDATED => $row[iFace::COLUMN_UPDATED],
                iFace::COLUMN_WATCHED => $row[iFace::COLUMN_WATCHED],
                iFace::COLUMN_VIA => ag($row['meta'], iFace::COLUMN_VIA, 'before_v0'),
                iFace::COLUMN_TITLE => ag($row['meta'], ['series', iFace::COLUMN_TITLE], '??'),
                iFace::COLUMN_YEAR => $metadata[iFace::COLUMN_YEAR],
                iFace::COLUMN_SEASON => ag($row['meta'], iFace::COLUMN_SEASON, null),
                iFace::COLUMN_EPISODE => ag($row['meta'], iFace::COLUMN_EPISODE, null),
                iFace::COLUMN_PARENT => json_encode(
                    value: array_intersect_key(
                        $row,
                        ag($row['meta'], iFace::COLUMN_PARENT, []),
                        Guid::getSupported(),
                    ),
                    flags: $this->jFlags,
                ),
                iFace::COLUMN_GUIDS => json_encode(
                    value: array_intersect_key($row, Guid::getSupported()),
                    flags: $this->jFlags,
                ),
                iFace::COLUMN_META_DATA => json_encode(
                    value: (static function () use ($metadata): array {
                        $list = [];

                        foreach (Config::get('servers', []) as $name => $info) {
                            if (true !== (bool) ag($info, 'import.enabled', false)) {
                                continue;
                            }
                            $list[$name] = $metadata;
                        }

                        return $list;
                    })(),
                    flags: $this->jFlags,
                ),
                iFace::COLUMN_EXTRA => json_encode(
                    value: (static function () use ($extra): array {
                        $list = [];

                        foreach (Config::get('servers', []) as $name => $info) {
                            if (true !== (bool) ag($info, 'import.enabled', false)) {
                                continue;
                            }
                            $list[$name] = $extra;
                        }

                        return $list;
                    })(),
                    flags: $this->jFlags,
                ),
            ]);
        }

        if ($this->db->inTransaction()) {
            $this->db->commit();
        }

        $stmt = null;
        $oldDB = null;

        if (null === $oldDBFile) {
            rename($automatic, $this->dbPath . '/archive/' . basename($automatic));
        }

        $this->logger->notice('PDODataMigration: Database data migration is successful.');

        return true;
    }

    /**
     * v0 table design
     * --------------------------------------------------
     * CREATE TABLE "state"
     * (
     *  "id"      integer NOT NULL PRIMARY KEY AUTOINCREMENT,
     *  "type"    text    NOT NULL,
     *  "updated" integer NOT NULL,
     *  "watched" integer NOT NULL DEFAULT '0',
     *  "via"     text    NOT NULL,
     *  "title"   text    NOT NULL,
     *  "year"    integer NULL,
     *  "season"  integer NULL,
     *  "episode" integer NULL,
     *  "parent"  text    NULL,
     *  "guids"   text    NULL,
     *  "extra"   text    NULL
     * );
     * -----------------------
     * @param string|null $oldDBFile
     * @return mixed
     */
    public function v01(?string $oldDBFile = null): mixed
    {
        $automatic = $oldDBFile ?? $this->dbPath . DIRECTORY_SEPARATOR . 'watchstate_v0.db';

        if (true !== file_exists($automatic)) {
            return false;
        }

        if (null === $oldDBFile) {
            $this->v0();
        }

        $this->logger->notice('PDODataMigration: Migrating database data from v0.0 version to v0.1');

        $oldDB = new PDO(dsn: sprintf('sqlite:%s', $automatic), options: [
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY,
        ]);

        if (!$this->db->inTransaction()) {
            $this->db->start();
        }

        $columns = implode(', ', iFace::ENTITY_KEYS);
        $binds = ':' . implode(', :', iFace::ENTITY_KEYS);

        /** @noinspection SqlInsertValues */
        $insert = $this->db->prepare("INSERT INTO state ({$columns}) VALUES({$binds})");

        foreach ($oldDB->query('SELECT * FROM state') as $row) {
            $row[iFace::COLUMN_EXTRA] = json_decode(
                json: $row[iFace::COLUMN_EXTRA] ?? '[]',
                associative: true,
                flags: JSON_INVALID_UTF8_IGNORE,
            );

            $row[iFace::COLUMN_GUIDS] = json_decode(
                json: $row[iFace::COLUMN_GUIDS] ?? '[]',
                associative: true,
                flags: JSON_INVALID_UTF8_IGNORE,
            );

            $row[iFace::COLUMN_PARENT] = json_decode(
                json: $row[iFace::COLUMN_PARENT] ?? '[]',
                associative: true,
                flags: JSON_INVALID_UTF8_IGNORE,
            );

            $row['suids'] = json_decode(
                json: $row['suids'] ?? '[]',
                associative: true,
                flags: JSON_INVALID_UTF8_IGNORE,
            );

            $extra = [
                iFace::COLUMN_EXTRA_DATE => (string) $row[iFace::COLUMN_UPDATED],
            ];

            $metadata = [
                iFace::COLUMN_TYPE => $row[iFace::COLUMN_TYPE],
                iFace::COLUMN_UPDATED => (string) $row[iFace::COLUMN_UPDATED],
                iFace::COLUMN_WATCHED => (string) $row[iFace::COLUMN_WATCHED],
                iFace::COLUMN_TITLE => $row[iFace::COLUMN_TITLE],
                iFace::COLUMN_YEAR => isset($row[iFace::COLUMN_YEAR]) ? (string) $row[iFace::COLUMN_YEAR] : null,
            ];

            if (iFace::TYPE_EPISODE === $row['type']) {
                $metadata[iFace::COLUMN_SEASON] = (string) $row[iFace::COLUMN_SEASON];
                $metadata[iFace::COLUMN_EPISODE] = (string) $row[iFace::COLUMN_EPISODE];
            }

            $metadata[iFace::COLUMN_META_DATA_EXTRA] = [];

            if (null !== ($date = ag($row[iFace::COLUMN_EXTRA], iFace::COLUMN_META_DATA_EXTRA_DATE))) {
                $metadata[iFace::COLUMN_META_DATA_EXTRA][iFace::COLUMN_META_DATA_EXTRA_DATE] = $date;
            }

            if (iFace::TYPE_EPISODE === $row['type']) {
                if (null !== ($title = ag($row[iFace::COLUMN_EXTRA], iFace::COLUMN_META_DATA_EXTRA_TITLE))) {
                    $metadata[iFace::COLUMN_META_DATA_EXTRA][iFace::COLUMN_META_DATA_EXTRA_TITLE] = $title;
                }
            }

            if (null !== ($whEvent = ag($row[iFace::COLUMN_EXTRA], 'webhook.event'))) {
                $extra[iFace::COLUMN_EXTRA_EVENT] = $whEvent;
            }

            $arr = [
                iFace::COLUMN_ID => $row[iFace::COLUMN_ID],
                iFace::COLUMN_TYPE => $row[iFace::COLUMN_TYPE],
                iFace::COLUMN_UPDATED => $row[iFace::COLUMN_UPDATED],
                iFace::COLUMN_WATCHED => $row[iFace::COLUMN_WATCHED],
                iFace::COLUMN_VIA => $row[iFace::COLUMN_VIA] ?? 'before_v01',
                iFace::COLUMN_TITLE => $row[iFace::COLUMN_TITLE],
                iFace::COLUMN_YEAR => $row[iFace::COLUMN_YEAR] ?? '0000',
                iFace::COLUMN_SEASON => $row[iFace::COLUMN_SEASON] ?? null,
                iFace::COLUMN_EPISODE => $row[iFace::COLUMN_EPISODE] ?? null,
                iFace::COLUMN_PARENT => json_encode(
                    value: array_intersect_key(
                        $row[iFace::COLUMN_PARENT] ?? [],
                        Guid::getSupported(),
                    ),
                    flags: $this->jFlags,
                ),
                iFace::COLUMN_GUIDS => json_encode(
                    value: array_intersect_key($row[iFace::COLUMN_GUIDS], Guid::getSupported()),
                    flags: $this->jFlags,
                ),
                iFace::COLUMN_META_DATA => json_encode(
                    value: (static function () use ($row, $metadata): array {
                        $list = [];

                        foreach (Config::get('servers', []) as $name => $info) {
                            $list[$name] = [];
                            if (null !== ($row['suids'][$name] ?? null)) {
                                $list[$name][iFace::COLUMN_ID] = $row['suids'][$name];
                            }

                            $list[$name] += $metadata;
                        }

                        return $list;
                    })(),
                    flags: $this->jFlags,
                ),
                iFace::COLUMN_EXTRA => json_encode(
                    value: (static function () use ($row, $extra): array {
                        $list = [];

                        foreach (Config::get('servers', []) as $name => $info) {
                            $list[$name] = [];

                            if (null !== ($row['suids'][$name] ?? null)) {
                                continue;
                            }

                            $list[$name] += $extra;
                        }

                        return $list;
                    })(),
                    flags: $this->jFlags,
                ),
            ];

            $insert->execute($arr);
        }

        if ($this->db->inTransaction()) {
            $this->db->commit();
        }

        $oldDB = null;

        if (null === $oldDBFile) {
            rename($automatic, $this->dbPath . '/archive/' . basename($automatic));
        }

        $this->logger->notice('PDODataMigration: Database data migration is successful.');

        return true;
    }
}
