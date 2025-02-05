<?php

declare(strict_types=1);

namespace App\API\Backend;

use App\Libs\Attributes\Route\Get;
use App\Libs\DataUtil;
use App\Libs\Enums\Http\Status;
use App\Libs\Exceptions\RuntimeException;
use App\Libs\Mappers\ExtendedImportInterface as iEImport;
use App\Libs\Options;
use App\Libs\Traits\APITraits;
use Psr\Http\Message\ResponseInterface as iResponse;
use Psr\Http\Message\ServerRequestInterface as iRequest;
use Psr\Log\LoggerInterface as iLogger;
use Throwable;

final class Search
{
    use APITraits;

    public function __construct(private readonly iEImport $mapper, private readonly iLogger $logger)
    {
    }

    #[Get(Index::URL . '/{name:backend}/search[/[{id}[/]]]', name: 'backend.search')]
    public function __invoke(iRequest $request, string $name, string|int|null $id = null): iResponse
    {
        $userContext = $this->getUserContext($request, $this->mapper, $this->logger);

        if (null === $this->getBackend(name: $name, userContext: $userContext)) {
            return api_error(r("Backend '{name}' not found.", ['name' => $name]), Status::NOT_FOUND);
        }

        $params = DataUtil::fromRequest($request, true);

        $id = $id ?? $params->get('id') ?? null;
        $query = $params->get('q', null);

        if (null === $id && null === $query) {
            return api_error('No search id or query string was provided.', Status::BAD_REQUEST);
        }

        try {
            $backend = $this->getClient(name: $name, userContext: $userContext);
        } catch (RuntimeException $e) {
            return api_error($e->getMessage(), Status::NOT_FOUND);
        }

        $raw = $params->get('raw', false) || $params->get(Options::RAW_RESPONSE, false);

        $response = [];

        try {
            if (null !== $id) {
                $data = $backend->searchId($id, [Options::RAW_RESPONSE => $raw]);
                if (!empty($data)) {
                    $item = $this->formatEntity($data, userContext: $userContext);
                    if (true === $raw) {
                        $item[Options::RAW_RESPONSE] = ag($data, Options::RAW_RESPONSE, []);
                    }
                    $response[] = $item;
                }
            } else {
                $data = $backend->search(
                    query: $query,
                    limit: (int)$params->get('limit', 25),
                    opts: [Options::RAW_RESPONSE => $raw]
                );
                foreach ($data as $entity) {
                    $item = $this->formatEntity($entity, userContext: $userContext);
                    if (true === $raw) {
                        $item[Options::RAW_RESPONSE] = ag($entity, Options::RAW_RESPONSE, []);
                    }
                    $response[] = $item;
                }
            }
        } catch (Throwable $e) {
            return api_error($e->getMessage(), Status::INTERNAL_SERVER_ERROR);
        }

        if (count($data) < 1) {
            return api_error(r("No results were found for '{query}'.", [
                'query' => $id ?? $query
            ]), Status::NOT_FOUND);
        }

        return api_response(Status::OK, $response);
    }

}
