<?php

declare(strict_types=1);

namespace App\API\Backend;

use App\API\Backend\Index as backendIndex;
use App\Libs\Attributes\Route\Get;
use App\Libs\DataUtil;
use App\Libs\Entity\StateInterface as iState;
use App\Libs\Enums\Http\Status;
use App\Libs\Exceptions\RuntimeException;
use App\Libs\Mappers\Import\MemoryMapper;
use App\Libs\Mappers\Import\ReadOnlyMapper;
use App\Libs\Traits\APITraits;
use Psr\Http\Message\ResponseInterface as iResponse;
use Psr\Http\Message\ServerRequestInterface as iRequest;

final class Stale
{
    use APITraits;

    public function __construct(private readonly ReadOnlyMapper $mapper, private readonly MemoryMapper $local)
    {
        set_time_limit(0);
        ini_set('memory_limit', '-1');
    }

    #[Get(backendIndex::URL . '/{name:backend}/stale/{id}[/]', name: 'backend.stale')]
    public function __invoke(iRequest $request, string $name, string|int $id): iResponse
    {
        if (empty($name)) {
            return api_error('Invalid value for name path parameter.', Status::BAD_REQUEST);
        }

        if (empty($id)) {
            return api_error('Invalid value for id path parameter.', Status::BAD_REQUEST);
        }

        if (null === $this->getBackend(name: $name)) {
            return api_error(r("Backend '{name}' not found.", ['name' => $name]), Status::NOT_FOUND);
        }

        $params = DataUtil::fromArray($request->getQueryParams());

        $backendOpts = $list = [];

        if ($params->get('timeout')) {
            $backendOpts = ag_set($backendOpts, 'client.timeout', (float)$params->get('timeout'));
        }

        try {
            $client = $this->getClient(name: $name, config: $backendOpts);
        } catch (RuntimeException $e) {
            return api_error($e->getMessage(), Status::NOT_FOUND);
        }

        $remote = cacheableItem(
            'remote-data-' . $name,
            fn() => array_map(fn($item) => ag($item->getMetadata($item->via), iState::COLUMN_ID),
                $client->getLibraryContent($id))
            , ignoreCache: (bool)$params->get('ignore', false)
        );

        $this->local->loadData();

        $localCount = 0;

        foreach ($this->local->getObjects() as $entity) {
            $backendData = $entity->getMetadata($name);
            if (empty($backendData)) {
                continue;
            }

            if (null === ($libraryId = ag($backendData, iState::COLUMN_META_LIBRARY))) {
                continue;
            }

            if ((string)$libraryId !== (string)$id) {
                continue;
            }

            $localCount++;

            $localId = ag($backendData, iState::COLUMN_ID);

            if (true === in_array($localId, $remote, true)) {
                continue;
            }

            $list[] = $entity;
        }

        $libraryInfo = [];
        foreach ($client->listLibraries() as $library) {
            if (null === ($libraryId = ag($library, 'id'))) {
                continue;
            }
            if ((string)$id !== (string)$libraryId) {
                continue;
            }
            $libraryInfo = $library;
            break;
        }

        return api_response(Status::OK, [
            'backend' => [
                'library' => $libraryInfo,
                'name' => $client->getContext()->backendName,
            ],
            'counts' => [
                'remote' => count($remote),
                'local' => $localCount,
                'stale' => count($list),
            ],
            'items' => array_map(fn($item) => self::formatEntity($item), $list),
        ]);
    }
}
