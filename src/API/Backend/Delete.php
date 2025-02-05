<?php

declare(strict_types=1);

namespace App\API\Backend;

use App\Libs\Enums\Http\Status;
use App\Libs\Exceptions\RuntimeException;
use App\Libs\Mappers\ExtendedImportInterface as iEImport;
use App\Libs\Traits\APITraits;
use Psr\Http\Message\ResponseInterface as iResponse;
use Psr\Http\Message\ServerRequestInterface as iRequest;
use Psr\Log\LoggerInterface as iLogger;

final class Delete
{
    use APITraits;

    #[\App\Libs\Attributes\Route\Delete(Index::URL . '/{name:backend}[/]', name: 'backend.delete')]
    public function __invoke(iRequest $request, string $name, iEImport $mapper, iLogger $logger): iResponse
    {
        try {
            $userContext = $this->getUserContext($request, $mapper, $logger);
        } catch (RuntimeException $e) {
            return api_error($e->getMessage(), Status::NOT_FOUND);
        }

        if (null === ($data = $this->getBackend(name: $name, userContext: $userContext))) {
            return api_error(r("Backend '{name}' not found.", ['name' => $name]), Status::NOT_FOUND);
        }

        $db = $userContext->db->getDBLayer();

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

        $stmt = $db->prepare($sql);
        $stmt->execute(['name_metadata' => $name, 'name_extra' => $name]);

        $removedReference = $stmt->rowCount();

        $sql = "DELETE FROM state WHERE id IN ( SELECT id FROM state WHERE length(metadata) < 10 )";
        $stmt = $db->query($sql);

        $deletedRecords = $stmt->rowCount();

        $userContext->config->delete($name)->persist();

        return api_response(Status::OK, [
            'deleted' => [
                'references' => $removedReference,
                'records' => $deletedRecords,
            ],
            'backend' => $data,
        ]);
    }
}
