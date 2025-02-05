<?php

declare(strict_types=1);

namespace App\API\Backend;

use App\API\Backend\Index as backendIndex;
use App\Backends\Common\ClientInterface as iClient;
use App\Libs\Attributes\Route\Get;
use App\Libs\DataUtil;
use App\Libs\Entity\StateInterface as iState;
use App\Libs\Enums\Http\Status;
use App\Libs\Exceptions\RuntimeException;
use App\Libs\Mappers\ExtendedImportInterface as iEImport;
use App\Libs\Options;
use App\Libs\Traits\APITraits;
use Psr\Http\Message\ResponseInterface as iResponse;
use Psr\Http\Message\ServerRequestInterface as iRequest;
use Psr\Log\LoggerInterface as iLogger;
use Throwable;

final class Mismatched
{
    use APITraits;

    public const array METHODS = [
        'similarity',
        'levenshtein',
    ];
    public const float DEFAULT_PERCENT = 50.0;
    public const array REMOVED_CHARS = [
        '?',
        ':',
        '(',
        '[',
        ']',
        ')',
        ',',
        '|',
        '%',
        '.',
        '–',
        '-',
        "'",
        '"',
        '+',
        '/',
        ';',
        '&',
        '_',
        '!',
        '*',
    ];

    public function __construct(private readonly iEImport $mapper, private readonly iLogger $logger)
    {
    }

    #[Get(backendIndex::URL . '/{name:backend}/mismatched[/[{id}[/]]]', name: 'backend.mismatched')]
    public function __invoke(iRequest $request, string $name, string|int|null $id = null): iResponse
    {
        try {
            $userContext = $this->getUserContext($request, $this->mapper, $this->logger);
        } catch (RuntimeException $e) {
            return api_error($e->getMessage(), Status::NOT_FOUND);
        }

        if (null === $this->getBackend(name: $name, userContext: $userContext)) {
            return api_error(r("Backend '{name}' not found.", ['name' => $name]), Status::NOT_FOUND);
        }

        $params = DataUtil::fromArray($request->getQueryParams());

        $backendOpts = $opts = $list = [];

        if ($params->get('timeout')) {
            $backendOpts = ag_set($backendOpts, 'client.timeout', (float)$params->get('timeout'));
        }

        $includeRaw = $params->get('raw') || $params->get(Options::RAW_RESPONSE);

        $opts[Options::RAW_RESPONSE] = true;

        $opts[Options::MISMATCH_DEEP_SCAN] = true;
        $opts[Options::NO_LOGGING] = true;

        $percentage = (float)$params->get('percentage', self::DEFAULT_PERCENT);
        $method = $params->get('method', self::METHODS[0]);

        if (false === in_array($method, self::METHODS, true)) {
            return api_error(r("Invalid comparison method '{method}'. Expecting '{methods}'", [
                'method' => $method,
                'methods' => implode(", ", self::METHODS),

            ]), Status::BAD_REQUEST);
        }

        try {
            $client = $this->getClient(name: $name, config: $backendOpts, userContext: $userContext);
        } catch (RuntimeException $e) {
            return api_error($e->getMessage(), Status::NOT_FOUND);
        }

        $ids = [];
        if (null !== $id) {
            $ids[] = $id;
        } else {
            foreach ($client->listLibraries() as $library) {
                if (false === (bool)ag($library, 'supported') || true === (bool)ag($library, 'ignored')) {
                    continue;
                }
                $ids[] = ag($library, 'id');
            }
        }

        foreach ($ids as $libraryId) {
            foreach ($client->getLibrary(id: $libraryId, opts: $opts) as $item) {
                $processed = $this->compare(client: $client, item: $item, method: $method);

                if (empty($processed) || $processed['percent'] >= $percentage) {
                    continue;
                }

                if (false === $includeRaw && ag_exists($processed, Options::RAW_RESPONSE)) {
                    unset($processed[Options::RAW_RESPONSE]);
                }

                $list[] = $processed;
            }
        }

        return api_response(Status::OK, $list);
    }

    protected function compare(iClient $client, array $item, string $method): array
    {
        if (empty($item)) {
            return [];
        }

        if (null === ($paths = ag($item, 'match.paths', [])) || empty($paths)) {
            return [];
        }

        if (null === ($titles = ag($item, 'match.titles', [])) || empty($titles)) {
            return [];
        }

        try {
            $builder = $client->toEntity($item[Options::RAW_RESPONSE])->getAll();
        } catch (Throwable) {
            // -- we likely encountered unexpected content type.
            return [];
        }

        if (count($item['guids']) < 1) {
            $item['guids'] = 'None';
        }

        $toLower = fn(string $text, bool $isASCII = false) => trim($isASCII ? strtolower($text) : mb_strtolower($text));

        $builder['percent'] = $percent = 0.0;
        $builder['matches'] = [];

        foreach ($paths as $path) {
            $pathFull = ag($path, 'full');
            $pathShort = ag($path, 'short');

            if (empty($pathFull) || empty($pathShort)) {
                continue;
            }

            foreach ($titles as $title) {
                $isASCII = mb_detect_encoding($pathShort, 'ASCII') && mb_detect_encoding($title, 'ASCII');

                $title = $toLower(self::formatName(name: $title), isASCII: $isASCII);
                $pathShort = $toLower(self::formatName(name: $pathShort), isASCII: $isASCII);

                if (1 === preg_match('/\((\d{4})\)/', basename($pathFull), $match)) {
                    $withYear = true;
                    if (ag($item, 'year') && false === str_contains($title, (string)ag($item, 'year'))) {
                        $title .= ' ' . ag($item, 'year');
                    }
                } else {
                    $withYear = false;
                }

                if (true === str_starts_with($pathShort, $title)) {
                    $percent = 100.0;
                }

                if (true === $isASCII) {
                    similar_text($pathShort, $title, $similarity);
                    $levenshtein = levenshtein($pathShort, $title);
                } else {
                    self::mb_similar_text($pathShort, $title, $similarity);
                    $levenshtein = self::mb_levenshtein($pathShort, $title);
                }

                $levenshtein = self::toPercentage($levenshtein, $pathShort, $title);

                switch ($method) {
                    default:
                    case 'similarity':
                        if ($similarity > $percent) {
                            $percent = $similarity;
                        }
                        break;
                    case 'levenshtein':
                        if ($similarity > $percent) {
                            $percent = $levenshtein;
                        }
                        break;
                }

                if (round($percent, 3) > $builder['percent']) {
                    $builder['percent'] = round($percent, 3);
                }

                $builder['matches'][] = [
                    'path' => $pathShort,
                    'title' => $title,
                    'type' => $isASCII ? 'ascii' : 'unicode',
                    'methods' => [
                        'similarity' => round($similarity, 3),
                        'levenshtein' => round($levenshtein, 3),
                        'startWith' => str_starts_with($pathShort, $title),
                    ],
                    'year' => [
                        'inPath' => $withYear,
                        'parsed' => isset($match[1]) ? (int)$match[1] : 'No',
                        'source' => ag($item, 'year', 'No'),
                    ],
                ];
            }
        }

        if (count($paths) <= 2 && null !== ($paths[0]['full'] ?? null)) {
            $builder['path'] = $paths[0]['full'];
        }

        if (null !== ($url = ag($item, 'url'))) {
            $builder['url'] = $url;
        }

        if (null !== ($webUrl = ag($item, 'webUrl'))) {
            $builder['webUrl'] = $webUrl;
        }

        return ag_set($builder, iState::COLUMN_META_LIBRARY, ag($item, iState::COLUMN_META_LIBRARY));
    }

    /**
     * Formats the given name.
     *
     * @param string $name The name to format.
     *
     * @return string The formatted name.
     */
    public static function formatName(string $name): string
    {
        $name = preg_replace('#[\[{].+?[]}]#', '', $name);
        return trim(preg_replace('/\s+/', ' ', str_replace(self::REMOVED_CHARS, ' ', $name)));
    }

    /**
     * Implementation of `mb_similar_text()`.
     *
     * (c) Antal Áron <antalaron@antalaron.hu>
     *
     * @see http://php.net/manual/en/function.similar-text.php
     * @see http://locutus.io/php/strings/similar_text/
     *
     * @param string $str1
     * @param string $str2
     * @param float|null $percent
     *
     * @return int
     */
    private function mb_similar_text(string $str1, string $str2, float|null &$percent = null): int
    {
        if (0 === mb_strlen($str1) + mb_strlen($str2)) {
            $percent = 0.0;

            return 0;
        }

        $pos1 = $pos2 = $max = 0;
        $l1 = mb_strlen($str1);
        $l2 = mb_strlen($str2);

        for ($p = 0; $p < $l1; ++$p) {
            for ($q = 0; $q < $l2; ++$q) {
                /** @noinspection LoopWhichDoesNotLoopInspection */
                /** @noinspection MissingOrEmptyGroupStatementInspection */
                for (
                    $l = 0; ($p + $l < $l1) && ($q + $l < $l2) && mb_substr($str1, $p + $l, 1) === mb_substr(
                    $str2,
                    $q + $l,
                    1
                ); ++$l
                ) {
                    // nothing to do
                }
                if ($l > $max) {
                    $max = $l;
                    $pos1 = $p;
                    $pos2 = $q;
                }
            }
        }

        $similarity = $max;
        if ($similarity) {
            if ($pos1 && $pos2) {
                $similarity += self::mb_similar_text(mb_substr($str1, 0, $pos1), mb_substr($str2, 0, $pos2));
            }
            if (($pos1 + $max < $l1) && ($pos2 + $max < $l2)) {
                $similarity += self::mb_similar_text(
                    mb_substr($str1, $pos1 + $max, $l1 - $pos1 - $max),
                    mb_substr($str2, $pos2 + $max, $l2 - $pos2 - $max)
                );
            }
        }

        $percent = ($similarity * 200.0) / ($l1 + $l2);

        return $similarity;
    }

    /**
     * Implementation levenshtein distance algorithm.
     *
     * @param string $str1 The first string.
     * @param string $str2 The second string.
     *
     * @return int The Levenshtein distance between the two strings.
     */
    private function mb_levenshtein(string $str1, string $str2): int
    {
        $length1 = mb_strlen($str1, 'UTF-8');
        $length2 = mb_strlen($str2, 'UTF-8');

        if ($length1 < $length2) {
            return self::mb_levenshtein($str2, $str1);
        }

        if (0 === $length1) {
            return $length2;
        }

        if ($str1 === $str2) {
            return 0;
        }

        $prevRow = range(0, $length2);

        for ($i = 0; $i < $length1; $i++) {
            $currentRow = [];
            $currentRow[0] = $i + 1;
            $c1 = mb_substr($str1, $i, 1, 'UTF-8');

            for ($j = 0; $j < $length2; $j++) {
                $c2 = mb_substr($str2, $j, 1, 'UTF-8');
                $insertions = $prevRow[$j + 1] + 1;
                $deletions = $currentRow[$j] + 1;
                $substitutions = $prevRow[$j] + (($c1 !== $c2) ? 1 : 0);
                $currentRow[] = min($insertions, $deletions, $substitutions);
            }

            $prevRow = $currentRow;
        }
        return $prevRow[$length2];
    }

    /**
     * How much percentage is the base value of the lengths of the strings.
     *
     * @param int $base The base value.
     * @param string $str1 The first string.
     * @param string $str2 The second string.
     * @param bool $isASCII Whether to consider ASCII characters only. Default is false.
     *
     * @return float The percentage value calculated based on the base value and the lengths of the strings.
     */
    private function toPercentage(int $base, string $str1, string $str2, bool $isASCII = false): float
    {
        $length = fn(string $text) => $isASCII ? mb_strlen($text, 'UTF-8') : strlen($text);

        return (1 - $base / max($length($str1), $length($str2))) * 100;
    }
}
