<?php

use App\Libs\Config;
use App\Libs\Entity\StateInterface;
use App\Libs\Guid;
use Psr\Log\LoggerInterface;

return function (string $version, PDO $newDB, LoggerInterface|null $logger = null) {
    $dbFile = after(Config::get('storage.dsn'), 'sqlite:');
    $dbPath = dirname($dbFile);

    if ('v0' === $version) {
        (function () use ($dbPath, $newDB, $logger): void {
            $oldDBFile = $dbPath . DIRECTORY_SEPARATOR . 'watchstate.db';

            if (true !== file_exists($oldDBFile)) {
                return;
            }

            $logger?->notice('Migrating pre release db to v0');

            $oldDB = new PDO(dsn: sprintf('sqlite:%s', $oldDBFile), options: [
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_STRINGIFY_FETCHES => false,
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY,
            ]);

            $sql = "INSERT INTO state (id, type, updated, watched, via, title, year, season, episode, parent, guids, extra)
                    VALUES(:id, :type, :updated, :watched, :via, :title, :year, :season, :episode, :parent, :guids, :extra)
            ";

            if (!$newDB->inTransaction()) {
                $newDB->beginTransaction();
            }

            $insert = $newDB->prepare($sql);

            $stmt = $oldDB->query("SELECT * FROM state");

            foreach ($stmt as $row) {
                $row['meta'] = json_decode(
                    json:        $row['meta'] ?? '{}',
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
                            flags: JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
                        ),
                        'guids' => json_encode(
                            value: array_intersect_key($row, Guid::SUPPORTED),
                            flags: JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
                        ),
                        'extra' => json_encode(
                            value: $extra,
                            flags: JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
                        ),
                    ]
                );
            }

            if ($newDB->inTransaction()) {
                $newDB->commit();
            }

            $stmt = null;
            $oldDB = null;

            rename($oldDBFile, $dbPath . DIRECTORY_SEPARATOR . 'watchstate.migrated.db');

            $logger?->notice('Migration is successful. Renamed old db to watchstate.migrated.db');
        })();
    }
};
