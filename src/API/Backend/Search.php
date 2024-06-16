<?php

declare(strict_types=1);

namespace App\API\Backend;

use App\Libs\Attributes\Route\Get;
use App\Libs\DataUtil;
use App\Libs\Exceptions\RuntimeException;
use App\Libs\HTTP_STATUS;
use App\Libs\Options;
use App\Libs\Traits\APITraits;
use Psr\Http\Message\ResponseInterface as iResponse;
use Psr\Http\Message\ServerRequestInterface as iRequest;
use Throwable;

final class Search
{
    use APITraits;

    #[Get(Index::URL . '/{name:backend}/search[/[{id}[/]]]', name: 'backend.search')]
    public function __invoke(iRequest $request, array $args = []): iResponse
    {
        if (null === ($name = ag($args, 'name'))) {
            return api_error('Invalid value for name path parameter.', HTTP_STATUS::HTTP_BAD_REQUEST);
        }

        $params = DataUtil::fromRequest($request, true);

        $id = ag($args, 'id', $params->get('id', null));
        $query = $params->get('q', null);

        if (null === $id && null === $query) {
            return api_error('No search id or query string was provided.', HTTP_STATUS::HTTP_BAD_REQUEST);
        }

        try {
            $backend = $this->getClient(name: $name);
        } catch (RuntimeException $e) {
            return api_error($e->getMessage(), HTTP_STATUS::HTTP_NOT_FOUND);
        }

        $raw = $params->get('raw', false) || $params->get(Options::RAW_RESPONSE, false);

        $response = [];

        try {
            if (null !== $id) {
                $data = $backend->searchId($id, [Options::RAW_RESPONSE => $raw]);
                if (!empty($data)) {
                    $item = $this->formatEntity($data);
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
                    $item = $this->formatEntity($entity);
                    if (true === $raw) {
                        $item[Options::RAW_RESPONSE] = ag($entity, Options::RAW_RESPONSE, []);
                    }
                    $response[] = $item;
                }
            }
        } catch (Throwable $e) {
            return api_error($e->getMessage(), HTTP_STATUS::HTTP_INTERNAL_SERVER_ERROR);
        }

        if (count($data) < 1) {
            return api_error(r("No results were found for '{query}'.", [
                'query' => $id ?? $query
            ]), HTTP_STATUS::HTTP_NOT_FOUND);
        }

        return api_response(HTTP_STATUS::HTTP_OK, $response);
    }

}
