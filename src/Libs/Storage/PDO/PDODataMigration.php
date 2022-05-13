<?php

declare(strict_types=1);

namespace App\Libs\Storage\PDO;

use App\Libs\Config;
use App\Libs\Entity\StateInterface;
use App\Libs\Guid;
use PDO;
use Psr\Log\LoggerInterface;

final class PDODataMigration
{
    private readonly string $version;
    private readonly string $dbPath;

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
            return 'No pre-release version found.';
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

        $columns = implode(', ', StateInterface::ENTITY_KEYS);
        $binds = ':' . implode(', :', StateInterface::ENTITY_KEYS);

        /** @noinspection SqlInsertValues */
        $insert = $this->pdo->prepare("INSERT INTO state ({$columns}) VALUES({$binds})");

        $stmt = $oldDB->query("SELECT * FROM state");

        foreach ($stmt as $row) {
            $row['meta'] = json_decode(
                json:        $row['meta'] ?? '[]',
                associative: true,
                flags:       JSON_INVALID_UTF8_IGNORE
            );

            $extra = [];

            if (null !== ($whEvent = ag($row['meta'], 'webhook.event'))) {
                $extra['webhook']['event'] = $whEvent;
            }

            if (null !== ($date = ag($row['meta'], 'date'))) {
                $extra['date'] = $date;
            }

            if (StateInterface::TYPE_EPISODE === $row['type'] && null !== ($title = ag($row['meta'], 'title'))) {
                $extra['title'] = $title;
            }

            $year = ag($row['meta'], 'year', null);

            if (0 === $year || null === $year) {
                $year = '0000';
            }

            $insert->execute(
                [
                    'id' => $row['id'],
                    'type' => $row['type'],
                    'updated' => $row['updated'],
                    'watched' => $row['watched'],
                    'via' => ag($row['meta'], 'via', 'before_v0'),
                    'title' => ag($row['meta'], ['series', 'title'], '??'),
                    'year' => $year,
                    'season' => ag($row['meta'], 'season', null),
                    'episode' => ag($row['meta'], 'episode', null),
                    'parent' => json_encode(
                        value: ag($row['meta'], 'parent', []),
                        flags: JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_IGNORE
                    ),
                    'guids' => json_encode(
                        value: array_intersect_key($row, Guid::SUPPORTED),
                        flags: JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_IGNORE
                    ),
                    'extra' => json_encode(
                        value: $extra,
                        flags: JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_IGNORE
                    ),
                ]
            );
        }

        if ($this->pdo->inTransaction()) {
            $this->pdo->commit();
        }

        $stmt = null;
        $oldDB = null;

        if (null === $oldDBFile) {
            rename($automatic, $this->dbPath . '/archive/pre-release.db');
        }

        $this->logger->notice('Database data migration is successful.');

        return 'Data migration is successful.';
    }
}
