<?php

declare(strict_types=1);

namespace App\API\System;

use App\Libs\Attributes\Route\Get;
use App\Libs\DataUtil;
use App\Libs\Enums\Http\Status;
use App\Libs\Exceptions\RuntimeException;
use App\Libs\Mappers\ImportInterface as iImport;
use App\Libs\Traits\APITraits;
use Psr\Http\Message\ResponseInterface as iResponse;
use Psr\Http\Message\ServerRequestInterface as iRequest;
use Psr\Log\LoggerInterface as iLogger;

final class Duplicated
{
    use APITraits;

    public const string URL = '%{api.prefix}/system/duplicated';

    public function __construct(private readonly iImport $mapper, private readonly iLogger $logger)
    {
    }

    /**
     */
    #[Get(self::URL . '[/]', name: 'system.parity')]
    public function __invoke(iRequest $request): iResponse
    {
        try {
            $userContext = $this->getUserContext($request, $this->mapper, $this->logger);
        } catch (RuntimeException $e) {
            return api_error($e->getMessage(), Status::NOT_FOUND);
        }

        $params = DataUtil::fromArray($request->getQueryParams());

        $page = (int)$params->get('page', 1);
        $perpage = (int)$params->get('perpage', 50);
        $start = (($page <= 2) ? ((1 === $page) ? 0 : $perpage) : $perpage * ($page - 1));
        $start = (!$page) ? 0 : $start;

        $response = [
            'paging' => [],
            'items' => [],
        ];

        $sql = "WITH file_paths AS (
                    SELECT s.id, json_extract(value, '$.path') AS file_path
                    FROM state s, json_each(s.metadata)
                    WHERE file_path IS NOT NULL AND file_path != ''
                )
                SELECT COUNT(*) FROM (
                    SELECT file_path
                    FROM file_paths
                    GROUP BY file_path
                    HAVING COUNT(DISTINCT id) > 1
                )";
        $stmt = $userContext->db->getDBLayer()->query($sql);
        $total = (int)$stmt->fetchColumn();

        $lastPage = @ceil($total / $perpage);
        if ($total && $page > $lastPage) {
            return api_error(r("Invalid page number. '{page}' is higher than what the last page is '{last_page}'.", [
                'page' => $page,
                'last_page' => $lastPage,
            ]), Status::NOT_FOUND);
        }

        $sql = "WITH file_paths AS (
                    SELECT s.id, json_extract(value, '$.path') AS file_path
                    FROM state s, json_each(s.metadata)
                    WHERE file_path IS NOT NULL AND file_path != ''
                ),
                dup_ids AS (
                    SELECT DISTINCT fp.id, fp.file_path
                    FROM file_paths fp
                    JOIN (
                        SELECT file_path
                        FROM file_paths
                        GROUP BY file_path
                        HAVING COUNT(DISTINCT id) > 1
                        LIMIT :_start, :_perpage
                    ) dup ON fp.file_path = dup.file_path
                )
                SELECT s.*
                FROM state s
                JOIN dup_ids di ON s.id = di.id
                ORDER BY di.file_path ASC, s.updated DESC";
        $stmt = $userContext->db->getDBLayer()->prepare($sql);
        $stmt->execute([
            '_start' => $start,
            '_perpage' => $perpage,
        ]);

        foreach ($stmt as $row) {
            $response['items'][] = $this->formatEntity($row, userContext: $userContext);
        }

        $response['paging'] = [
            'total' => $total,
            'perpage' => $perpage,
            'current_page' => $page,
            'first_page' => 1,
            'next_page' => $page < @ceil($total / $perpage) ? $page + 1 : null,
            'prev_page' => !empty($total) && $page > 1 ? $page - 1 : null,
            'last_page' => $lastPage,
            'params' => []
        ];

        return api_response(Status::OK, $response);
    }

}
