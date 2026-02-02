<?php

declare(strict_types=1);

namespace App\API\Backend;

use App\API\Backend\Index as BackendsIndex;
use App\Libs\Attributes\Route\Get;
use App\Libs\Attributes\Route\Route;
use App\Libs\Enums\Http\Status;
use App\Libs\Exceptions\RuntimeException;
use App\Libs\Mappers\ImportInterface as iImport;
use App\Libs\Options;
use App\Libs\Traits\APITraits;
use Psr\Http\Message\ResponseInterface as iResponse;
use Psr\Http\Message\ServerRequestInterface as iRequest;
use Psr\Log\LoggerInterface as iLogger;
use Throwable;

final class Library
{
    use APITraits;

    public function __construct(
        private readonly iImport $mapper,
        private readonly iLogger $logger,
    ) {}

    #[Get(BackendsIndex::URL . '/{name:backend}/library[/]', name: 'backend.library')]
    public function listLibraries(iRequest $request, string $name): iResponse
    {
        try {
            $userContext = $this->getUserContext($request, $this->mapper, $this->logger);
        } catch (RuntimeException $e) {
            return api_error($e->getMessage(), Status::NOT_FOUND);
        }

        if (null === $this->getBackend(name: $name, userContext: $userContext)) {
            return api_error(r("Backend '{name}' not found.", ['name' => $name]), Status::NOT_FOUND);
        }

        try {
            $client = $this->getClient(name: $name, userContext: $userContext);
            return api_response(Status::OK, $client->listLibraries());
        } catch (RuntimeException $e) {
            return api_error($e->getMessage(), Status::NOT_FOUND);
        } catch (Throwable $e) {
            return api_error($e->getMessage(), Status::INTERNAL_SERVER_ERROR);
        }
    }

    #[Route(['POST', 'DELETE'], BackendsIndex::URL . '/{name:backend}/library/{id}[/]', name: 'backend.library.ignore')]
    public function ignoreLibrary(iRequest $request, string $name, string|int $id): iResponse
    {
        try {
            $userContext = $this->getUserContext($request, $this->mapper, $this->logger);
        } catch (RuntimeException $e) {
            return api_error($e->getMessage(), Status::NOT_FOUND);
        }

        if (null === $this->getBackend(name: $name, userContext: $userContext)) {
            return api_error(r("Backend '{name}' not found.", ['name' => $name]), Status::NOT_FOUND);
        }

        $remove = 'DELETE' === $request->getMethod();

        if (null === $userContext->config->get($name)) {
            return api_error(r("Backend '{backend}' not found.", ['backend' => $name]), Status::NOT_FOUND);
        }

        $ignoreIds = array_map(
            trim(...),
            explode(',', (string) $userContext->config->get("{$name}.options." . Options::IGNORE, '')),
        );

        $mode = !(true === $remove);
        if ($mode === in_array($id, $ignoreIds, true)) {
            return api_error(r("Library id '{id}' is {message} ignored.", [
                'id' => $id,
                'message' => $remove ? 'not' : 'already',
            ]), Status::CONFLICT);
        }

        $found = false;

        $libraries = $this->getClient(name: $name, userContext: $userContext)->listLibraries();

        foreach ($libraries as &$library) {
            if ((string) ag($library, 'id') !== (string) $id) {
                continue;
            }

            $ignoreIds[] = $id;
            $library['ignored'] = !$remove;
            $found = true;
            break;
        }

        if (false === $found) {
            return api_error(r("The library id '{id}' is incorrect.", ['id' => $name]), Status::NOT_FOUND, [
                'possible_ids' => array_column($libraries, 'id'),
            ]);
        }

        if (true === $remove) {
            $ignoreIds = array_diff($ignoreIds, [$id]);
        }

        $userContext
            ->config
            ->set("{$name}.options." . Options::IGNORE, implode(',', array_values($ignoreIds)))
            ->persist();

        return api_response(Status::OK, $libraries);
    }
}
