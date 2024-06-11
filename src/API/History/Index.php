<?php

declare(strict_types=1);

namespace App\API\History;

use App\Commands\Database\ListCommand;
use App\Libs\Attributes\Route\Get;
use App\Libs\Attributes\Route\Route;
use App\Libs\Container;
use App\Libs\Database\DatabaseInterface as iDB;
use App\Libs\DataUtil;
use App\Libs\Entity\StateInterface as iState;
use App\Libs\Guid;
use App\Libs\HTTP_STATUS;
use App\Libs\Mappers\Import\DirectMapper;
use App\Libs\Traits\APITraits;
use PDO;
use Psr\Http\Message\ResponseInterface as iResponse;
use Psr\Http\Message\ServerRequestInterface as iRequest;

final class Index
{
    use APITraits;

    public const string URL = '%{api.prefix}/history';
    private PDO $pdo;

    public function __construct(private readonly iDB $db, private DirectMapper $mapper)
    {
        $this->pdo = $this->db->getPDO();
    }

    #[Get(self::URL . '[/]', name: 'history')]
    public function historyIndex(iRequest $request): iResponse
    {
        $es = fn(string $val) => $this->db->identifier($val);
        $data = DataUtil::fromArray($request->getQueryParams());
        $filters = [];

        $page = (int)$data->get('page', 1);
        $perpage = (int)$data->get('perpage', 12);

        $start = (($page <= 2) ? ((1 === $page) ? 0 : $perpage) : $perpage * ($page - 1));
        $start = (!$page) ? 0 : $start;

        $params = [];

        $sql = $where = $or = [];

        $sql[] = sprintf('FROM %s', $es('state'));

        // -- search relative guids.
        if ($data->get('rguid')) {
            $regex = '/(?<guid>\w+)\:\/\/(?<parentID>.+?)\/(?<seasonNumber>\w+)(\/(?<episodeNumber>.+))?/is';
            if (1 !== preg_match($regex, $data->get('rguid'), $matches)) {
                return api_error(
                    'Invalid value for rguid query string expected value format is guid://parentID/seasonNumber[/episodeNumber].',
                    HTTP_STATUS::HTTP_BAD_REQUEST
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
                    HTTP_STATUS::HTTP_BAD_REQUEST
                );
            }

            $where[] = "json_extract(" . iState::COLUMN_PARENT . ",'$.{$parent}') = :" . iState::COLUMN_PARENT;
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
                    HTTP_STATUS::HTTP_BAD_REQUEST
                );
            }

            $where[] = "json_extract(" . iState::COLUMN_GUIDS . ",'$.{$guid}') = :" . iState::COLUMN_GUIDS;
            $params[iState::COLUMN_GUIDS] = array_values($d->getAll())[0];
            $filters[iState::COLUMN_GUIDS] = $data->get(iState::COLUMN_GUIDS);
        }

        if ($data->get(iState::COLUMN_META_DATA)) {
            $sField = $data->get('key');
            $sValue = $data->get('value');
            if (null === $sField || null === $sValue) {
                return api_error(
                    'When searching using JSON fields the query string \'key\' and \'value\' must be set.',
                    HTTP_STATUS::HTTP_BAD_REQUEST
                );
            }

            if (preg_match('/[^a-zA-Z0-9_\.]/', $sField)) {
                return api_error(
                    'Invalid value for key query string expected value format is [a-zA-Z0-9_].',
                    HTTP_STATUS::HTTP_BAD_REQUEST
                );
            }

            if ($data->get('exact')) {
                $where[] = "json_extract(" . iState::COLUMN_META_DATA . ",'$.{$sField}') = :jf_metadata_value";
            } else {
                $where[] = "json_extract(" . iState::COLUMN_META_DATA . ",'$.{$sField}') LIKE \"%\" || :jf_metadata_value || \"%\"";
            }

            $params['jf_metadata_value'] = $sValue;
            $filters[iState::COLUMN_META_DATA] = [
                'key' => $sField,
                'value' => $sValue,
                'exact' => $data->get('exact'),
            ];
        }

        if ($data->get(iState::COLUMN_META_PATH)) {
            foreach ($this->getBackends() as $backend) {
                $bName = $backend['name'];

                if ($data->get('exact')) {
                    $or[] = "json_extract(" . iState::COLUMN_META_DATA . ",'$.{$bName}.path') = :path_{$bName}";
                } else {
                    $or[] = "json_extract(" . iState::COLUMN_META_DATA . ",'$.{$bName}.path') LIKE \"%\" || :path_{$bName} || \"%\"";
                }

                $params["path_{$bName}"] = $data->get(iState::COLUMN_META_PATH);
            }

            $filters[iState::COLUMN_META_PATH] = $data->get(iState::COLUMN_META_PATH);
        }

        if ($data->get('subtitle')) {
            foreach ($this->getBackends() as $backend) {
                $bName = $backend['name'];

                if ($data->get('exact')) {
                    $or[] = "json_extract(" . iState::COLUMN_META_DATA . ",'$.{$bName}.extra.title') = :subtitle_{$bName}";
                } else {
                    $or[] = "json_extract(" . iState::COLUMN_META_DATA . ",'$.{$bName}.extra.title') LIKE \"%\" || :subtitle_{$bName} || \"%\"";
                }

                $params["subtitle_{$bName}"] = $data->get('subtitle');
            }

            $filters['subtitle'] = $data->get('subtitle');
        }

        if ($data->get(iState::COLUMN_EXTRA)) {
            $sField = $data->get('key');
            $sValue = $data->get('value');
            if (null === $sField || null === $sValue) {
                return api_error(
                    'When searching using JSON fields the query string \'key\' and \'value\' must be set.',
                    HTTP_STATUS::HTTP_BAD_REQUEST
                );
            }

            if (preg_match('/[^a-zA-Z0-9_\.]/', $sField)) {
                return api_error(
                    'Invalid value for key query string expected value format is [a-zA-Z0-9_].',
                    HTTP_STATUS::HTTP_BAD_REQUEST
                );
            }

            if ($data->get('exact')) {
                $where[] = "json_extract(" . iState::COLUMN_EXTRA . ",'$.{$sField}') = :jf_extra_value";
            } else {
                $where[] = "json_extract(" . iState::COLUMN_EXTRA . ",'$.{$sField}') LIKE \"%\" || :jf_extra_value || \"%\"";
            }

            $params['jf_extra_value'] = $sValue;
            $filters['extra'] = [
                'key' => $sField,
                'value' => $sValue,
                'exact' => $data->get('exact'),
            ];
        }

        if (count($or) >= 1) {
            $where[] = '( ' . implode(' OR ', $or) . ' )';
        }

        if (count($where) >= 1) {
            $sql[] = 'WHERE ' . implode(' AND ', $where);
        }

        $stmt = $this->pdo->prepare('SELECT COUNT(*) ' . implode(' ', array_map('trim', $sql)));
        $stmt->execute($params);
        $total = $stmt->fetchColumn();

        if (0 === $total) {
            $message = 'No Results.';

            if (true === count($filters) >= 1) {
                $message .= ' Probably invalid filters values were used.';
            }

            return api_error($message, HTTP_STATUS::HTTP_NOT_FOUND, ['filters' => $filters]);
        }

        $sorts = [];

        foreach ($data->get('sort', []) as $sort) {
            if (1 !== preg_match('/(?P<field>\w+)(:(?P<dir>\w+))?/', $sort, $matches)) {
                continue;
            }

            if (null === ($matches['field'] ?? null) || false === in_array(
                    $matches['field'],
                    ListCommand::COLUMNS_SORTABLE
                )) {
                continue;
            }

            $sorts[] = sprintf(
                '%s %s',
                $es($matches['field']),
                match (strtolower($matches['dir'] ?? 'desc')) {
                    default => 'DESC',
                    'asc' => 'ASC',
                }
            );
        }

        if (count($sorts) < 1) {
            $sorts[] = sprintf('%s DESC', $es('updated'));
        }

        $params['_start'] = $start;
        $params['_limit'] = $perpage <= 0 ? 20 : $perpage;
        $sql[] = 'ORDER BY ' . implode(', ', $sorts) . ' LIMIT :_start,:_limit';

        $stmt = $this->pdo->prepare('SELECT * ' . implode(' ', array_map('trim', $sql)));
        $stmt->execute($params);

        $getUri = $request->getUri()->withHost('')->withPort(0)->withScheme('');

        $pagingUrl = $getUri->withPath($request->getUri()->getPath());

        $firstUrl = (string)$pagingUrl->withQuery(
            http_build_query($data->with('page', 1)->getAll())
        );
        $nextUrl = $page < @ceil($total / $perpage) ? (string)$pagingUrl->withQuery(
            http_build_query($data->with('page', $page + 1)->getAll())
        ) : null;

        $totalPages = @ceil($total / $perpage);

        $prevUrl = !empty($total) && $page > 1 && $page <= $totalPages ? (string)$pagingUrl->withQuery(
            http_build_query($data->with('page', $page - 1)->getAll())
        ) : null;

        $lastUrl = (string)$pagingUrl->withQuery(
            http_build_query($data->with('page', @ceil($total / $perpage))->getAll())
        );

        $response = [
            'paging' => [
                'total' => (int)$total,
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
                'self' => (string)$getUri,
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
                    'display' => 'Content title',
                    'description' => 'Search using content title. Searching this field will be slow.',
                    'type' => 'string',
                ],
            ],
        ];

        while ($row = $stmt->fetch()) {
            $entity = Container::get(iState::class)->fromArray($row);
            $item = $entity->getAll();

            $item[iState::COLUMN_WATCHED] = $entity->isWatched();
            $item[iState::COLUMN_UPDATED] = makeDate($entity->updated);

            if (!$data->get('metadata')) {
                unset($item[iState::COLUMN_META_DATA]);
            }

            if (!$data->get('extra')) {
                unset($item[iState::COLUMN_EXTRA]);
            }

            $item['full_title'] = $entity->getName();
            $item[iState::COLUMN_META_DATA_PROGRESS] = $entity->hasPlayProgress() ? $entity->getPlayProgress() : null;
            $item[iState::COLUMN_EXTRA_EVENT] = ag($entity->getExtra($entity->via), iState::COLUMN_EXTRA_EVENT, null);
            $item[iState::COLUMN_TITLE] = $entity->isEpisode() ? ag(
                $entity->getMetadata($entity->via),
                iState::COLUMN_EXTRA . '.' . iState::COLUMN_TITLE,
                null
            ) : null;
            $item[iState::COLUMN_META_PATH] = ag($entity->getMetadata($entity->via), iState::COLUMN_META_PATH);

            $item['rguids'] = [];

            if ($entity->isEpisode()) {
                foreach ($entity->getRelativeGuids() as $rKey => $rGuid) {
                    $item['rguids'][$rKey] = $rGuid;
                }
            }

            $response['history'][] = $item;
        }

        return api_response(HTTP_STATUS::HTTP_OK, $response);
    }

    #[Get(self::URL . '/{id:\d+}[/]', name: 'history.view')]
    public function historyView(iRequest $request, array $args = []): iResponse
    {
        if (null === ($id = ag($args, 'id'))) {
            return api_error('Invalid value for id path parameter.', HTTP_STATUS::HTTP_BAD_REQUEST);
        }

        $entity = Container::get(iState::class)::fromArray([iState::COLUMN_ID => $id]);

        if (null === ($item = $this->db->get($entity))) {
            return api_error('Not found', HTTP_STATUS::HTTP_NOT_FOUND);
        }

        $r = $item->getAll();
        $r['full_title'] = $item->getName();
        $r[iState::COLUMN_META_DATA_PROGRESS] = $item->hasPlayProgress() ? $item->getPlayProgress() : null;
        $r[iState::COLUMN_WATCHED] = $item->isWatched();
        $r[iState::COLUMN_UPDATED] = makeDate($item->updated);
        $r[iState::COLUMN_EXTRA_EVENT] = ag($item->getExtra($item->via), iState::COLUMN_EXTRA_EVENT, null);
        $r['rguids'] = [];

        if ($item->isEpisode()) {
            foreach ($item->getRelativeGuids() as $rKey => $rGuid) {
                $r['rguids'][$rKey] = $rGuid;
            }
        }

        $reportedBy = [];
        $r['not_reported_by'] = [];

        if (!empty($r[iState::COLUMN_META_DATA])) {
            foreach ($r[iState::COLUMN_META_DATA] as $key => &$metadata) {
                $metadata['webUrl'] = (string)$this->getBackendItemWebUrl(
                    $key,
                    ag($metadata, iState::COLUMN_TYPE),
                    ag($metadata, iState::COLUMN_ID),
                );
                $reportedBy[] = $key;
            }
        }

        $backendsKeys = array_column($this->getBackends(), 'name');

        $r['not_reported_by'] = array_values(
            array_filter($backendsKeys, fn($key) => !in_array($key, $reportedBy))
        );
        $r[iState::COLUMN_TITLE] = $item->isEpisode() ? ag(
            $item->getMetadata($item->via),
            iState::COLUMN_EXTRA . '.' . iState::COLUMN_TITLE,
            null
        ) : null;
        $r[iState::COLUMN_META_PATH] = ag($item->getMetadata($item->via), iState::COLUMN_META_PATH);

        return api_response(HTTP_STATUS::HTTP_OK, $r);
    }

    #[Route(['GET', 'POST', 'DELETE'], self::URL . '/{id:\d+}/watch[/]', name: 'history.watch')]
    public function historyPlayStatus(iRequest $request, array $args = []): iResponse
    {
        if (null === ($id = ag($args, 'id'))) {
            return api_error('Invalid value for id path parameter.', HTTP_STATUS::HTTP_BAD_REQUEST);
        }

        $entity = Container::get(iState::class)::fromArray([iState::COLUMN_ID => $id]);

        if (null === ($item = $this->db->get($entity))) {
            return api_error('Not found', HTTP_STATUS::HTTP_NOT_FOUND);
        }

        if ('GET' === $request->getMethod()) {
            return api_response(HTTP_STATUS::HTTP_OK, ['watched' => $item->isWatched()]);
        }

        if ('POST' === $request->getMethod() && true === $item->isWatched()) {
            return api_error('Already watched', HTTP_STATUS::HTTP_CONFLICT);
        }

        if ('DELETE' === $request->getMethod() && false === $item->isWatched()) {
            return api_error('Already unwatched', HTTP_STATUS::HTTP_CONFLICT);
        }

        $item->watched = 'POST' === $request->getMethod() ? 1 : 0;
        $item->updated = time();
        $item->extra = ag_set($item->getExtra(), $item->via, [
            iState::COLUMN_EXTRA_EVENT => 'webui.mark' . ($item->isWatched() ? 'played' : 'unplayed'),
            iState::COLUMN_EXTRA_DATE => (string)makeDate('now'),
        ]);

        $this->mapper->add($item)->commit();

        $response = $item->getAll();
        $response['full_title'] = $item->getName();
        $response[iState::COLUMN_WATCHED] = $item->isWatched();
        $response[iState::COLUMN_UPDATED] = makeDate($item->updated);

        queuePush($item);

        return api_response(HTTP_STATUS::HTTP_OK, $response);
    }
}
