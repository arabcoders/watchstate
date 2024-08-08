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
use App\Libs\Traits\APITraits;
use Psr\Http\Message\ResponseInterface as iResponse;
use Psr\Http\Message\ServerRequestInterface as iRequest;
use Throwable;

final class Ignore
{
    use APITraits;

    private ConfigFile $file;

    public function __construct()
    {
        $this->file = new ConfigFile(Config::get('path') . '/config/ignore.yaml', type: 'yaml', autoCreate: true);
    }

    #[Get(Index::URL . '/{name:backend}/ignore[/]', name: 'backend.ignoredIds')]
    public function ignoredIds(iRequest $request, array $args = []): iResponse
    {
        if (null === ($name = ag($args, 'name'))) {
            return api_error('Invalid value for name path parameter.', Status::BAD_REQUEST);
        }

        if (null === $this->getBackend(name: $name)) {
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
    public function deleteRule(iRequest $request, array $args = []): iResponse
    {
        if (null === ($name = ag($args, 'name'))) {
            return api_error('Invalid value for name path parameter.', Status::BAD_REQUEST);
        }

        if (null === $this->getBackend(name: $name)) {
            return api_error(r("Backend '{name}' not found.", ['name' => $name]), Status::NOT_FOUND);
        }

        $data = $this->getBackends(name: $name);

        if (empty($data)) {
            return api_error(r("Backend '{name}' not found.", ['name' => $name]), Status::NOT_FOUND);
        }

        $params = DataUtil::fromRequest($request);

        if (null === ($rule = $params->get('rule'))) {
            return api_error('No rule was given.', Status::BAD_REQUEST);
        }

        try {
            checkIgnoreRule($rule);
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
    public function addRule(iRequest $request, array $args = []): iResponse
    {
        if (null === ($name = ag($args, 'name'))) {
            return api_error('Invalid value for name path parameter.', Status::BAD_REQUEST);
        }

        if (null === $this->getBackend(name: $name)) {
            return api_error(r("Backend '{name}' not found.", ['name' => $name]), Status::NOT_FOUND);
        }

        $data = $this->getBackends(name: $name);

        if (empty($data)) {
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
            checkIgnoreRule($rule);
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
