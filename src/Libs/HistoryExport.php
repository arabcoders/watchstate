<?php

declare(strict_types=1);

namespace App\Libs;

use App\Libs\Entity\StateEntity;
use Psr\Http\Message\StreamInterface as iStream;

final class HistoryExport
{
    /**
     * @param iterable<array<string,mixed>> $rows
     * @return iterable<array<string,mixed>>
     */
    public function items(iterable $rows, UserContext $context, string $grouping): iterable
    {
        if ('records' === $grouping) {
            foreach ($rows as $row) {
                yield $this->record(StateEntity::fromArray($row), $context);
            }

            return;
        }

        $items = [];
        $selected = [];
        foreach ($rows as $row) {
            $entity = StateEntity::fromArray($row);
            if ($entity->isMovie() || [] === ($tokens = ShowIdentity::tokens($entity))) {
                $items[] = $this->record($entity, $context);
            } else {
                $selected[$tokens[0]] = true;
            }
        }

        if ([] !== $selected) {
            $parents = [];
            $db = $context->db->getDBLayer();
            foreach ($db->query("SELECT parent, metadata FROM state WHERE type = 'episode' ORDER BY id") as $row) {
                $entity = StateEntity::fromArray($row);
                $tokens = ShowIdentity::tokens($entity);
                if ([] === $tokens) {
                    continue;
                }
                $first = $tokens[0];
                $parents[$first] ??= $first;
                foreach ($tokens as $token) {
                    $parents[$token] ??= $token;
                    $parents[ShowIdentity::root($parents, $token)] = ShowIdentity::root($parents, $first);
                }
            }

            $selectedShows = [];
            foreach ($selected as $token => $_) {
                $selectedShows[ShowIdentity::root($parents, $token)] = true;
            }
            unset($selected);

            $shows = [];
            $sql = "SELECT type, updated, watched, title, year, season, episode, parent, metadata, extra FROM state WHERE type = 'episode' ORDER BY id";
            foreach ($db->query($sql) as $row) {
                $entity = StateEntity::fromArray($row);
                $tokens = ShowIdentity::tokens($entity);
                if ([] === $tokens) {
                    continue;
                }
                $key = ShowIdentity::root($parents, $tokens[0]);
                if (false === isset($selectedShows[$key])) {
                    continue;
                }
                $record = $this->record($entity, $context);
                $shows[$key] ??= [
                    'title' => $record['title'],
                    'type' => 'show',
                    'year' => null,
                    'genres' => [],
                    'status' => 'not_started',
                    'last_watched_at' => null,
                    'user_rating' => null,
                    'episodes' => ['watched' => 0, 'available' => 0, 'in_progress' => 0],
                ];
                $show = &$shows[$key];
                $show['episodes']['available']++;
                $show['episodes']['watched'] += (int) ('watched' === $record['status']);
                $show['episodes']['in_progress'] += (int) ('in_progress' === $record['status']);
                $show['genres'] = array_values(array_unique([...$show['genres'], ...$record['genres']]));
                if (null !== $record['last_watched_at']) {
                    $show['last_watched_at'] = max($show['last_watched_at'] ?? 0, $record['last_watched_at']);
                }
                unset($show);
            }
            foreach ($shows as $show) {
                $counts = $show['episodes'];
                $show['status'] = match (true) {
                    $counts['available'] === $counts['watched'] => 'fully_watched',
                    0 < ($counts['watched'] + $counts['in_progress']) => 'in_progress',
                    default => 'not_started',
                };
                sort($show['genres'], SORT_NATURAL | SORT_FLAG_CASE);
                $items[] = $show;
            }
        }

        usort($items, static function (array $left, array $right): int {
            $title = strnatcasecmp($left['title'], $right['title']);

            return 0 !== $title
                ? $title
                : [$left['year'], $left['type'], $left['season'] ?? null, $left['episode'] ?? null] <=> [
                    $right['year'],
                    $right['type'],
                    $right['season'] ?? null,
                    $right['episode'] ?? null,
                ];
        });

        yield from $items;
    }

    /** @return array<string> */
    public static function genres(StateEntity $entity): array
    {
        $genres = [];
        foreach ($entity->getMetadata() as $metadata) {
            if (false === is_array($metadata)) {
                continue;
            }
            $names = ag($metadata, 'extra.genres', []);
            if (false === is_array($names) || false === array_is_list($names)) {
                continue;
            }
            foreach ($names as $genre) {
                if (false === is_string($genre)) {
                    continue;
                }
                $genre = mb_strtolower(trim($genre));
                if ('' !== $genre && false === in_array($genre, ['??', 'unknown'], true)) {
                    $genres[$genre] = $genre;
                }
            }
        }
        $genres = array_values($genres);
        sort($genres, SORT_NATURAL | SORT_FLAG_CASE);

        return $genres;
    }

    /**
     * Write the export one item at a time and return its item count.
     *
     * @param iterable<array<string,mixed>> $items
     */
    public function write(iStream $stream, iterable $items, string $grouping, string $format): int
    {
        $flags = JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE;
        $json = 'json' === $format;
        $stream->write(
            $json
                ? "{\n    \"version\": 1,\n    \"grouping\": " . json_encode($grouping, $flags) . ",\n    \"items\": ["
                : "# History\n\n| Title | Type | Year | Genres | Status | Episodes / position | Your rating |\n| --- | --- | ---: | --- | --- | --- | ---: |\n",
        );
        $count = 0;
        foreach ($items as $item) {
            if ($json) {
                $stream->write((0 === $count ? "\n" : ",\n") . '        ' . str_replace("\n", "\n        ", json_encode($item, $flags)));
                $count++;
                continue;
            }
            $position = '';
            if ('show' === $item['type']) {
                $position = sprintf(
                    '%d/%d watched, %d in progress',
                    $item['episodes']['watched'],
                    $item['episodes']['available'],
                    $item['episodes']['in_progress'],
                );
            } else {
                if ('episode' === $item['type'] && null !== $item['season'] && null !== $item['episode']) {
                    $position = sprintf('%dx%02d', $item['season'], $item['episode']);
                }
                if (null !== $item['progress'] && 0 < $item['progress']) {
                    $position .= ('' === $position ? '' : ', ') . format_duration($item['progress']);
                }
            }
            $stream->write(sprintf(
                "| %s | %s | %s | %s | %s | %s | %s |\n",
                $this->escapeMarkdown($item['title']),
                ucfirst($item['type']),
                $item['year'] ?? '',
                $this->escapeMarkdown(implode(', ', $item['genres'])),
                ucfirst(str_replace('_', ' ', $item['status'])),
                $position,
                $item['user_rating'] ?? '',
            ));
            $count++;
        }
        if ($json) {
            $stream->write((0 === $count ? '' : "\n    ") . "],\n    \"count\": " . $count . "\n}");
        }

        return $count;
    }

    /** @return array<string,mixed> */
    private function record(StateEntity $entity, UserContext $context): array
    {
        $playedAt = null;
        $rating = null;
        foreach ($entity->getMetadata() as $backend => $metadata) {
            if (false === is_array($metadata)) {
                continue;
            }
            $value = $metadata['played_at'] ?? null;
            if (is_numeric($value) && 0 < (int) $value) {
                $playedAt = max($playedAt ?? 0, (int) $value);
            }
            $value = $metadata['rating'] ?? null;
            if (null === $rating && 'plex' === $context->config->get($backend . '.type') && is_numeric($value) && 0 < (float) $value) {
                $rating = (float) $value;
            }
        }
        $hasProgress = $entity->hasPlayProgress();

        return [
            'title' => $entity->title,
            'type' => $entity->type,
            'year' => $entity->year,
            'genres' => self::genres($entity),
            'status' => match (true) {
                $entity->isWatched() => 'watched',
                $hasProgress => 'in_progress',
                default => 'not_started',
            },
            'season' => $entity->isEpisode() ? $entity->season : null,
            'episode' => $entity->isEpisode() ? $entity->episode : null,
            'progress' => $hasProgress ? $entity->getPlayProgress() : null,
            'last_watched_at' => $playedAt,
            'user_rating' => $rating,
        ];
    }

    private function escapeMarkdown(string $value): string
    {
        $value = htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $value = preg_replace('/[\x00-\x20]+/u', ' ', $value) ?? '';

        return preg_replace('/([\\\\`*_{}\[\]()#+.!|>~\-])/u', '\\\\$1', $value) ?? '';
    }
}
