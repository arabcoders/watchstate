<?php

declare(strict_types=1);

namespace App\API\History;

use App\Commands\Database\ListCommand;
use App\Libs\Attributes\Route\Get;
use App\Libs\Container;
use App\Libs\Database\DatabaseInterface as iDB;
use App\Libs\DataUtil;
use App\Libs\Entity\StateInterface as iState;
use App\Libs\Guid;
use App\Libs\HTTP_STATUS;
use App\Libs\Uri;
use PDO;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

#[Get(self::URL . '[/]', name: 'history.index')]
final class Index
{
    public const URL = '%{api.prefix}/history';
    private PDO $pdo;

    public function __construct(private readonly iDB $db)
    {
        $this->pdo = $this->db->getPDO();
    }

    public function __invoke(ServerRequestInterface $request, array $args = []): ResponseInterface
    {
        $es = fn(string $val) => $this->db->identifier($val);
        $data = DataUtil::fromArray($request->getQueryParams());
        $filters = [];

        $page = (int)$data->get('page', 1);
        $perpage = (int)$data->get('perpage', 12);

        $start = (($page <= 2) ? ((1 === $page) ? 0 : $perpage) : $perpage * ($page - 1));
        $start = (!$page) ? 0 : $start;

        $params = [];

        $sql = $where = [];

        $sql[] = sprintf('FROM %s', $es('state'));

        if ($data->get('id')) {
            $where[] = $es(iState::COLUMN_ID) . ' = :id';
            $params['id'] = $data->get('id');
            $filters['id'] = $data->get('id');
        }

        if ($data->get('via')) {
            $where[] = $es(iState::COLUMN_VIA) . ' = :via';
            $params['via'] = $data->get('via');
            $filters['via'] = $data->get('via');
        }

        if ($data->get('year')) {
            $where[] = $es(iState::COLUMN_YEAR) . ' = :year';
            $params['year'] = $data->get('year');
            $filters['year'] = $data->get('year');
        }

        if ($data->get('type')) {
            $where[] = $es(iState::COLUMN_TYPE) . ' = :type';
            $params['type'] = match ($data->get('type')) {
                iState::TYPE_MOVIE => iState::TYPE_MOVIE,
                default => iState::TYPE_EPISODE,
            };
            $filters['type'] = $data->get('type');
        }

        if ($data->get('title')) {
            $where[] = $es(iState::COLUMN_TITLE) . ' LIKE "%" || :title || "%"';
            $params['title'] = $data->get('title');
            $filters['title'] = $data->get('title');
        }

        if (null !== $data->get('season')) {
            $where[] = $es(iState::COLUMN_SEASON) . ' = :season';
            $params['season'] = $data->get('season');
            $filters['season'] = $data->get('season');
        }

        if (null !== $data->get('episode')) {
            $where[] = $es(iState::COLUMN_EPISODE) . ' = :episode';
            $params['episode'] = $data->get('episode');
            $filters['episode'] = $data->get('episode');
        }

        if (null !== ($parent = $data->get('parent'))) {
            $parent = after($parent, 'guid_');
            $d = Guid::fromArray(['guid_' . before($parent, '://') => after($parent, '://')]);
            $parent = array_keys($d->getAll())[0] ?? null;

            if (null === $parent) {
                return api_error(
                    'Invalid value for parent query string expected value format is db://id.',
                    HTTP_STATUS::HTTP_BAD_REQUEST
                );
            }

            $where[] = "json_extract(" . iState::COLUMN_PARENT . ",'$.{$parent}') = :parent";
            $params['parent'] = array_values($d->getAll())[0];
            $filters['parent'] = $data->get('parent');
        }

        if (null !== ($guid = $data->get('guid'))) {
            $guid = after($guid, 'guid_');
            $d = Guid::fromArray(['guid_' . before($guid, '://') => after($guid, '://')]);
            $guid = array_keys($d->getAll())[0] ?? null;

            if (null === $guid) {
                return api_error(
                    'Invalid value for guid query string expected value format is db://id.',
                    HTTP_STATUS::HTTP_BAD_REQUEST
                );
            }

            $where[] = "json_extract(" . iState::COLUMN_GUIDS . ",'$.{$guid}') = :guid";
            $params['guid'] = array_values($d->getAll())[0];
            $filters['guid'] = $data->get('guid');
        }

        if ($data->get('metadata')) {
            $sField = $data->get('key');
            $sValue = $data->get('value');
            if (null === $sField || null === $sValue) {
                return api_error(
                    'When searching using JSON fields the query string \'key\' and \'value\' must be set.',
                    HTTP_STATUS::HTTP_BAD_REQUEST
                );
            }

            if (preg_match('/[^a-zA-Z0-9_]/', $sField)) {
                return api_error(
                    'Invalid value for key query string expected value format is [a-zA-Z0-9_].',
                    HTTP_STATUS::HTTP_BAD_REQUEST
                );
            }

            if ($data->get('exact')) {
                $where[] = "json_extract(" . iState::COLUMN_META_DATA . ",'$.{$sField}') = :jf_metadata_value ";
            } else {
                $where[] = "json_extract(" . iState::COLUMN_META_DATA . ",'$.{$sField}') LIKE \"%\" || :jf_metadata_value || \"%\"";
            }

            $params['jf_metadata_value'] = $sValue;
            $filters['metadata'] = [
                'key' => $sField,
                'value' => $sValue,
                'exact' => $data->get('exact'),
            ];
        }

        if ($data->get('extra')) {
            $sField = $data->get('key');
            $sValue = $data->get('value');
            if (null === $sField || null === $sValue) {
                return api_error(
                    'When searching using JSON fields the query string \'key\' and \'value\' must be set.',
                    HTTP_STATUS::HTTP_BAD_REQUEST
                );
            }

            if (preg_match('/[^a-zA-Z0-9_]/', $sField)) {
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

        foreach ($data->get('sort') as $sort) {
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

        $currentQuery = [];
        $apikey = $data->get('apikey');
        $getUri = $request->getUri()->withHost('')->withPort(0)->withScheme('');
        if (null !== $apikey) {
            $currentQuery['apikey'] = $apikey;
        }

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
            '@self' => (string)$getUri,
            'paging' => [
                'total' => (int)$total,
                'perpage' => $perpage,
                'current_page' => $page,
                'first_page' => 1,
                'next_page' => $page < @ceil($total / $perpage) ? $page + 1 : null,
                'prev_page' => !empty($total) && $page > 1 ? $page - 1 : null,
                'last_page' => @ceil($total / $perpage),
                'urls' => [
                    'first_url' => $firstUrl,
                    'next_url' => $nextUrl,
                    'prev_url' => $prevUrl,
                    'last_url' => $lastUrl,
                ],
            ],
            'filters' => $filters,
            'data' => [],
        ];

        if (0 === $stmt->rowCount()) {
            $response['error'] = [
                'message' => 'No Results.',
            ];

            if (true === count($response['filters']) >= 1) {
                $response['error']['message'] .= ' Probably invalid filters values were used.';
            }
        }

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

            $response['data'][] = [
                    '@self' => (string)(new Uri())->withPath(
                        rtrim($request->getUri()->getPath(), '/') . '/' . $entity->id
                    )->withQuery(http_build_query($currentQuery)),
                ] + $item;
        }

        return api_response($response, HTTP_STATUS::HTTP_OK, []);
    }
}
