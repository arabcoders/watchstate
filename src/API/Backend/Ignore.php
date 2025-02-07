<?php

declare(strict_types=1);

namespace App\API\Backend;

use App\Libs\Attributes\Route\Delete;
use App\Libs\Attributes\Route\Get;
use App\Libs\Attributes\Route\Post;
use App\Libs\Config;
use App\Libs\ConfigFile;
use App\Libs\DataUtil;
use App\Libs\Enums\Http\Status;
use App\Libs\Exceptions\RuntimeException;
use App\Libs\Mappers\ImportInterface as iImport;
use App\Libs\Traits\APITraits;
use Psr\Http\Message\ResponseInterface as iResponse;
use Psr\Http\Message\ServerRequestInterface as iRequest;
use Psr\Log\LoggerInterface as iLogger;
use Throwable;

final class Ignore
{
    use APITraits;

    private ConfigFile $file;

    public function __construct(private readonly iImport $mapper, private readonly iLogger $logger)
    {
        $this->file = new ConfigFile(Config::get('path') . '/config/ignore.yaml', type: 'yaml', autoCreate: true);
    }

    #[Get(Index::URL . '/{name:backend}/ignore[/]', name: 'backend.ignoredIds')]
    public function ignoredIds(iRequest $request, string $name): iResponse
    {
        try {
            $userContext = $this->getUserContext($request, $this->mapper, $this->logger);
        } catch (RuntimeException $e) {
            return api_error($e->getMessage(), Status::NOT_FOUND);
        }

        if (null === $this->getBackend(name: $name, userContext: $userContext)) {
            return api_error(r("Backend '{name}' not found.", ['name' => $name]), Status::NOT_FOUND);
        }

        $list = [];

        foreach ($this->file->getAll() as $guid => $date) {
            $urlParts = parse_url($guid);

            $backend = ag($urlParts, 'host');
            $type = ag($urlParts, 'scheme');
            $db = ag($urlParts, 'user');
            $id = ag($urlParts, 'pass');
            $scope = ag($urlParts, 'query');

            if ($name !== $backend) {
                continue;
            }

            $rule = makeIgnoreId($guid);

            $list[] = [
                'rule' => (string)$rule,
                'type' => ucfirst($type),
                'backend' => $backend,
                'db' => $db,
                'id' => $id,
                'scoped' => null === $scope ? 'No' : 'Yes',
                'created' => makeDate($date),
            ];
        }

        return api_response(Status::OK, $list);
    }

    #[Delete(Index::URL . '/{name:backend}/ignore[/]', name: 'backend.ignoredIds.delete')]
    public function deleteRule(iRequest $request, string $name): iResponse
    {
        try {
            $userContext = $this->getUserContext($request, $this->mapper, $this->logger);
        } catch (RuntimeException $e) {
            return api_error($e->getMessage(), Status::NOT_FOUND);
        }

        if (null === $this->getBackend(name: $name, userContext: $userContext)) {
            return api_error(r("Backend '{name}' not found.", ['name' => $name]), Status::NOT_FOUND);
        }

        $params = DataUtil::fromRequest($request);

        if (null === ($rule = $params->get('rule'))) {
            return api_error('No rule was given.', Status::BAD_REQUEST);
        }

        try {
            checkIgnoreRule($rule, userContext: $userContext);
        } catch (Throwable $e) {
            return api_error($e->getMessage(), Status::BAD_REQUEST);
        }

        if (!$this->file->has($rule)) {
            return api_error('Rule not found.', Status::NOT_FOUND);
        }

        $this->file->delete($rule)->persist();

        return api_response(Status::OK);
    }

    #[Post(Index::URL . '/{name:backend}/ignore[/]', name: 'backend.ignoredIds.add')]
    public function addRule(iRequest $request, string $name): iResponse
    {
        try {
            $userContext = $this->getUserContext($request, $this->mapper, $this->logger);
        } catch (RuntimeException $e) {
            return api_error($e->getMessage(), Status::NOT_FOUND);
        }

        if (null === $this->getBackend(name: $name, userContext: $userContext)) {
            return api_error(r("Backend '{name}' not found.", ['name' => $name]), Status::NOT_FOUND);
        }

        $params = DataUtil::fromRequest($request);

        if (null === ($rule = $params->get('rule'))) {
            $partial = [
                'type' => $params->get('type'),
                'backend' => $name,
                'db' => $params->get('db'),
                'id' => $params->get('id'),
            ];

            foreach ($partial as $k => $v) {
                if (empty($v)) {
                    return api_error(r('No {key} was given.', ['key' => $k]), Status::BAD_REQUEST);
                }
            }

            $partial['type'] = strtolower($partial['type']);

            $rule = r('{type}://{db}:{id}@{backend}', $partial);

            if (null !== ($scoped = $params->get('scoped'))) {
                $rule .= '?id=' . $scoped;
            }
        }

        try {
            checkIgnoreRule($rule, userContext: $userContext);
            $id = makeIgnoreId($rule);
        } catch (Throwable $e) {
            return api_error($e->getMessage(), Status::BAD_REQUEST);
        }

        if (true === $this->file->has((string)$id)) {
            return api_error('Rule already exists.', Status::CONFLICT);
        }

        if (true === $this->file->has((string)$id->withQuery(''))) {
            return api_error('Global rule already exists.', Status::CONFLICT);
        }

        $this->file->set((string)$id, time())->persist();
        return api_response(Status::CREATED);
    }
}
