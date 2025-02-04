<?php

declare(strict_types=1);

namespace App\API\Backend;

use App\API\Backend\Index as backendIndex;
use App\Libs\Attributes\Route\Get;
use App\Libs\DataUtil;
use App\Libs\Entity\StateInterface as iState;
use App\Libs\Enums\Http\Status;
use App\Libs\Exceptions\RuntimeException;
use App\Libs\Options;
use App\Libs\Traits\APITraits;
use Psr\Http\Message\ResponseInterface as iResponse;
use Psr\Http\Message\ServerRequestInterface as iRequest;
use Throwable;

final class Unmatched
{
    use APITraits;

    #[Get(backendIndex::URL . '/{name:backend}/unmatched[/[{id}[/]]]', name: 'backend.unmatched')]
    public function __invoke(iRequest $request, array $args = []): iResponse
    {
        if (null === ($name = ag($args, 'name'))) {
            return api_error('Invalid value for name path parameter.', Status::BAD_REQUEST);
        }

        if (null === $this->getBackend(name: $name)) {
            return api_error(r("Backend '{name}' not found.", ['name' => $name]), Status::NOT_FOUND);
        }

        $params = DataUtil::fromArray($request->getQueryParams());

        $backendOpts = $opts = $list = [];

        if ($params->get('timeout')) {
            $backendOpts = ag_set($backendOpts, 'client.timeout', (float)$params->get('timeout'));
        }

        $includeRaw = $params->get('raw') || $params->get(Options::RAW_RESPONSE);

        $opts[Options::RAW_RESPONSE] = true;

        try {
            $client = $this->getClient(name: $name, config: $backendOpts);
        } catch (RuntimeException $e) {
            return api_error($e->getMessage(), Status::NOT_FOUND);
        }

        $ids = [];
        if (null !== ($id = ag($args, 'id'))) {
            $ids[] = $id;
        } else {
            foreach ($client->listLibraries() as $library) {
                if (false === (bool)ag($library, 'supported') || true === (bool)ag($library, 'ignored')) {
                    continue;
                }
                $ids[] = ag($library, 'id');
            }
        }

        $opts[Options::RAW_RESPONSE] = true;

        foreach ($ids as $libraryId) {
            $opts[iState::COLUMN_META_LIBRARY] = $libraryId;
            foreach ($client->getLibrary(id: $libraryId, opts: $opts) as $item) {
                $entity = $client->toEntity($item[Options::RAW_RESPONSE], $opts);

                if ($entity->hasGuids() || $entity->hasParentGuid()) {
                    continue;
                }

                $builder = $entity->getAll();

                try {
                    $builder['webUrl'] = (string)$client->getWebUrl(
                        $entity->type,
                        ag($entity->getMetadata($entity->via), iState::COLUMN_ID)
                    );
                } catch (Throwable) {
                    $builder['webUrl'] = null;
                }

                $builder[iState::COLUMN_META_LIBRARY] = ag($item, iState::COLUMN_META_LIBRARY);

                if ($includeRaw) {
                    $builder[Options::RAW_RESPONSE] = ag($item, Options::RAW_RESPONSE, []);
                }

                if (null !== ($path = ag($entity->getMetadata($entity->via), 'path'))) {
                    $builder[iState::COLUMN_META_PATH] = $path;
                }

                $list[] = $builder;
            }
        }

        return api_response(Status::OK, $list);
    }
}
