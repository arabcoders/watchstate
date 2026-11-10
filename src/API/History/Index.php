<?php

declare(strict_types=1);

namespace App\API\History;

use App\API\Logs\Index as LogsIndex;
use App\API\Player\Subtitle;
use App\Libs\APIResponse;
use App\Libs\Attributes\DI\Inject;
use App\Libs\Attributes\Route\Delete;
use App\Libs\Attributes\Route\Get;
use App\Libs\Attributes\Route\Post;
use App\Libs\Attributes\Route\Route;
use App\Libs\Config;
use App\Libs\Container;
use App\Libs\DataUtil;
use App\Libs\Entity\StateEntity;
use App\Libs\Entity\StateInterface as iState;
use App\Libs\Enums\Http\Method;
use App\Libs\Enums\Http\Status;
use App\Libs\Exceptions\RuntimeException;
use App\Libs\Guid;
use App\Libs\HistoryExport;
use App\Libs\Mappers\Import\DirectMapper;
use App\Libs\Mappers\ImportInterface as iImport;
use App\Libs\Options;
use App\Libs\Stream;
use App\Libs\Traits\APITraits;
use App\Libs\UserContext;
use App\Model\Events\EventsRepository;
use DateInterval;
use JsonException;
use PDO;
use Psr\Http\Message\ResponseInterface as iResponse;
use Psr\Http\Message\ServerRequestInterface as iRequest;
use Psr\Log\LoggerInterface as iLogger;
use Psr\SimpleCache\CacheInterface as iCache;
use SplFileInfo;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Throwable;

final class Index
{
    private const float IMAGE_TIMEOUT_SECONDS = 10.0;

    // Keep combined genre/library predicates below SQLite's expression-depth limit.
    private const int MAX_FILTER_CHOICES = 100;

    use APITraits;

    /**
     * @var array The array containing the names of the columns that the list can be sorted by.
     */
    public const array COLUMNS_SORTABLE = [
        iState::COLUMN_ID,
        iState::COLUMN_TYPE,
        iState::COLUMN_UPDATED,
        iState::COLUMN_WATCHED,
        iState::COLUMN_VIA,
        iState::COLUMN_TITLE,
        iState::COLUMN_YEAR,
        iState::COLUMN_SEASON,
        iState::COLUMN_EPISODE,
        iState::COLUMN_CREATED_AT,
        iState::COLUMN_UPDATED_AT,
    ];

    public const string URL = '%{api.prefix}/history';

    public function __construct(
        #[Inject(DirectMapper::class)]
        private iImport $mapper,
        private iLogger $logger,
        private EventsRepository $events,
    ) {}

    /**
     * @param array<int>|null $ids
     * @return array{sql:string,params:array<string,mixed>,filters:array<string,mixed>,data:DataUtil}|iResponse
     */
    private function buildQuery(DataUtil $data, UserContext $userContext, ?array $ids = null): array|iResponse
    {
        foreach ($data->getAll() as $key => $value) {
            if (in_array($key, ['genres', 'library', 'sort'], true)) {
                if (false === is_string($value) && false === is_array($value) && null !== $value) {
                    return api_error('Invalid history filter.', Status::BAD_REQUEST);
                }
                if (is_array($value)) {
                    if (false === array_is_list($value)) {
                        return api_error('History filter choices must be lists.', Status::BAD_REQUEST);
                    }
                    if (in_array($key, ['genres', 'library'], true) && self::MAX_FILTER_CHOICES < count($value)) {
                        return api_error(r("History filter '{filter}' accepts at most {limit} choices.", [
                            'filter' => $key,
                            'limit' => self::MAX_FILTER_CHOICES,
                        ]), Status::BAD_REQUEST);
                    }
                    if (false === array_all($value, static fn(mixed $choice): bool => is_string($choice))) {
                        return api_error('Invalid history filter choice.', Status::BAD_REQUEST);
                    }
                }
            } elseif (in_array($key, ['rguid', 'parent', 'guids', 'key'], true)) {
                if (null !== $value && false === is_string($value)) {
                    return api_error(r("History filter '{filter}' must be a string.", [
                        'filter' => $key,
                    ]), Status::BAD_REQUEST);
                }
            } elseif (false === is_scalar($value) && null !== $value) {
                return api_error('Invalid history filter.', Status::BAD_REQUEST);
            }
        }

        $db = $userContext->db->getDBLayer();
        $es = static fn(string $val) => $db->escapeIdentifier($val, true);
        $filters = [];
        $params = [];

        $sql = $where = [];

        $sql[] = sprintf('FROM %s', $es('state'));

        if (null !== $ids) {
            $where[] = 'id IN (SELECT value FROM json_each(:selection_ids))';
            $params['selection_ids'] = json_encode($ids, JSON_THROW_ON_ERROR);
        }

        // -- search relative guids.
        if ($data->get('rguid')) {
            $regex = '/(?<guid>\w+)\:\/\/(?<parentID>.+?)\/(?<seasonNumber>\w+)(\/(?<episodeNumber>.+))?/is';
            if (1 !== preg_match($regex, $data->get('rguid'), $matches)) {
                return api_error(
                    'Invalid value for rguid query string expected value format is guid://parentID/seasonNumber[/episodeNumber].',
                    Status::BAD_REQUEST,
                );
            }

            $data = $data->with(iState::COLUMN_PARENT, r('{guid}://{parent}', [
                'guid' => ag($matches, 'guid'),
                'parent' => ag($matches, 'parentID'),
            ]));

            $data = $data->with(iState::COLUMN_SEASON, ag($matches, 'seasonNumber'));

            if (null !== ($episodeNumber = ag($matches, 'episodeNumber'))) {
                $data = $data->with(iState::COLUMN_EPISODE, $episodeNumber);
            }

            $filters['rguid'] = $data->get('rguid');
        }

        if ($data->has(iState::COLUMN_WATCHED)) {
            $where[] = $es(iState::COLUMN_WATCHED) . ' = :' . iState::COLUMN_WATCHED;
            $params[iState::COLUMN_WATCHED] = (int) $data->get(iState::COLUMN_WATCHED);
            $filters[iState::COLUMN_WATCHED] = $data->get(iState::COLUMN_WATCHED);
        }

        $status = $data->get('status');
        if (null !== $status && '' !== $status && 'all' !== $status) {
            if (false === in_array($status, ['watched', 'in_progress', 'not_started'], true)) {
                return api_error('Invalid history status.', Status::BAD_REQUEST);
            }
            $where[] = 'watched = ' . ('watched' === $status ? '1' : '0');
            $filters['status'] = $status;
        }

        if ($data->get('in_progress') || in_array($status, ['in_progress', 'not_started'], true)) {
            $where[] = $es(iState::COLUMN_WATCHED) . ' = 0';
            $progressSql = <<<'SQL'
                EXISTS (
                    SELECT 1 FROM json_each(metadata) AS progress_metadata
                    WHERE CASE WHEN progress_metadata.type IN ('object', 'array') THEN
                        CAST(COALESCE(json_extract(progress_metadata.value, '$.progress'), 0) AS INTEGER) > :progress_minimum
                        AND (
                            CAST(:progress_threshold AS INTEGER) >= 1
                            OR CAST(COALESCE(json_extract(progress_metadata.value, '$.watched'), 0) AS INTEGER) = 0
                        )
                    ELSE 0 END
                )
                SQL;
            $where[] = 'not_started' === $status ? 'NOT ' . $progressSql : $progressSql;
            if ($data->get('in_progress') && 'not_started' === $status) {
                $where[] = $progressSql;
            }
            $params['progress_minimum'] = (int) Config::get('progress.minimum', 60_000);
            $params['progress_threshold'] = (int) Config::get('progress.threshold', 0);
            if ($data->get('in_progress')) {
                $filters['in_progress'] = $data->get('in_progress');
            }
        }

        foreach (['year_min' => '>=', 'year_max' => '<='] as $key => $operator) {
            if (null === $data->get($key) || '' === $data->get($key)) {
                continue;
            }
            $year = filter_var($data->get($key), FILTER_VALIDATE_INT);
            if (false === $year || 1 > $year || 9999 < $year) {
                return api_error('Invalid year range.', Status::BAD_REQUEST);
            }
            $where[] = 'year ' . $operator . ' :' . $key;
            $params[$key] = $year;
            $filters[$key] = $year;
        }
        if (isset($params['year_min'], $params['year_max']) && $params['year_min'] > $params['year_max']) {
            return api_error('Invalid year range.', Status::BAD_REQUEST);
        }

        if (null !== ($libraries = $data->get('library'))) {
            $libraries = is_array($libraries) ? $libraries : [$libraries];
            $choices = [];
            foreach (array_values($libraries) as $index => $library) {
                if (false === is_string($library) || 1 !== preg_match('~^(.+?)://(.+)$~s', $library, $parts)) {
                    return api_error('Invalid library source.', Status::BAD_REQUEST);
                }
                $choices[] =
                    '(library_metadata.key = :library_backend_'
                    . $index
                    . ' AND CAST(json_extract(library_metadata.value, \'$.library\') AS TEXT) = :library_id_'
                    . $index
                    . ')';
                $params['library_backend_' . $index] = $parts[1];
                $params['library_id_' . $index] = $parts[2];
            }
            if ([] !== $choices) {
                $where[] =
                    'EXISTS (SELECT 1 FROM json_each(metadata) AS library_metadata WHERE CASE '
                    . "WHEN library_metadata.type IN ('object', 'array') THEN ("
                    . implode(' OR ', $choices)
                    . ') ELSE 0 END)';
                $filters['library'] = $libraries;
            }
        }

        if ($data->get(iState::COLUMN_ID)) {
            $where[] = $es(iState::COLUMN_ID) . ' = :' . iState::COLUMN_ID;
            $params[iState::COLUMN_ID] = $data->get(iState::COLUMN_ID);
            $filters[iState::COLUMN_ID] = $data->get(iState::COLUMN_ID);
        }

        if ($data->get(iState::COLUMN_VIA)) {
            $where[] = $es(iState::COLUMN_VIA) . ' = :' . iState::COLUMN_VIA;
            $params[iState::COLUMN_VIA] = $data->get('via');
            $filters[iState::COLUMN_VIA] = $data->get('via');
        }

        if ($data->get(iState::COLUMN_YEAR)) {
            $where[] = $es(iState::COLUMN_YEAR) . ' = :' . iState::COLUMN_YEAR;
            $params[iState::COLUMN_YEAR] = $data->get(iState::COLUMN_YEAR);
            $filters[iState::COLUMN_YEAR] = $data->get(iState::COLUMN_YEAR);
        }

        if ($data->get(iState::COLUMN_TYPE)) {
            $where[] = $es(iState::COLUMN_TYPE) . ' = :' . iState::COLUMN_TYPE;
            $params[iState::COLUMN_TYPE] = match ($data->get(iState::COLUMN_TYPE)) {
                iState::TYPE_MOVIE => iState::TYPE_MOVIE,
                default => iState::TYPE_EPISODE,
            };
            $filters[iState::COLUMN_TYPE] = $data->get(iState::COLUMN_TYPE);
        }

        if ($data->get(iState::COLUMN_TITLE)) {
            $where[] = $es(iState::COLUMN_TITLE) . ' LIKE "%" || :' . iState::COLUMN_TITLE . ' || "%"';
            $params[iState::COLUMN_TITLE] = $data->get(iState::COLUMN_TITLE);
            $filters[iState::COLUMN_TITLE] = $data->get(iState::COLUMN_TITLE);
        }

        if (null !== $data->get(iState::COLUMN_SEASON)) {
            $where[] = $es(iState::COLUMN_SEASON) . ' = :' . iState::COLUMN_SEASON;
            $params[iState::COLUMN_SEASON] = $data->get(iState::COLUMN_SEASON);
            $filters[iState::COLUMN_SEASON] = $data->get(iState::COLUMN_SEASON);
        }

        if (null !== $data->get(iState::COLUMN_EPISODE)) {
            $where[] = $es(iState::COLUMN_EPISODE) . ' = :' . iState::COLUMN_EPISODE;
            $params[iState::COLUMN_EPISODE] = $data->get(iState::COLUMN_EPISODE);
            $filters[iState::COLUMN_EPISODE] = $data->get(iState::COLUMN_EPISODE);
        }

        if (null !== ($parent = $data->get(iState::COLUMN_PARENT))) {
            $parent = after($parent, 'guid_');
            $d = Guid::fromArray(['guid_' . before($parent, '://') => after($parent, '://')]);
            $parent = array_keys($d->getAll())[0] ?? null;

            if (null === $parent) {
                return api_error(
                    'Invalid value for parent query string expected value format is db://id.',
                    Status::BAD_REQUEST,
                );
            }

            $where[] = 'json_extract(' . iState::COLUMN_PARENT . ",'$.{$parent}') = :" . iState::COLUMN_PARENT;
            $params[iState::COLUMN_PARENT] = array_values($d->getAll())[0];
            $filters[iState::COLUMN_PARENT] = $data->get(iState::COLUMN_PARENT);
        }

        if (null !== ($guid = $data->get(iState::COLUMN_GUIDS))) {
            $guid = after($guid, 'guid_');
            $d = Guid::fromArray(['guid_' . before($guid, '://') => after($guid, '://')]);
            $guid = array_keys($d->getAll())[0] ?? null;

            if (null === $guid) {
                return api_error(
                    'Invalid value for guid query string expected value format is db://id.',
                    Status::BAD_REQUEST,
                );
            }

            $where[] = 'json_extract(' . iState::COLUMN_GUIDS . ",'$.{$guid}') = :" . iState::COLUMN_GUIDS;
            $params[iState::COLUMN_GUIDS] = array_values($d->getAll())[0];
            $filters[iState::COLUMN_GUIDS] = $data->get(iState::COLUMN_GUIDS);
        }

        if ($data->get(iState::COLUMN_META_DATA)) {
            $sField = $data->get('key');
            $sValue = $data->get('value');
            if (null === $sField || null === $sValue) {
                return api_error(
                    'When searching using JSON fields the query string \'key\' and \'value\' must be set.',
                    Status::BAD_REQUEST,
                );
            }

            if (preg_match('/[^a-zA-Z0-9_.]/', $sField)) {
                return api_error(
                    'Invalid value for key query string expected value format is [a-zA-Z0-9_].',
                    Status::BAD_REQUEST,
                );
            }

            if ($data->get('exact')) {
                $where[] = 'json_extract(' . iState::COLUMN_META_DATA . ",'$.{$sField}') = :jf_metadata_value";
            } else {
                $where[] = 'json_extract(' . iState::COLUMN_META_DATA . ",'$.{$sField}') LIKE \"%\" || :jf_metadata_value || \"%\"";
            }

            $params['jf_metadata_value'] = $sValue;
            $filters[iState::COLUMN_META_DATA] = [
                'key' => $sField,
                'value' => $sValue,
                'exact' => $data->get('exact'),
            ];
        }

        foreach (['path' => '$.path', 'subtitle' => '$.extra.title'] as $field => $jsonPath) {
            if (false === (bool) $data->get($field)) {
                continue;
            }
            $backendKeys = [];
            foreach (array_keys($userContext->config->getAll()) as $index => $backend) {
                $key = $field . '_backend_' . $index;
                $backendKeys[] = ':' . $key;
                $params[$key] = $backend;
            }
            if ([] === $backendKeys) {
                $where[] = '0 = 1';
            } else {
                $comparison = $data->get('exact') ? '= :' . $field . '_value' : "LIKE '%' || :" . $field . "_value || '%'";
                $where[] =
                    'EXISTS (SELECT 1 FROM json_each(metadata) AS search_metadata WHERE search_metadata.key IN ('
                    . implode(',', $backendKeys)
                    . ") AND CASE WHEN search_metadata.type IN ('object', 'array') THEN "
                    . "json_extract(search_metadata.value, '"
                    . $jsonPath
                    . "') "
                    . $comparison
                    . ' ELSE 0 END)';
                $params[$field . '_value'] = $data->get($field);
            }
            $filters[$field] = $data->get($field);
        }

        if ($data->get(iState::COLUMN_EXTRA)) {
            $sField = $data->get('key');
            $sValue = $data->get('value');
            if (null === $sField || null === $sValue) {
                return api_error(
                    'When searching using JSON fields the query string \'key\' and \'value\' must be set.',
                    Status::BAD_REQUEST,
                );
            }

            if (preg_match('/[^a-zA-Z0-9_.]/', $sField)) {
                return api_error(
                    'Invalid value for key query string expected value format is [a-zA-Z0-9_].',
                    Status::BAD_REQUEST,
                );
            }

            if ($data->get('exact')) {
                $where[] = 'json_extract(' . iState::COLUMN_EXTRA . ",'$.{$sField}') = :jf_extra_value";
            } else {
                $where[] = 'json_extract(' . iState::COLUMN_EXTRA . ",'$.{$sField}') LIKE \"%\" || :jf_extra_value || \"%\"";
            }

            $params['jf_extra_value'] = $sValue;
            $filters['extra'] = [
                'key' => $sField,
                'value' => $sValue,
                'exact' => $data->get('exact'),
            ];
        }

        if (null !== ($genre = $data->get(iState::COLUMN_META_DATA_EXTRA_GENRES))) {
            $exact = is_array($genre) || $data->get('exact');
            $genres = is_array($genre) ? $genre : [$genre];
            $choices = [];
            foreach (array_values($genres) as $index => $name) {
                if (false === is_string($name)) {
                    return api_error('Invalid genre.', Status::BAD_REQUEST);
                }
                $name = mb_strtolower(trim($name));
                $genres[$index] = $name;
                $choices[] =
                    'LOWER(TRIM(genre_value.value)) ' . ($exact ? '= :genre_' . $index : "LIKE '%' || :genre_" . $index . " || '%'");
                $params['genre_' . $index] = $name;
            }
            if ([] !== $choices) {
                $where[] =
                    'EXISTS (SELECT 1 FROM json_each(metadata) AS genre_metadata, json_each(CASE '
                    . "WHEN genre_metadata.type IN ('object', 'array') THEN CASE WHEN json_type(genre_metadata.value, '$.extra.genres') = 'array' "
                    . "THEN json_extract(genre_metadata.value, '$.extra.genres') ELSE NULL END ELSE NULL END) AS genre_value "
                    . "WHERE genre_value.type = 'text' AND ("
                    . implode(' OR ', $choices)
                    . '))';
            }
            $filters[iState::COLUMN_META_DATA_EXTRA_GENRES] = [
                'key' => iState::COLUMN_META_DATA_EXTRA_GENRES,
                'value' => is_array($genre) ? $genres : $genres[0] ?? '',
                'exact' => is_array($genre) ? true : $data->get('exact'),
            ];
        }

        if (count($where) >= 1) {
            $sql[] = 'WHERE ' . implode(' AND ', $where);
        }

        $sorts = [];

        foreach ((array) $data->get('sort', []) as $sort) {
            if (1 !== preg_match('/(?P<field>\w+)(:(?P<dir>\w+))?/', $sort, $matches)) {
                continue;
            }

            if (
                null === ($matches['field'] ?? null)
                || false === in_array(
                    $matches['field'],
                    self::COLUMNS_SORTABLE,
                    true,
                )
            ) {
                continue;
            }

            $sorts[] = sprintf(
                '%s %s',
                $es($matches['field']),
                match (strtolower($matches['dir'] ?? 'desc')) {
                    default => 'DESC',
                    'asc' => 'ASC',
                },
            );
        }

        if (count($sorts) < 1) {
            $sorts[] = sprintf('%s DESC', $es(iState::COLUMN_UPDATED_AT));
        }

        $sql[] = 'ORDER BY ' . implode(', ', $sorts);

        return ['sql' => implode(' ', array_map('trim', $sql)), 'params' => $params, 'filters' => $filters, 'data' => $data];
    }

    /**
     * List the active user's history using combined filters and pagination.
     */
    #[Get(self::URL . '[/]', name: 'history.list')]
    public function list(iRequest $request): iResponse
    {
        try {
            $userContext = $this->getUserContext(request: $request, mapper: $this->mapper, logger: $this->logger);
        } catch (RuntimeException $e) {
            return api_error($e->getMessage(), Status::NOT_FOUND);
        }

        $query = $this->buildQuery(DataUtil::fromArray($request->getQueryParams()), $userContext);
        if ($query instanceof iResponse) {
            return $query;
        }
        ['sql' => $sql, 'params' => $params, 'filters' => $filters, 'data' => $data] = $query;
        $db = $userContext->db->getDBLayer();
        $stmt = $db->prepare('SELECT COUNT(*) ' . $sql);
        $stmt->execute($params);
        $total = (int) $stmt->fetchColumn();
        if (0 === $total) {
            return api_error('No Results.' . ([] !== $filters ? ' Probably invalid filters values were used.' : ''), Status::NOT_FOUND, [
                'filters' => $filters,
            ]);
        }

        $page = max(1, (int) $data->get('page', 1));
        $perpage = (int) $data->get('perpage', 12);
        $perpage = 0 < $perpage ? $perpage : 20;
        $customView = $data->get('view');
        $params['_start'] = $perpage * ($page - 1);
        $params['_limit'] = $perpage;
        $stmt = $db->prepare('SELECT * ' . $sql . ' LIMIT :_start,:_limit');
        $stmt->execute($params);

        $getUri = $request->getUri()->withHost('')->withPort(0)->withScheme('');

        $pagingUrl = $getUri->withPath($request->getUri()->getPath());

        $firstUrl = (string) $pagingUrl->withQuery(
            http_build_query($data->with('page', 1)->getAll()),
        );
        $nextUrl = $page < @ceil($total / $perpage)
            ? (string) $pagingUrl->withQuery(
                http_build_query($data->with('page', $page + 1)->getAll()),
            )
            : null;

        $totalPages = @ceil($total / $perpage);

        $prevUrl =
            !empty($total) && $page > 1 && $page <= $totalPages
                ? (string) $pagingUrl->withQuery(
                    http_build_query($data->with('page', $page - 1)->getAll()),
                )
                : null;

        $lastUrl = (string) $pagingUrl->withQuery(
            http_build_query($data->with('page', @ceil($total / $perpage))->getAll()),
        );

        $response = [
            'paging' => [
                'total' => (int) $total,
                'perpage' => $perpage,
                'current_page' => $page,
                'first_page' => 1,
                'next_page' => $page < @ceil($total / $perpage) ? $page + 1 : null,
                'prev_page' => !empty($total) && $page > 1 ? $page - 1 : null,
                'last_page' => @ceil($total / $perpage),
            ],
            'filters' => $filters,
            'history' => [],
            'links' => [
                'self' => (string) $getUri,
                'first_url' => $firstUrl,
                'next_url' => $nextUrl,
                'prev_url' => $prevUrl,
                'last_url' => $lastUrl,
            ],
            'searchable' => [
                [
                    'key' => 'id',
                    'description' => 'Search using local history id.',
                    'type' => 'int',
                ],
                [
                    'key' => 'watched',
                    'description' => 'Search using watched status.',
                    'type' => ['0', '1'],
                ],
                [
                    'key' => 'in_progress',
                    'display' => 'In progress',
                    'description' => 'Show movies or episodes you have started but not finished.',
                    'type' => ['1'],
                ],
                [
                    'key' => 'via',
                    'display' => 'Backend',
                    'description' => 'Search using the backend name.',
                    'type' => 'string',
                ],
                [
                    'key' => 'year',
                    'description' => 'Search using the year.',
                    'type' => 'int',
                ],
                [
                    'key' => 'type',
                    'description' => 'Search using the content type.',
                    'type' => [
                        'movie',
                        'episode',
                    ],
                ],
                [
                    'key' => 'title',
                    'description' => 'Search using the title.',
                    'type' => 'string',
                ],
                [
                    'key' => 'season',
                    'description' => 'Search using the season number.',
                    'type' => 'int',
                ],
                [
                    'key' => 'episode',
                    'description' => 'Search using the episode number.',
                    'type' => 'int',
                ],
                [
                    'key' => 'parent',
                    'display' => 'Series GUID',
                    'description' => 'Search using the parent GUID.',
                    'type' => 'provider://id',
                ],
                [
                    'key' => 'guids',
                    'display' => 'Content GUID',
                    'description' => 'Search using the GUID.',
                    'type' => 'provider://id',
                ],
                [
                    'key' => 'metadata',
                    'description' => 'Search using the metadata JSON field. Searching this field might be slow.',
                    'type' => 'backend.field://value',
                ],
                [
                    'key' => 'extra',
                    'description' => 'Search using the extra JSON field. Searching this field might be slow.',
                    'type' => 'backend.field://value',
                ],
                [
                    'key' => 'rguid',
                    'description' => 'Search using the rGUID.',
                    'type' => 'guid://parentID/seasonNumber[/episodeNumber]',
                ],
                [
                    'key' => 'path',
                    'description' => 'Search using file path. Searching this field might be slow.',
                    'type' => 'string',
                ],
                [
                    'key' => 'subtitle',
                    'display' => 'Subtitle',
                    'description' => 'Search using subtitle. Searching this field will be slow.',
                    'type' => 'string',
                ],
                [
                    'key' => 'genres',
                    'display' => 'Genre',
                    'description' => 'Search using genres. Searching this field will be slow.',
                    'type' => 'string',
                ],
            ],
        ];

        while ($row = $stmt->fetch()) {
            $record = StateEntity::fromArray($row);
            $record->metadata = array_filter($record->metadata, is_array(...));
            $entity = $this->formatEntity($record, userContext: $userContext);
            $entity['duplicate_reference_ids'] = [];

            if (null !== $customView) {
                $customEntity = [];
                foreach (explode(',', $customView) as $k) {
                    $customEntity = ag_set($customEntity, $k, ag($entity, $k, null));
                }
                $entity = $customEntity;
            } elseif ($data->get('with_duplicates')) {
                try {
                    $entity['duplicate_reference_ids'] = array_reduce(
                        array_values(
                            array_map(
                                static fn(iState $i) => $i->id,
                                $userContext->db->duplicates(StateEntity::fromArray($entity), $userContext->cache),
                            ),
                        ),
                        static fn(array $r, $i) => $i !== $entity['id'] ? [...$r, $i] : $r,
                        [],
                    );
                } catch (Throwable $e) {
                    $this->logger->error(
                        "Failed to load duplicates for '#{item.id}: {item.title}'. {exception.message}",
                        [
                            'operation' => 'history.duplicates',
                            'identity' => [
                                'user' => $userContext->name,
                            ],
                            'item' => [
                                'id' => $entity['id'] ?? null,
                                'title' => ag($entity, 'title', '??'),
                            ],
                            ...exception_log($e),
                        ],
                    );
                }
            }

            $response['history'][] = $entity;
        }

        return api_response(Status::OK, $response);
    }

    /**
     * Return genres and library sources present in the active user's local records.
     */
    #[Get(self::URL . '/options[/]', name: 'history.options')]
    public function options(iRequest $request): iResponse
    {
        try {
            $context = $this->getUserContext($request, $this->mapper, $this->logger);
        } catch (RuntimeException $e) {
            return api_error($e->getMessage(), Status::NOT_FOUND);
        }

        $cacheKey = 'history_options_' . hash('sha256', $context->name);
        $cached = $context->cache->get($cacheKey);
        if (is_array($cached)) {
            return api_response(Status::OK, $cached);
        }

        $genres = [];
        $sources = [];
        foreach ($context->db->getDBLayer()->query('SELECT metadata FROM state') as $row) {
            $entity = StateEntity::fromArray($row);
            foreach (HistoryExport::genres($entity) as $genre) {
                $genres[$genre] = $genre;
            }
            foreach ($entity->getMetadata() as $backend => $metadata) {
                if (false === is_array($metadata)) {
                    continue;
                }
                $id = $metadata[iState::COLUMN_META_LIBRARY] ?? null;
                if (false === is_scalar($id) || '' === trim((string) $id)) {
                    continue;
                }
                $sources[(string) $backend][(string) $id] = (string) $id;
            }
        }

        $libraries = [];
        foreach ($sources as $backend => $ids) {
            $namesKey = 'history_library_names_' . hash('sha256', $context->name . ':' . $backend);
            $names = $context->cache->get($namesKey);
            if (false === is_array($names)) {
                $names = [];
                if (null !== $context->config->get($backend)) {
                    try {
                        $client = $this->getClient(
                            $backend,
                            [
                                'options' => ['client' => ['timeout' => 2.0, 'max_duration' => 3.0]],
                            ],
                            $context,
                        );
                        foreach ($client->listLibraries() as $library) {
                            $id = $library['id'] ?? null;
                            $title = $library['title'] ?? null;
                            if (is_scalar($id) && is_string($title) && '' !== trim($title)) {
                                $names[(string) $id] = $title;
                            }
                        }
                    } catch (Throwable $e) {
                        $this->logger->warning(
                            "Could not resolve library names for '{identity.user}@{identity.backend}': {exception.message}",
                            [
                                'identity' => ['user' => $context->name, 'backend' => $backend],
                                'operation' => 'history.library_options',
                                'error' => 'library_names_unavailable',
                                ...exception_log($e),
                            ],
                        );
                    }
                }
                $context->cache->set($namesKey, $names, 300);
            }
            foreach ($ids as $id) {
                $libraries[] = [
                    'value' => $backend . '://' . $id,
                    'label' => $backend . ' / ' . ($names[$id] ?? $id),
                ];
            }
        }
        $genres = array_values($genres);
        sort($genres, SORT_NATURAL | SORT_FLAG_CASE);
        usort($libraries, static fn(array $left, array $right): int => strnatcasecmp($left['label'], $right['label']));
        $result = ['genres' => $genres, 'libraries' => $libraries];
        $context->cache->set($cacheKey, $result, 120);

        return api_response(Status::OK, $result);
    }

    /**
     * Export all matching records, optionally intersected with an explicit selection.
     */
    #[Post(self::URL . '/export/{format:json|markdown}[/]', name: 'history.export')]
    public function export(iRequest $request, string $format, HistoryExport $exporter): iResponse
    {
        if (false === in_array($format, ['json', 'markdown'], true)) {
            return api_error('Export format not found.', Status::NOT_FOUND);
        }
        try {
            $context = $this->getUserContext($request, $this->mapper, $this->logger);
        } catch (RuntimeException $e) {
            return api_error($e->getMessage(), Status::NOT_FOUND);
        }

        $body = $request->getParsedBody();
        if (
            false === is_array($body)
            || false === is_array($body['filters'] ?? null)
            || [] !== $body['filters'] && array_is_list($body['filters'])
        ) {
            return api_error('Expected an export object with filters.', Status::BAD_REQUEST);
        }
        $grouping = $body['group'] ?? 'titles';
        if (false === in_array($grouping, ['records', 'titles'], true)) {
            return api_error('Invalid export grouping.', Status::BAD_REQUEST);
        }
        $ids = null;
        if (array_key_exists('ids', $body)) {
            $ids = $body['ids'];
            if (
                false === is_array($ids)
                || [] === $ids
                || false === array_is_list($ids)
                || false === array_all($ids, static fn(mixed $id): bool => is_int($id) && 0 < $id)
            ) {
                return api_error('Selection must be a nonempty list of positive integer IDs.', Status::BAD_REQUEST);
            }
            $ids = array_values(array_unique($ids));
        }
        $filters = array_intersect_key($body['filters'], array_flip([
            'rguid',
            'watched',
            'in_progress',
            'id',
            'via',
            'year',
            'type',
            'title',
            'season',
            'episode',
            'parent',
            'guids',
            'metadata',
            'key',
            'value',
            'exact',
            'path',
            'subtitle',
            'extra',
            'genres',
            'status',
            'library',
            'year_min',
            'year_max',
            'sort',
        ]));
        $query = $this->buildQuery(DataUtil::fromArray($filters), $context, $ids);
        if ($query instanceof iResponse) {
            return $query;
        }
        $stmt = $context
            ->db
            ->getDBLayer()
            ->prepare(
                'SELECT type, updated, watched, title, year, season, episode, parent, metadata, extra ' . $query['sql'],
            );
        $stmt->execute($query['params']);
        $stmt->setFetchMode(PDO::FETCH_ASSOC);
        $stream = Stream::make('php://temp', 'w+b');
        $count = $exporter->write($stream, $exporter->items($stmt, $context, $grouping), $grouping, $format);
        $stmt->closeCursor();
        if (0 === $count) {
            $stream->close();
            return api_error('No matching history records.', Status::NOT_FOUND);
        }
        $stream->rewind();
        $extension = 'json' === $format ? 'json' : 'md';

        return api_response(Status::OK, $stream, headers: [
            'Content-Type' => 'json' === $format ? 'application/json' : 'text/markdown; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="history-' . time() . '.' . $extension . '"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    #[Get(self::URL . '/{id:\d+}[/]', name: 'history.read')]
    public function read(iRequest $request, string $id): iResponse
    {
        try {
            $userContext = $this->getUserContext(request: $request, mapper: $this->mapper, logger: $this->logger);
        } catch (RuntimeException $e) {
            return api_error($e->getMessage(), Status::NOT_FOUND);
        }

        $entity = Container::get(iState::class)::fromArray([iState::COLUMN_ID => $id]);

        if (null === ($item = $userContext->db->get($entity))) {
            return api_error('Not found', Status::NOT_FOUND);
        }

        $entity = $this->formatEntity($item, userContext: $userContext);

        if (!empty($entity['content_path'])) {
            $entity['content_exists'] = file_exists($entity['content_path']);
        }

        $params = DataUtil::fromArray($request->getQueryParams());

        if ($params->get('files')) {
            $ffprobe = [];

            foreach ($item->getMetadata() as $backend => $metadata) {
                if (null === ($file = ag($metadata, 'path', null))) {
                    continue;
                }

                if (false !== ($key = array_search($file, array_column($ffprobe, 'path'), true))) {
                    $ffprobe[$key]['source'][] = $backend;
                    continue;
                }

                if (false === file_exists($file)) {
                    continue;
                }

                try {
                    $data = ffprobe_file($file, $userContext->cache);
                } catch (RuntimeException|JsonException) {
                    continue;
                }

                $ffprobe[] = [
                    'path' => $file,
                    'source' => [$backend],
                    'ffprobe' => $data,
                    'subtitles' => array_values(array_filter(
                        find_side_car_files(new SplFileInfo($file)),
                        static fn($sideCar) => isset(Subtitle::FORMATS[get_extension($sideCar)]),
                    )),
                ];
            }

            $entity['files'] = $ffprobe;

            $entity['hardware'] = [
                'codecs' => [
                    [
                        'codec' => 'libx264',
                        'name' => 'H.264 (CPU) (All)',
                        'hwaccel' => false,
                    ],
                    [
                        'codec' => 'h264_vaapi',
                        'name' => 'H.264 (VA-API) (VAAPI)',
                        'hwaccel' => true,
                    ],
                    [
                        'codec' => 'h264_nvenc',
                        'name' => 'H.264 (NVENC) (Nvidia)',
                        'hwaccel' => true,
                    ],
                    [
                        'codec' => 'h264_qsv',
                        'name' => 'H.264 (QSV) (Intel)',
                        'hwaccel' => true,
                    ],
                ],
                'devices' => [],
            ];

            if (is_dir('/dev/dri/') && is_readable('/dev/dri/')) {
                try {
                    foreach (scandir('/dev/dri') as $dev) {
                        if (false === str_starts_with($dev, 'render')) {
                            continue;
                        }
                        $entity['hardware']['devices'][] = '/dev/dri/' . $dev;
                    }
                } catch (Throwable) {
                }
            }
        }

        $entity['duplicate_reference_ids'] = [];

        if ($params->get('with_duplicates')) {
            try {
                $entity['duplicate_reference_ids'] = array_reduce(
                    array_values(
                        array_map(
                            static fn(iState $i) => $i->id,
                            $userContext->db->duplicates($item, $userContext->cache),
                        ),
                    ),
                    static fn(array $r, $i) => $i !== $item->id ? [...$r, $i] : $r,
                    [],
                );
            } catch (Throwable $e) {
                $this->logger->error(
                    "Failed to load duplicates for '#{item.id}: {item.title}'. {exception.message}",
                    [
                        'operation' => 'history.duplicates',
                        'identity' => [
                            'user' => $userContext->name,
                        ],
                        'item' => [
                            'id' => $item->id,
                            'title' => $item->getName(),
                        ],
                        ...exception_log($e),
                    ],
                );
            }
        }

        return api_response(Status::OK, $entity);
    }

    /**
     * Return logs and persisted events structurally related to a history record.
     *
     * @param iCache $cache The application cache.
     * @param iRequest $request The HTTP request.
     * @param string $id The local history record ID.
     *
     * @return iResponse The related link metadata response.
     */
    #[Get(self::URL . '/{id:\d+}/related[/]', name: 'history.related')]
    public function related(iCache $cache, iRequest $request, string $id): iResponse
    {
        try {
            $userContext = $this->getUserContext(request: $request, mapper: $this->mapper, logger: $this->logger);
        } catch (RuntimeException $e) {
            return api_error($e->getMessage(), Status::NOT_FOUND);
        }

        $entity = Container::get(iState::class)::fromArray([iState::COLUMN_ID => $id]);
        if (null === $userContext->db->get($entity)) {
            return api_error('Not found', Status::NOT_FOUND);
        }

        $cacheKey = 'history_related_' . hash('sha256', $userContext->name . ':' . $id);
        $result = cacheable_item(
            $cacheKey,
            fn(): array => $this->findRelated($id, $userContext->name),
            new DateInterval('PT300S'),
            opts: [iCache::class => $cache],
        );

        return api_response(Status::OK, $result);
    }

    #[Get(self::URL . '/{id:\d+}/duplicates[/]', name: 'history.duplicates')]
    public function duplicates(iRequest $request, string $id): iResponse
    {
        try {
            $userContext = $this->getUserContext(request: $request, mapper: $this->mapper, logger: $this->logger);
        } catch (RuntimeException $e) {
            return api_error($e->getMessage(), Status::NOT_FOUND);
        }

        $entity = Container::get(iState::class)::fromArray([iState::COLUMN_ID => $id]);

        if (null === ($item = $userContext->db->get($entity))) {
            return api_error('Not found', Status::NOT_FOUND);
        }

        $data = [
            'duplicate_reference_ids' => [],
        ];

        try {
            $data['duplicate_reference_ids'] = array_reduce(
                array_values(
                    array_map(
                        static fn(iState $i) => $i->id,
                        $userContext->db->duplicates($item, $userContext->cache),
                    ),
                ),
                static fn(array $r, $i) => $i !== $item->id ? [...$r, $i] : $r,
                [],
            );
        } catch (Throwable $e) {
            $this->logger->error(
                "Failed to load duplicates for '#{item.id}: {item.title}'. {exception.message}",
                [
                    'operation' => 'history.duplicates',
                    'identity' => [
                        'user' => $userContext->name,
                    ],
                    'item' => [
                        'id' => $item->id,
                        'title' => $item->getName(),
                    ],
                    ...exception_log($e),
                ],
            );
            return api_error('Failed to get duplicates', Status::INTERNAL_SERVER_ERROR);
        }

        return api_response(Status::OK, $data, headers: ['X-No-AccessLog' => '1']);
    }

    #[Delete(self::URL . '/{id:\d+}[/]', name: 'history.delete')]
    public function delete(iRequest $request, string $id): iResponse
    {
        try {
            $userContext = $this->getUserContext(request: $request, mapper: $this->mapper, logger: $this->logger);
        } catch (RuntimeException $e) {
            return api_error($e->getMessage(), Status::NOT_FOUND);
        }

        $entity = Container::get(iState::class)::fromArray([iState::COLUMN_ID => $id]);

        if (null === ($item = $userContext->db->get($entity))) {
            return api_error('item Not found.', Status::NOT_FOUND);
        }

        $userContext->db->remove($item);

        return api_response(Status::OK);
    }

    #[Route(['GET', 'POST', 'DELETE'], self::URL . '/{id:\d+}/watch[/]', name: 'history.watch')]
    public function changePlayState(iRequest $request, string $id): iResponse
    {
        try {
            $userContext = $this->getUserContext(request: $request, mapper: $this->mapper, logger: $this->logger);
        } catch (RuntimeException $e) {
            return api_error($e->getMessage(), Status::NOT_FOUND);
        }

        $entity = Container::get(iState::class)::fromArray([iState::COLUMN_ID => $id]);

        if (null === ($item = $userContext->db->get($entity))) {
            return api_error('Not found', Status::NOT_FOUND);
        }

        if ('GET' === $request->getMethod()) {
            return api_response(Status::OK, ['watched' => $item->isWatched()]);
        }

        if ('POST' === $request->getMethod() && true === $item->isWatched()) {
            return api_error('Already watched', Status::CONFLICT);
        }

        if ('DELETE' === $request->getMethod() && false === $item->isWatched()) {
            return api_error('Already unwatched', Status::CONFLICT);
        }

        $item->watched = 'POST' === $request->getMethod() ? 1 : 0;
        $item->updated = time();
        $item->extra = ag_set($item->getExtra(), $item->via, [
            iState::COLUMN_EXTRA_EVENT => 'webui.mark' . ($item->isWatched() ? 'played' : 'unplayed'),
            iState::COLUMN_EXTRA_DATE => (string) make_date('now'),
        ]);

        $userContext->mapper->add($item)->commit();

        queue_push($item, userContext: $userContext);

        return $this->read($request, $id);
    }

    #[Get(self::URL . '/{id:\d+}/validate[/]', name: 'history.validate')]
    public function validate_item(iCache $cache, iRequest $request, string $id): iResponse
    {
        try {
            $userContext = $this->getUserContext(request: $request, mapper: $this->mapper, logger: $this->logger);
        } catch (RuntimeException $e) {
            return api_error($e->getMessage(), Status::NOT_FOUND);
        }

        $entity = Container::get(iState::class)::fromArray([iState::COLUMN_ID => $id]);

        if (null === ($item = $userContext->db->get($entity))) {
            return api_error('Item Not found', Status::NOT_FOUND);
        }

        $cacheKey = r('validate_item_{id}_{user}_{backends}', [
            'id' => $item->id,
            'user' => $userContext->name,
            'backends' => md5(implode(',', array_keys($item->getMetadata()))),
        ]);

        try {
            if (null !== ($cached = $cache->get($cacheKey))) {
                return api_response(Status::OK, $cached, headers: ['X-Cache' => 'HIT']);
            }
        } catch (Throwable) {
            // Ignore cache errors.
        }

        $validation = [];

        foreach ($item->getMetadata() as $name => $metadata) {
            $id = ag($metadata, iState::COLUMN_ID, null);
            $validation[$name] = [
                'id' => $id,
                'status' => false,
                'message' => 'Item not found.',
            ];

            if (null === $userContext->config->get($name)) {
                $validation[$name]['message'] = 'Backend not found.';
                continue;
            }

            if (null === $id) {
                $validation[$name]['message'] = 'Item ID is missing.';
                continue;
            }

            try {
                $client = $this->getClient(name: $name, userContext: $userContext);
                $item = $client->getMetadata($id, [Options::NO_LOGGING => true]);
                if (count($item) > 0) {
                    $validation[$name]['status'] = true;
                    $validation[$name]['message'] = 'Item found.';
                } else {
                    $validation[$name]['status'] = false;
                    $validation[$name]['message'] = 'Item not found.';
                }
            } catch (Throwable $e) {
                $validation[$name]['message'] = $e->getMessage();
            }
        }

        try {
            $cache->set($cacheKey, $validation, new DateInterval('PT10M'));
        } catch (Throwable) {
            // Ignore cache errors.
        }

        return api_response(Status::OK, $validation, headers: ['X-Cache' => 'MISS']);
    }

    #[Delete(self::URL . '/{id:\d+}/metadata/{backend}[/]', name: 'history.metadata.delete')]
    public function delete_item_metadata(iRequest $request, string $id, string $backend): iResponse
    {
        try {
            $userContext = $this->getUserContext(request: $request, mapper: $this->mapper, logger: $this->logger);
        } catch (RuntimeException $e) {
            return api_error($e->getMessage(), Status::NOT_FOUND);
        }

        $entity = Container::get(iState::class)::fromArray([iState::COLUMN_ID => $id]);

        if (null === ($item = $userContext->db->get($entity))) {
            return api_error('Item Not found', Status::NOT_FOUND);
        }

        if (null === ($item->getMetadata($backend) ?? null)) {
            return api_error('Item metadata not found.', Status::NOT_FOUND);
        }

        if (count($item->removeMetadata($backend)) < 1) {
            return api_error('Item metadata not found.', Status::NOT_FOUND);
        }

        if (count($item->getMetadata()) < 1) {
            $userContext->db->remove($item);
            return api_message('Record deleted.', Status::OK);
        }

        $userContext->db->update($item);
        return $this->read($request, $id);
    }

    #[Get(self::URL . '/{id:\d+}/images/{type:poster|background}[/]', name: 'history.item.images')]
    public function images(iRequest $request, string $id, string $type): iResponse
    {
        if ($request->hasHeader('if-modified-since')) {
            return api_response(Status::NOT_MODIFIED, headers: ['Cache-Control' => 'public, max-age=25920000']);
        }

        try {
            $userContext = $this->getUserContext(request: $request, mapper: $this->mapper, logger: $this->logger);
        } catch (RuntimeException $e) {
            return api_error($e->getMessage(), Status::NOT_FOUND);
        }

        $entity = Container::get(iState::class)::fromArray([iState::COLUMN_ID => $id]);

        if (null === ($item = $userContext->db->get($entity))) {
            return api_error('Not found.', Status::NOT_FOUND);
        }

        if (null === ($rId = ag($item->getMetadata($item->via), $item->isMovie() ? 'id' : 'show', null))) {
            return api_error('Remote item id not found.', Status::NOT_FOUND);
        }

        try {
            $client = $this->getClient(
                name: $item->via,
                config: [
                    'options' => [
                        'client' => [
                            'timeout' => self::IMAGE_TIMEOUT_SECONDS,
                            'max_duration' => self::IMAGE_TIMEOUT_SECONDS,
                        ],
                    ],
                ],
                userContext: $userContext,
            );
            $images = $client->getImagesUrl($rId);
        } catch (RuntimeException $e) {
            return api_error('Failed to fetch image.', Status::BAD_REQUEST);
        }

        if (false === array_key_exists($type, $images)) {
            return api_error('Invalid image type.', Status::BAD_REQUEST);
        }

        if (null === ($images[$type] ?? null)) {
            return api_error('Image not available via this backend.', Status::NOT_FOUND);
        }

        $apiRequest = $client->proxy(Method::GET, $images[$type]);

        if (false === $apiRequest->isSuccessful()) {
            return api_error('Failed to fetch image.', Status::BAD_REQUEST);
        }

        $response = $apiRequest->response;
        assert($response instanceof APIResponse, 'Expected APIResponse from image proxy.');

        if (Status::OK !== $response->status) {
            return api_error(r('Failed to fetch image.'), $response->status);
        }

        return api_response($response->status, body: $response->stream, headers: [
            'Pragma' => 'public',
            'Cache-Control' => sprintf('public, max-age=%s', time() + 31_536_000),
            'Last-Modified' => sprintf('%s GMT', gmdate('D, d M Y H:i:s', time())),
            'Expires' => sprintf('%s GMT', gmdate('D, d M Y H:i:s', time() + 31_536_000)),
            'Content-Type' => ag($response->headers, 'content-type', 'image/jpeg'),
            'X-Via' => r('{user}@{backend}', ['user' => $userContext->name, 'backend' => $item->via]),
        ]);
    }

    /**
     * @return array{logs:array<array{filename:string,entry:array<string,mixed>}>,events:array<array{id:string,name:string,status:int,status_name:string,timestamp:string}>}
     */
    private function findRelated(string $historyId, string $user): array
    {
        $logPath = realpath(fix_path((string) Config::get('tmpDir') . '/logs'));
        $logs = false === $logPath || false === is_dir($logPath) ? [] : $this->findLogs($logPath, $historyId, $user);

        $events = [];
        foreach ($this->events->findRelatedHistory($historyId, $user) as $event) {
            $events[] = [
                'id' => (string) $event->id,
                'name' => $event->event,
                'status' => $event->status->value,
                'status_name' => $event->getStatusText(),
                'timestamp' => (string) $event->created_at,
            ];
        }

        usort($logs, static function (array $left, array $right): int {
            if (0 !== ($dateCmp = strcmp((string) ag($right, 'entry.datetime', ''), (string) ag($left, 'entry.datetime', '')))) {
                return $dateCmp;
            }

            if (0 !== ($fileCmp = strcmp((string) $left['filename'], (string) $right['filename']))) {
                return $fileCmp;
            }

            return strcmp((string) ag($left, 'entry.id', ''), (string) ag($right, 'entry.id', ''));
        });

        return ['logs' => $logs, 'events' => $events];
    }

    /**
     * @return array<array{filename:string,entry:array<string,mixed>}>
     */
    private function findLogs(string $logPath, string $historyId, string $user): array
    {
        if (null === ($ripgrep = new ExecutableFinder()->find('rg'))) {
            return [];
        }

        $files = glob($logPath . '/*.jsonl');
        if (false === $files || count($files) < 1) {
            return [];
        }

        try {
            return $this->searchLogs($ripgrep, $files, $historyId, $user) ?? [];
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @param array<string> $logFiles
     * @return array<array{filename:string,entry:array<string,mixed>}>|null
     */
    private function searchLogs(string $bin, array $logFiles, string $historyId, string $user): ?array
    {
        $pattern = '"history\\.id"\\s*:\\s*"?' . preg_quote($historyId, '/') . '"?\\s*[,}]';
        $process = new Process([$bin, '--json', '--no-config', '--no-messages', '--regexp', $pattern, '--', ...$logFiles]);
        $process->setTimeout(30);
        $process->start();

        $logs = [];
        $buffer = '';
        foreach ($process as $type => $chunk) {
            if (Process::OUT !== $type) {
                continue;
            }

            $buffer .= $chunk;
            while (false !== ($position = strpos($buffer, "\n"))) {
                $line = substr($buffer, 0, $position);
                $buffer = substr($buffer, $position + 1);
                $this->appendSearchMatch($logs, $line, $historyId, $user);
            }
        }

        if ('' !== trim($buffer)) {
            $this->appendSearchMatch($logs, $buffer, $historyId, $user);
        }

        return in_array($process->getExitCode(), [0, 1], true) ? $logs : null;
    }

    /**
     * @param array<array{filename:string,entry:array<string,mixed>}> $logs
     */
    private function appendSearchMatch(array &$logs, string $line, string $historyId, string $user): void
    {
        $match = json_decode($line, true, flags: JSON_INVALID_UTF8_IGNORE);
        if (false === is_array($match) || 'match' !== ($match['type'] ?? null)) {
            return;
        }

        $payload = LogsIndex::decodeJsonlLine((string) ag($match, 'data.lines.text', ''));
        $path = ag($match, 'data.path.text');
        if (
            null === $payload
            || false === is_string($path)
            || false === $this->matchesStructured($payload['fields'] ?? [], $historyId, $user)
        ) {
            return;
        }

        $logs[] = ['filename' => basename($path), 'entry' => $payload];
    }

    private function matchesStructured(mixed $value, string $historyId, string $user): bool
    {
        if (false === is_array($value)) {
            return false;
        }

        $history = $value['history.id'] ?? ag($value, 'history.id');
        $identity = $value['identity.user'] ?? ag($value, 'identity.user');

        return null !== $history && null !== $identity && (string) $history === $historyId && (string) $identity === $user;
    }
}
