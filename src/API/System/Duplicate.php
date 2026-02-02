<?php

declare(strict_types=1);

namespace App\API\System;

use App\Libs\Attributes\Route\Delete;
use App\Libs\Attributes\Route\Get;
use App\Libs\DataUtil;
use App\Libs\Enums\Http\Status;
use App\Libs\Exceptions\RuntimeException;
use App\Libs\Mappers\ImportInterface as iImport;
use App\Libs\Traits\APITraits;
use Psr\Http\Message\ResponseInterface as iResponse;
use Psr\Http\Message\ServerRequestInterface as iRequest;
use Psr\Log\LoggerInterface as iLogger;
use Psr\SimpleCache\InvalidArgumentException;

final class Duplicate
{
    use APITraits;

    public const string URL = '%{api.prefix}/system/duplicate';

    public function __construct(
        private readonly iImport $mapper,
        private readonly iLogger $logger,
    ) {}

    /**
     * @throws InvalidArgumentException
     */
    #[Get(self::URL . '[/]', name: 'system.duplicate')]
    public function __invoke(iRequest $request): iResponse
    {
        try {
            $userContext = $this->getUserContext($request, $this->mapper, $this->logger);
        } catch (RuntimeException $e) {
            return api_error($e->getMessage(), Status::NOT_FOUND);
        }

        $params = DataUtil::fromArray($request->getQueryParams());
        $page = max(1, (int) $params->get('page', 1));
        $perpage = max(1, (int) $params->get('perpage', 50));
        $start = ($page - 1) * $perpage;

        $db = $userContext->db->getDBLayer();

        $response = ['paging' => [], 'items' => []];

        $records = $userContext->cache->get('user_dfr', null);

        $shouldRebuildCache = false;
        if (true === is_array($records) && false === empty($records)) {
            $firstRecord = $records[array_key_first($records)];
            if (false === is_array($firstRecord) || false === array_key_exists('reference_count', $firstRecord)) {
                $shouldRebuildCache = true;
            }
        }

        if ($params->get('no_cache') || null === $records || true === $shouldRebuildCache) {
            $sql = <<<SQL
                    WITH file_paths AS (
                        SELECT s.id, s.updated,
                               json_extract(value, '$.path') AS file_path
                        FROM
                            state s, json_each(s.metadata)
                        WHERE
                            file_path IS NOT NULL AND file_path != '' AND COALESCE(json_extract(value, '$.multi'), 0) = 0
                    ),
                    dup_paths AS (
                        SELECT file_path, COUNT(DISTINCT id) AS reference_count
                        FROM file_paths
                        GROUP BY file_path
                        HAVING reference_count > 1
                    )
                    SELECT
                        fp.id,
                        fp.file_path,
                        fp.updated,
                        dp.reference_count
                    FROM
                        file_paths fp
                    JOIN
                        dup_paths dp ON dp.file_path = fp.file_path
                    ORDER BY
                        fp.file_path, fp.updated DESC
                SQL;

            $rows = $db->query($sql);
            $records = [];
            foreach ($rows as $row) {
                $records[] = [
                    'id' => (int) $row['id'],
                    'file_path' => $row['file_path'],
                    'updated' => (int) $row['updated'],
                    'reference_count' => (int) $row['reference_count'],
                ];
            }
            $userContext->cache->set('user_dfr', $records, new \DateInterval('PT30M'));
        }

        $grouped = [];
        foreach ($records as $r) {
            if (!isset($grouped[$r['file_path']])) {
                $grouped[$r['file_path']] = [];
            }

            $grouped[$r['file_path']][] = $r;
        }

        $filePaths = array_keys($grouped);
        $total = count($filePaths);
        $lastPage = $total ? (int) ceil($total / $perpage) : 1;
        if ($total && $page > $lastPage) {
            return api_error('Invalid page number.', Status::BAD_REQUEST);
        }

        $pagedPaths = array_slice($filePaths, $start, $perpage);

        $ids = [];
        $idDuplicateIds = [];
        foreach ($pagedPaths as $path) {
            $pathRecords = $grouped[$path];
            $pathRecordIds = array_values(array_map(static fn($r) => (int) $r['id'], $pathRecords));
            $uniquePathIds = array_values(array_unique($pathRecordIds, SORT_NUMERIC));

            foreach ($pathRecords as $r) {
                $recordId = (int) $r['id'];
                if (false === in_array($recordId, $ids, true)) {
                    $ids[] = $recordId;
                }

                $idDuplicateIds[$recordId] = array_values(array_filter(
                    $uniquePathIds,
                    static fn($candidateId) => $candidateId !== $recordId,
                ));
            }
        }

        if (empty($ids)) {
            $response['paging'] = [
                'total' => $total,
                'perpage' => $perpage,
                'current_page' => $page,
                'first_page' => 1,
                'next_page' => $page < $lastPage ? $page + 1 : null,
                'prev_page' => $page > 1 ? $page - 1 : null,
                'last_page' => $lastPage,
                'params' => [],
            ];
            return api_response(Status::OK, $response);
        }

        $rank = array_flip($ids);
        $rows = [];
        $stmt = $db->select('state', [], ['id' => [$db::IS_IN, $ids]]);
        foreach ($stmt as $row) {
            $rows[] = $row;
        }

        usort($rows, static fn($a, $b) => $rank[(int) $a['id']] <=> $rank[(int) $b['id']]);

        foreach ($rows as $row) {
            $item = $this->formatEntity($row, userContext: $userContext);
            $recordId = (int) $row['id'];
            if (isset($idDuplicateIds[$recordId]) && false === empty($idDuplicateIds[$recordId])) {
                $item['duplicate_reference_ids'] = $idDuplicateIds[$recordId];
            }
            $response['items'][] = $item;
        }

        $response['paging'] = [
            'total' => $total,
            'perpage' => $perpage,
            'current_page' => $page,
            'first_page' => 1,
            'next_page' => $page < $lastPage ? $page + 1 : null,
            'prev_page' => $page > 1 ? $page - 1 : null,
            'last_page' => $lastPage,
            'params' => [],
        ];

        return api_response(Status::OK, $response);
    }

    /**
     * @throws InvalidArgumentException
     */
    #[Delete(self::URL . '[/]', name: 'system.duplicate.delete')]
    public function deleteRecords(iRequest $request): iResponse
    {
        try {
            $userContext = $this->getUserContext($request, $this->mapper, $this->logger);
        } catch (RuntimeException $e) {
            return api_error($e->getMessage(), Status::NOT_FOUND);
        }

        $records = $userContext->cache->get('user_dfr', null);
        if (null === $records) {
            return api_error('Cache has expired.', Status::NOT_FOUND);
        }

        $ids = array_column($records, 'id');
        if (empty($ids)) {
            return api_error('No duplicate records found.', Status::NOT_FOUND);
        }

        $stmt = $userContext->db->getDBLayer()->delete('state', [
            'id' => [$userContext->db->getDBLayer()::IS_IN, $ids],
        ]);

        $userContext->cache->delete('user_dfr');

        return api_response(Status::OK, [
            'deleted_records' => $stmt->rowCount(),
        ]);
    }
}
