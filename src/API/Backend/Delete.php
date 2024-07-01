<?php

declare(strict_types=1);

namespace App\API\Backend;

use App\Libs\Config;
use App\Libs\ConfigFile;
use App\Libs\Database\DatabaseInterface as iDB;
use App\Libs\HTTP_STATUS;
use App\Libs\Traits\APITraits;
use Psr\Http\Message\ResponseInterface as iResponse;
use Psr\Http\Message\ServerRequestInterface as iRequest;

final class Delete
{
    use APITraits;

    public function __construct(private iDB $db)
    {
    }

    #[\App\Libs\Attributes\Route\Delete(Index::URL . '/{name:backend}[/]', name: 'backend.delete')]
    public function __invoke(iRequest $request, array $args = []): iResponse
    {
        if (null === ($name = ag($args, 'name'))) {
            return api_error('Invalid value for name path parameter.', HTTP_STATUS::HTTP_BAD_REQUEST);
        }

        if (null === ($data = $this->getBackend(name: $name))) {
            return api_error(r("Backend '{name}' not found.", ['name' => $name]), HTTP_STATUS::HTTP_NOT_FOUND);
        }

        set_time_limit(0);
        ignore_user_abort(true);

        $sql = "UPDATE
                    state
                SET
                    metadata = json_remove(metadata, '$.{$name}'),
                    extra = json_remove(extra, '$.{$name}')
                WHERE
                (
                    json_extract(metadata,'$.{$name}.via') = :name_metadata
                OR
                    json_extract(extra,'$.{$name}.via') = :name_extra
                )
        ";

        $stmt = $this->db->getPDO()->prepare($sql);
        $stmt->execute(['name_metadata' => $name, 'name_extra' => $name]);

        $removedReference = $stmt->rowCount();

        $sql = "DELETE FROM state WHERE id IN ( SELECT id FROM state WHERE length(metadata) < 10 )";
        $stmt = $this->db->getPDO()->query($sql);

        $deletedRecords = $stmt->rowCount();

        ConfigFile::open(Config::get('backends_file'), 'yaml')->delete($name)->persist();

        return api_response(HTTP_STATUS::HTTP_OK, [
            'deleted' => [
                'references' => $removedReference,
                'records' => $deletedRecords,
            ],
            'backend' => $data,
        ]);
    }
}
