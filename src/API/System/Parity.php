<?php

declare(strict_types=1);

namespace App\API\System;

use App\Libs\Attributes\Route\Delete;
use App\Libs\Attributes\Route\Get;
use App\Libs\Database\DBLayer;
use App\Libs\DataUtil;
use App\Libs\Enums\Http\Status;
use App\Libs\Traits\APITraits;
use Psr\Http\Message\ResponseInterface as iResponse;
use Psr\Http\Message\ServerRequestInterface as iRequest;

final class Parity
{
    use APITraits;

    public const string URL = '%{api.prefix}/system/parity';

    public function __construct(private readonly DBLayer $db)
    {
    }

    /**
     */
    #[Get(self::URL . '[/]', name: 'system.parity')]
    public function __invoke(iRequest $request): iResponse
    {
        $params = DataUtil::fromArray($request->getQueryParams());

        $page = (int)$params->get('page', 1);
        $perpage = (int)$params->get('perpage', 1000);
        $start = (($page <= 2) ? ((1 === $page) ? 0 : $perpage) : $perpage * ($page - 1));
        $start = (!$page) ? 0 : $start;

        $response = [
            'paging' => [],
            'items' => [],
        ];

        $counter = (int)$params->get('min', 0);

        $backends = $this->getBackends();
        $backendsCount = count($backends);

        if ($counter > $backendsCount) {
            return api_error(r("Minimum value cannot be greater than the number of backends '({backends})'.", [
                'backends' => $backendsCount,
            ]), Status::BAD_REQUEST);
        }

        $counter = 0 === $counter ? $backendsCount : $counter;

        $sql = "SELECT COUNT(*) FROM state WHERE ( SELECT COUNT(*) FROM JSON_EACH(state.metadata) ) < {$counter}";
        $stmt = $this->db->query($sql);
        $total = (int)$stmt->fetchColumn();

        $lastPage = @ceil($total / $perpage);
        if ($total && $page > $lastPage) {
            return api_error(r("Invalid page number. '{page}' is higher than what the last page is '{last_page}'.", [
                'page' => $page,
                'last_page' => $lastPage,
            ]), Status::NOT_FOUND);
        }

        $sql = "SELECT
                    *,
                    ( SELECT COUNT(*) FROM JSON_EACH(state.metadata) ) as total_md
                FROM
                    state
                WHERE
                    total_md < {$counter}
                ORDER BY
                    updated DESC
                LIMIT
                    :_start, :_perpage
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            '_start' => $start,
            '_perpage' => $perpage,
        ]);

        foreach ($stmt as $row) {
            $response['items'][] = $this->formatEntity($row);
        }

        $response['paging'] = [
            'total' => $total,
            'perpage' => $perpage,
            'current_page' => $page,
            'first_page' => 1,
            'next_page' => $page < @ceil($total / $perpage) ? $page + 1 : null,
            'prev_page' => !empty($total) && $page > 1 ? $page - 1 : null,
            'last_page' => $lastPage,
            'params' => [
                'min' => $counter,
            ]
        ];

        return api_response(Status::OK, $response);
    }

    /**
     */
    #[Delete(self::URL . '[/]', name: 'system.parity.delete')]
    public function deleteRecords(iRequest $request): iResponse
    {
        $params = DataUtil::fromRequest($request, true);

        if (0 === ($counter = (int)$params->get('min', 0))) {
            return api_error('Invalid minimum value.', Status::BAD_REQUEST);
        }

        $count = count($this->getBackends());

        if ($counter > $count) {
            return api_error(r("Minimum value cannot be greater than the number of backends '({backends})'.", [
                'backends' => $count,
            ]), Status::BAD_REQUEST);
        }

        $sql = "DELETE FROM
                    state
                WHERE
                    ( SELECT COUNT(*) FROM JSON_EACH(state.metadata) ) < {$counter}
        ";
        $stmt = $this->db->query($sql);

        return api_response(Status::OK, [
            'deleted_records' => $stmt->rowCount(),
        ]);
    }
}
