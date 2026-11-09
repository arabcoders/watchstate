<?php

declare(strict_types=1);

namespace App\Libs;

use App\Libs\Entity\StateEntity;
use App\Libs\Entity\StateInterface as iState;
use RuntimeException;

final class UserStatisticsGenerator
{
    public const int VERSION = 1;
    public const string CACHE_KEY = 'user_statistics_v1';

    /**
     * Return the last successful snapshot for this user.
     *
     * @return array<string,mixed>|null
     */
    public function latest(UserContext $context): ?array
    {
        $snapshot = $context->cache->get(self::CACHE_KEY);

        return $this->validSnapshot($snapshot) ? $snapshot['report'] : null;
    }

    /**
     * Compare local record counts and update timestamps without loading metadata.
     */
    public function isStale(UserContext $context): bool
    {
        $snapshot = $context->cache->get(self::CACHE_KEY);
        if (false === $this->validSnapshot($snapshot)) {
            return true;
        }

        return $snapshot['fingerprint'] !== $this->fingerprint($context) || (time() - (int) $snapshot['report']['generated_at']) >= 86_400;
    }

    /**
     * Generate local statistics and replace the snapshot only after completion.
     *
     * @return array<string,mixed>
     */
    public function generate(UserContext $context): array
    {
        $generatedAt = time();
        $fingerprint = $this->fingerprint($context);
        $summary = [
            'movies' => ['total' => 0, 'watched' => 0, 'in_progress' => 0],
            'episodes' => ['total' => 0, 'watched' => 0, 'in_progress' => 0],
            'shows' => ['total' => 0, 'started' => 0, 'in_progress' => 0, 'fully_watched' => 0],
        ];
        $parents = [];
        $episodes = [];
        $genres = [];
        $recent = [];
        $inProgressItems = ['movies' => [], 'episodes' => []];

        $sql = "SELECT
                    id, type, watched, title, year, season, episode, parent, metadata, updated, extra
                FROM
                    state
                WHERE
                    type IN ('movie', 'episode')
                ORDER BY
                    id
        ";

        foreach ($context->db->getDBLayer()->query($sql) as $row) {
            $entity = StateEntity::fromArray($row);
            $watched = $entity->isWatched();
            $inProgress = false === $watched && $entity->hasPlayProgress();
            $type = $entity->isMovie() ? 'movies' : 'episodes';
            $summary[$type]['total']++;
            $summary[$type]['watched'] += (int) $watched;
            $summary[$type]['in_progress'] += (int) $inProgress;
            $itemGenres = $this->genres($entity);
            $playedAt = $this->playedAt($entity);

            if (true === $inProgress) {
                $inProgressItems[$type][] = [
                    'title' => $entity->title,
                    'type' => $entity->type,
                    'year' => $entity->year,
                    'season' => $entity->isEpisode() ? $entity->season : null,
                    'episode' => $entity->isEpisode() ? $entity->episode : null,
                    'progress' => $entity->getPlayProgress(),
                    'history_id' => (int) $entity->id,
                ];
            }

            if (true === $watched && null !== $playedAt) {
                $recent[] = [
                    'title' => $entity->title,
                    'type' => $entity->type,
                    'year' => $entity->year,
                    'season' => $entity->isEpisode() ? $entity->season : null,
                    'episode' => $entity->isEpisode() ? $entity->episode : null,
                    'played_at' => $playedAt,
                    'history_id' => (int) $entity->id,
                ];
                usort(
                    $recent,
                    static fn(array $a, array $b): int => [$b['played_at'], $b['history_id']] <=> [$a['played_at'], $a['history_id']],
                );
                $recent = array_slice($recent, 0, 20);
            }

            if ($entity->isMovie()) {
                if (true === $watched) {
                    $this->addGenres($genres, $itemGenres, 'movies');
                }
                continue;
            }

            $tokens = $this->showTokens($entity);
            if ([] === $tokens) {
                continue;
            }

            $first = $tokens[0];
            $parents[$first] ??= $first;
            foreach ($tokens as $token) {
                $parents[$token] ??= $token;
                $parents[$this->root($parents, $token)] = $this->root($parents, $first);
            }

            $episodes[] = [
                'token' => $first,
                'title' => $entity->title,
                'watched' => (int) $watched,
                'in_progress' => (int) $inProgress,
                'played_at' => true === $watched ? $playedAt : null,
                'history_id' => (int) $entity->id,
                'genres' => $itemGenres,
            ];
        }

        $shows = [];
        foreach ($episodes as $episode) {
            $key = $this->root($parents, $episode['token']);
            $shows[$key] ??= [
                'title' => $episode['title'],
                'year' => null,
                'total' => 0,
                'watched' => 0,
                'in_progress' => 0,
                'status' => 'not_started',
                'last_played_at' => null,
                'history_id' => $episode['history_id'],
                'genres' => [],
            ];
            $show = &$shows[$key];
            $show['total']++;
            $show['watched'] += $episode['watched'];
            $show['in_progress'] += $episode['in_progress'];
            $show['genres'] += $episode['genres'];
            if (null !== $episode['played_at'] && (null === $show['last_played_at'] || $episode['played_at'] > $show['last_played_at'])) {
                $show['last_played_at'] = $episode['played_at'];
                $show['history_id'] = $episode['history_id'];
            }
            unset($show);
        }

        foreach ($shows as &$show) {
            $summary['shows']['total']++;
            if (0 < $show['watched'] || 0 < $show['in_progress']) {
                $summary['shows']['started']++;
                $show['status'] = $show['total'] === $show['watched'] ? 'fully_watched' : 'in_progress';
                $summary['shows'][$show['status']]++;
            }
            if (0 < $show['watched']) {
                $this->addGenres($genres, $show['genres'], 'shows');
            }
            unset($show['genres']);
        }
        unset($show);

        $shows = array_values($shows);
        usort(
            $shows,
            static function (array $a, array $b): int {
                $title = strnatcasecmp($a['title'], $b['title']);

                return 0 !== $title ? $title : $a['history_id'] <=> $b['history_id'];
            },
        );
        $genres = array_values($genres);
        usort($genres, static fn(array $a, array $b): int => $b['total'] !== $a['total']
            ? $b['total'] <=> $a['total']
            : strnatcasecmp($a['name'], $b['name']));
        foreach ($inProgressItems as &$items) {
            usort($items, static function (array $a, array $b): int {
                $title = strnatcasecmp($a['title'], $b['title']);

                return 0 !== $title
                    ? $title
                    : [$a['season'], $a['episode'], $a['history_id']] <=> [$b['season'], $b['episode'], $b['history_id']];
            });
        }
        unset($items);

        $report = [
            'version' => self::VERSION,
            'generated_at' => $generatedAt,
            'completed_at' => time(),
            'summary' => $summary,
            'genres' => $genres,
            'shows' => $shows,
            'recent' => $recent,
            'in_progress' => $inProgressItems,
        ];

        if (false === $context->cache->set(self::CACHE_KEY, ['fingerprint' => $fingerprint, 'report' => $report], null)) {
            throw new RuntimeException('Failed to persist user statistics snapshot.');
        }

        return $report;
    }

    /**
     * Remove internal history links from the public export projection.
     *
     * @param array<string,mixed> $report
     *
     * @return array<string,mixed>
     */
    public function export(array $report): array
    {
        $result = array_intersect_key($report, array_flip([
            'version',
            'generated_at',
            'completed_at',
            'summary',
            'genres',
            'shows',
            'recent',
            'in_progress',
        ]));
        foreach (['shows', 'recent'] as $section) {
            $fields = 'shows' === $section
                ? ['title', 'year', 'total', 'watched', 'in_progress', 'status', 'last_played_at']
                : ['title', 'type', 'year', 'season', 'episode', 'played_at'];
            $result[$section] = array_map(static fn(array $item): array => array_intersect_key(
                $item,
                array_flip($fields),
            ), $report[$section]);
        }
        $result['in_progress'] = [];
        foreach (['movies', 'episodes'] as $type) {
            $result['in_progress'][$type] = array_map(static fn(array $item): array => array_intersect_key(
                $item,
                array_flip(['title', 'type', 'year', 'season', 'episode', 'progress']),
            ), $report['in_progress'][$type]);
        }

        return $result;
    }

    /**
     * Write a text-only Markdown export with escaped metadata.
     *
     * @param array<string,mixed> $report
     */
    public function markdown(array $report): string
    {
        $lines = [
            '# User Statistics',
            '',
            'Updated: ' . gmdate(DATE_ATOM, $report['generated_at']),
            '',
            '| Type | Total | Watched | In progress |',
            '| --- | ---: | ---: | ---: |',
        ];
        foreach (['movies', 'episodes'] as $type) {
            $counts = $report['summary'][$type];
            $lines[] = sprintf('| %s | %d | %d | %d |', ucfirst($type), $counts['total'], $counts['watched'], $counts['in_progress']);
        }
        $counts = $report['summary']['shows'];
        $lines = [
            ...$lines,
            '',
            sprintf(
                'Shows: %d total, %d started, %d in progress, %d fully watched.',
                $counts['total'],
                $counts['started'],
                $counts['in_progress'],
                $counts['fully_watched'],
            ),
        ];
        $lines = [
            ...$lines,
            '',
            '## Genres',
            '',
            '| Genre | Movies | Shows | Total |',
            '| --- | ---: | ---: | ---: |',
        ];
        foreach ($report['genres'] as $genre) {
            $lines[] = sprintf(
                '| %s | %d | %d | %d |',
                $this->escapeMarkdown($genre['name']),
                $genre['movies'],
                $genre['shows'],
                $genre['total'],
            );
        }
        $lines = [
            ...$lines,
            '',
            '## Shows',
            '',
            '| Title | Year | Episodes | Watched | In progress | Status | Last watched |',
            '| --- | ---: | ---: | ---: | ---: | --- | --- |',
        ];
        foreach ($report['shows'] as $show) {
            $lines[] = sprintf(
                '| %s | %s | %d | %d | %d | %s | %s |',
                $this->escapeMarkdown($show['title']),
                $show['year'] ?? '',
                $show['total'],
                $show['watched'],
                $show['in_progress'],
                match ($show['status']) {
                    'fully_watched' => 'Fully watched',
                    'in_progress' => 'In progress',
                    'not_started' => 'Not started',
                },
                null === $show['last_played_at'] ? '' : gmdate(DATE_ATOM, $show['last_played_at']),
            );
        }
        $lines = [
            ...$lines,
            '',
            '## In progress',
            '',
            '| Type | Title | Episode | Year | Playback position |',
            '| --- | --- | --- | ---: | --- |',
        ];
        foreach (['movies', 'episodes'] as $type) {
            foreach ($report['in_progress'][$type] as $item) {
                $lines[] = sprintf(
                    '| %s | %s | %s | %s | %s |',
                    'movie' === $item['type'] ? 'Movie' : 'Episode',
                    $this->escapeMarkdown($item['title']),
                    'episode' === $item['type'] && null !== $item['season'] && null !== $item['episode']
                        ? sprintf('%dx%02d', $item['season'], $item['episode'])
                        : '',
                    $item['year'] ?? '',
                    0 < $item['progress'] ? format_duration($item['progress']) : '',
                );
            }
        }
        $lines = [
            ...$lines,
            '',
            '## Recently watched',
            '',
            '| Title | Episode | Year | Last watched |',
            '| --- | --- | ---: | --- |',
        ];
        foreach ($report['recent'] as $item) {
            $lines[] = sprintf(
                '| %s | %s | %s | %s |',
                $this->escapeMarkdown($item['title']),
                'episode' === $item['type'] && null !== $item['season'] && null !== $item['episode']
                    ? sprintf('%dx%02d', $item['season'], $item['episode'])
                    : '',
                $item['year'] ?? '',
                gmdate(DATE_ATOM, $item['played_at']),
            );
        }

        return implode("\n", $lines) . "\n";
    }

    private function validSnapshot(mixed $snapshot): bool
    {
        if (false === is_array($snapshot) || self::VERSION !== ag($snapshot, 'report.version')) {
            return false;
        }

        foreach (['movies', 'episodes'] as $type) {
            $items = ag($snapshot, 'report.in_progress.' . $type);
            if (
                false === is_array($items)
                || false === array_all(
                    $items,
                    static fn(mixed $item): bool => is_array($item) && is_int($item['progress'] ?? null),
                )
            ) {
                return false;
            }
        }

        return true;
    }

    private function fingerprint(UserContext $context): string
    {
        $row = $context
            ->db
            ->getDBLayer()
            ->query(
                "SELECT COUNT(*) AS records, COALESCE(MAX(id), 0) AS last_id, COALESCE(MAX(updated_at), 0) AS modified, COALESCE(MAX(updated), 0) AS played FROM state WHERE type IN ('movie', 'episode')",
            )
            ->fetch();

        return json_encode($row, JSON_THROW_ON_ERROR);
    }

    /** @return array<string,string> */
    private function genres(StateEntity $entity): array
    {
        $genres = [];
        foreach ($entity->getMetadata() as $metadata) {
            if (false === is_array($metadata)) {
                continue;
            }
            foreach ((array) ag($metadata, 'extra.genres', []) as $genre) {
                if (false === is_string($genre)) {
                    continue;
                }
                $genre = trim($genre);
                if ('' !== $genre && false === in_array(mb_strtolower($genre), ['??', 'unknown'], true)) {
                    $genres[mb_strtolower($genre)] = $genre;
                }
            }
        }

        return $genres;
    }

    private function playedAt(StateEntity $entity): ?int
    {
        $playedAt = 0;
        foreach ($entity->getMetadata() as $metadata) {
            if (false === is_array($metadata)) {
                continue;
            }
            $value = ag($metadata, iState::COLUMN_META_DATA_PLAYED_AT);
            if (is_numeric($value) && 0 < (int) $value) {
                $playedAt = max($playedAt, (int) $value);
            }
        }

        return 0 < $playedAt ? $playedAt : null;
    }

    /** @return array<string> */
    private function showTokens(StateEntity $entity): array
    {
        $tokens = [];
        $parentSets = [$entity->getParentGuids()];
        foreach ($entity->getMetadata() as $backend => $metadata) {
            if (false === is_array($metadata)) {
                continue;
            }
            $parentSets[] = (array) ag($metadata, iState::COLUMN_PARENT, []);
            $id = trim((string) ag($metadata, iState::COLUMN_META_SHOW, ''));
            if ('' !== $id && false === in_array(mb_strtolower($id), ['??', '0', '-1', 'unknown'], true)) {
                $tokens[] = json_encode(['backend', (string) $backend, $id], JSON_THROW_ON_ERROR);
            }
        }
        foreach ($parentSets as $parents) {
            foreach (array_intersect_key($parents, Guid::getSupported()) as $source => $id) {
                if (false === is_scalar($id)) {
                    continue;
                }
                $id = trim((string) $id);
                if ('' !== $id && false === in_array(mb_strtolower($id), ['??', '0', '-1', 'unknown'], true)) {
                    $tokens[] = json_encode(['guid', $source, $id], JSON_THROW_ON_ERROR);
                }
            }
        }

        return array_values(array_unique($tokens));
    }

    /** @param array<string,string> $parents */
    private function root(array &$parents, string $alias): string
    {
        if ($parents[$alias] !== $alias) {
            $parents[$alias] = $this->root($parents, $parents[$alias]);
        }

        return $parents[$alias];
    }

    /**
     * @param array<string,array{name:string,movies:int,shows:int,total:int}> $genres
     * @param array<string,string> $names
     */
    private function addGenres(array &$genres, array $names, string $type): void
    {
        foreach ($names as $key => $name) {
            $genres[$key] ??= ['name' => $name, 'movies' => 0, 'shows' => 0, 'total' => 0];
            $genres[$key][$type]++;
            $genres[$key]['total']++;
        }
    }

    private function escapeMarkdown(string $value): string
    {
        $value = htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $value = preg_replace('/[\x00-\x20]+/u', ' ', $value) ?? '';

        return preg_replace('/([\\\\`*_{}\[\]()#+.!|>~\-])/u', '\\\\$1', $value) ?? '';
    }
}
