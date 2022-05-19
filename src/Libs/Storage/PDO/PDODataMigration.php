<?php

declare(strict_types=1);

namespace App\Libs\Storage\PDO;

use App\Libs\Config;
use App\Libs\Entity\StateInterface as iFace;
use App\Libs\Guid;
use PDO;
use Psr\Log\LoggerInterface;

final class PDODataMigration
{
    private readonly string $version;
    private readonly string $dbPath;

    private int $jFlags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_IGNORE | JSON_THROW_ON_ERROR;

    public function __construct(private PDO $pdo, private LoggerInterface $logger)
    {
        $this->version = Config::get('storage.version');
        $this->dbPath = dirname(after(Config::get('storage.dsn'), 'sqlite:'));
    }

    public function setLogger(LoggerInterface $logger): self
    {
        $this->logger = $logger;
        return $this;
    }

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

    public function v0(string|null $oldDBFile = null): mixed
    {
        $automatic = $oldDBFile ?? $this->dbPath . DIRECTORY_SEPARATOR . 'watchstate.db';

        if (true !== file_exists($automatic)) {
            return false;
        }

        $this->logger->notice('Migrating database data from pre-release version to v0');

        $oldDB = new PDO(dsn: sprintf('sqlite:%s', $automatic), options: [
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY,
        ]);

        if (!$this->pdo->inTransaction()) {
            $this->pdo->beginTransaction();
        }

        $columns = implode(', ', iFace::ENTITY_KEYS);
        $binds = ':' . implode(', :', iFace::ENTITY_KEYS);

        /** @noinspection SqlInsertValues */
        $insert = $this->pdo->prepare("INSERT INTO state ({$columns}) VALUES({$binds})");

        $stmt = $oldDB->query("SELECT * FROM state");

        foreach ($stmt as $row) {
            $row['meta'] = json_decode(
                json:        $row['meta'] ?? '[]',
                associative: true,
                flags:       JSON_INVALID_UTF8_IGNORE
            );

            $metadata = [
                iFace::COLUMN_TYPE => $row[iFace::COLUMN_TYPE],
                iFace::COLUMN_UPDATED => $row[iFace::COLUMN_UPDATED],
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
                $metadata[iFace::COLUMN_META_DATA_EXTRA][iFace::COLUMN_META_DATA_EXTRA_EVENT] = $whEvent;
            }

            $metadata[iFace::COLUMN_YEAR] = ag($row['meta'], iFace::COLUMN_YEAR, null);

            if (0 === $metadata[iFace::COLUMN_YEAR] || null === $metadata[iFace::COLUMN_YEAR]) {
                $metadata[iFace::COLUMN_YEAR] = '0000';
            }

            $insert->execute(
                [
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
                        value: array_intersect_key($row, ag($row['meta'], iFace::COLUMN_PARENT, []), Guid::SUPPORTED),
                        flags: $this->jFlags
                    ),
                    iFace::COLUMN_GUIDS => json_encode(
                        value: array_intersect_key($row, Guid::SUPPORTED),
                        flags: $this->jFlags
                    ),
                    iFace::COLUMN_META_DATA => json_encode(
                        value: (function () use ($metadata): array {
                                 $list = [];

                                 foreach (Config::get('servers', []) as $name => $info) {
                                     if (true !== (bool)ag($info, 'import.enabled', false)) {
                                         continue;
                                     }
                                     $list[$name] = $metadata;
                                 }

                                 return $list;
                             })(),
                        flags: $this->jFlags
                    ),
                    iFace::COLUMN_EXTRA => json_encode([]),
                ]
            );
        }

        if ($this->pdo->inTransaction()) {
            $this->pdo->commit();
        }

        $stmt = null;
        $oldDB = null;

        if (null === $oldDBFile) {
            rename($automatic, $this->dbPath . '/archive/' . basename($automatic));
        }

        $this->logger->notice('Database data migration is successful.');

        return true;
    }

    public function v01(string|null $oldDBFile = null): mixed
    {
        $automatic = $oldDBFile ?? $this->dbPath . DIRECTORY_SEPARATOR . 'watchstate_v0.db';

        if (true !== file_exists($automatic)) {
            return false;
        }

        if (null === $oldDBFile) {
            $this->v0();
        }

        $this->logger->notice('Migrating database data from v0.0 version to v0.1');

        $oldDB = new PDO(dsn: sprintf('sqlite:%s', $automatic), options: [
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY,
        ]);

        if (!$this->pdo->inTransaction()) {
            $this->pdo->beginTransaction();
        }

        $columns = implode(', ', iFace::ENTITY_KEYS);
        $binds = ':' . implode(', :', iFace::ENTITY_KEYS);

        /** @noinspection SqlInsertValues */
        $insert = $this->pdo->prepare("INSERT INTO state ({$columns}) VALUES({$binds})");

        $stmt = $oldDB->query("SELECT * FROM state");

        foreach ($stmt as $row) {
            $row[iFace::COLUMN_EXTRA] = json_decode(
                json:        $row[iFace::COLUMN_EXTRA] ?? '[]',
                associative: true,
                flags:       JSON_INVALID_UTF8_IGNORE
            );

            $row[iFace::COLUMN_GUIDS] = json_decode(
                json:        $row[iFace::COLUMN_GUIDS] ?? '[]',
                associative: true,
                flags:       JSON_INVALID_UTF8_IGNORE
            );

            $row[iFace::COLUMN_PARENT] = json_decode(
                json:        $row[iFace::COLUMN_PARENT] ?? '[]',
                associative: true,
                flags:       JSON_INVALID_UTF8_IGNORE
            );

            $row['suids'] = json_decode(
                json:        $row['suids'] ?? '[]',
                associative: true,
                flags:       JSON_INVALID_UTF8_IGNORE
            );

            $metadata = [
                iFace::COLUMN_TYPE => $row[iFace::COLUMN_TYPE],
                iFace::COLUMN_UPDATED => (string)$row[iFace::COLUMN_UPDATED],
                iFace::COLUMN_WATCHED => (string)$row[iFace::COLUMN_WATCHED],
                iFace::COLUMN_TITLE => $row[iFace::COLUMN_TITLE],
                iFace::COLUMN_YEAR => isset($row[iFace::COLUMN_YEAR]) ? (string)$row[iFace::COLUMN_YEAR] : null,
            ];

            if (iFace::TYPE_EPISODE === $row['type']) {
                $metadata[iFace::COLUMN_SEASON] = (string)$row[iFace::COLUMN_SEASON];
                $metadata[iFace::COLUMN_EPISODE] = (string)$row[iFace::COLUMN_EPISODE];
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
                $metadata[iFace::COLUMN_META_DATA_EXTRA][iFace::COLUMN_META_DATA_EXTRA_EVENT] = $whEvent;
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
                    value: array_intersect_key($row[iFace::COLUMN_PARENT] ?? [], Guid::SUPPORTED),
                    flags: $this->jFlags
                ),
                iFace::COLUMN_GUIDS => json_encode(
                    value: array_intersect_key($row[iFace::COLUMN_GUIDS], Guid::SUPPORTED),
                    flags: $this->jFlags
                ),
                iFace::COLUMN_META_DATA => json_encode(
                    value: (function () use ($row, $metadata): array {
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
                    flags: $this->jFlags
                ),
                iFace::COLUMN_EXTRA => json_encode([]),
            ];

            $insert->execute($arr);
        }

        if ($this->pdo->inTransaction()) {
            $this->pdo->commit();
        }

        $stmt = null;
        $oldDB = null;

        if (null === $oldDBFile) {
            rename($automatic, $this->dbPath . '/archive/' . basename($automatic));
        }

        $this->logger->notice('Database data migration is successful.');

        return true;
    }

}
