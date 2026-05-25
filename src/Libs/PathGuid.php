<?php

declare(strict_types=1);

namespace App\Libs;

use App\Libs\Entity\StateInterface as iState;

final class PathGuid
{
    private const string SCOPE_MOVIE = 'movie';
    private const string SCOPE_EPISODE = 'episode';
    private const string SCOPE_SHOW = 'show';

    /**
     * Generate path-derived GUID fields for a state entity.
     *
     * @param string $type Entity type.
     * @param string $path Raw backend media path.
     * @param int|null $season Episode season number.
     * @param int|null $episode Episode number.
     *
     * @return array<string,array<string,string>> Entity GUID fields keyed by `guids` and/or `parent`.
     */
    public static function get(string $type, string $path, ?int $season = null, ?int $episode = null): array
    {
        if (null === ($segments = self::segments($path))) {
            return [];
        }

        if (iState::TYPE_MOVIE === $type) {
            if (count($segments) < 2) {
                return [];
            }

            return [
                iState::COLUMN_GUIDS => [
                    Guid::GUID_PATH => self::hash(self::SCOPE_MOVIE, self::suffix($segments, 2)),
                ],
            ];
        }

        if (iState::TYPE_EPISODE !== $type || count($segments) < 3 || null === $season || null === $episode) {
            return [];
        }

        $episodeSuffix = self::suffix($segments, 3) . '/' . $season . '/' . $episode;
        $parentSuffix = '/' . implode('/', array_slice($segments, -3, 2));

        return [
            iState::COLUMN_GUIDS => [
                Guid::GUID_PATH => self::hash(self::SCOPE_EPISODE, $episodeSuffix),
            ],
            iState::COLUMN_PARENT => [
                Guid::GUID_PATH => self::hash(self::SCOPE_SHOW, $parentSuffix),
            ],
        ];
    }

    /**
     * Normalize a raw path into lowercase path segments.
     *
     * @param string $path Raw path.
     *
     * @return array<string>|null Normalized path segments, or null when not a file path.
     */
    private static function segments(string $path): ?array
    {
        if ('' === ($path = trim(str_replace('\\', '/', $path)))) {
            return null;
        }

        if (null === ($normalized = preg_replace('#/+#', '/', $path))) {
            return null;
        }

        if ('' === ($normalized = rtrim($normalized, '/'))) {
            return null;
        }

        $segments = array_values(array_filter(explode('/', $normalized), static fn(string $segment): bool => '' !== $segment));
        if (count($segments) < 1) {
            return null;
        }

        $file = end($segments);
        if (false === is_string($file) || '' === pathinfo($file, PATHINFO_EXTENSION)) {
            return null;
        }

        return array_map(static fn(string $segment): string => mb_strtolower($segment), $segments);
    }

    /**
     * Build a normalized path suffix from the final path segments.
     *
     * @param array<string> $segments Normalized path segments.
     * @param int $length Number of final segments to include.
     */
    private static function suffix(array $segments, int $length): string
    {
        return '/' . implode('/', array_slice($segments, -$length));
    }

    /**
     * Hash a scoped path suffix.
     *
     * @param string $scope Hash scope.
     * @param string $suffix Normalized path suffix.
     */
    private static function hash(string $scope, string $suffix): string
    {
        return hash('md5', $scope . ':' . $suffix);
    }
}
