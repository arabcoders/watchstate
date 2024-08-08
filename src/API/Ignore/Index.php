<?php

declare(strict_types=1);

namespace App\API\Ignore;

use App\Libs\Attributes\Route\Delete;
use App\Libs\Attributes\Route\Get;
use App\Libs\Attributes\Route\Post;
use App\Libs\Config;
use App\Libs\ConfigFile;
use App\Libs\Container;
use App\Libs\Database\DatabaseInterface as iDB;
use App\Libs\DataUtil;
use App\Libs\Entity\StateInterface as iState;
use App\Libs\Enums\Http\Status;
use App\Libs\Exceptions\RuntimeException;
use PDO;
use Psr\Http\Message\ResponseInterface as iResponse;
use Psr\Http\Message\ServerRequestInterface as iRequest;
use Psr\Http\Message\UriInterface as iUri;

final class Index
{
    public const string URL = '%{api.prefix}/ignore';

    private array $cache = [];

    private PDO $db;

    private ConfigFile $config;

    public function __construct(iDB $db)
    {
        $this->db = $db->getPDO();

        $this->config = ConfigFile::open(
            file: Config::get('path') . '/config/ignore.yaml',
            type: 'yaml',
            autoCreate: true,
            autoBackup: false
        );
    }

    #[Get(self::URL . '[/]', name: 'ignore')]
    public function __invoke(iRequest $request): iResponse
    {
        $params = DataUtil::fromArray($request->getQueryParams());

        $type = $params->get('type');
        $db = $params->get('db');
        $id = $params->get('id');
        $backend = $params->get('backend');

        $response = [];

        foreach ($this->config->getAll() as $guid => $date) {
            $item = $this->ruleAsArray($guid, $date);

            if (null !== $type && strtolower($type) !== strtolower(ag($item, 'type', ''))) {
                continue;
            }

            if (null !== $db && strtolower($db) !== strtolower(ag($item, 'db', ''))) {
                continue;
            }

            if (null !== $id && $id !== ag($item, 'id', '')) {
                continue;
            }

            if (null !== $backend && strtolower($backend) !== strtolower(ag($item, 'backend', ''))) {
                continue;
            }

            $response[] = $item;
        }

        return api_response(Status::OK, $response);
    }

    #[Post(self::URL . '[/]', name: 'ignore.add')]
    public function addNewRule(iRequest $request): iResponse
    {
        $params = DataUtil::fromRequest($request);

        if (null === ($id = $params->get('rule'))) {
            foreach (['id', 'db', 'backend', 'type'] as $key) {
                if (null === $params->get($key)) {
                    return api_error(r('Missing required parameter: {key}', ['key' => $key]),
                        Status::BAD_REQUEST);
                }
            }

            $id = r('{type}://{db}:{id}@{backend}?id={scoped_to}', [
                'type' => $params->get('type'),
                'db' => $params->get('db'),
                'id' => $params->get('id'),
                'backend' => $params->get('backend'),
                'scoped_to' => $params->get('scoped') ? $params->get('scoped_to') : '',
            ]);
        }

        try {
            checkIgnoreRule($id);
        } catch (RuntimeException $e) {
            return api_error($e->getMessage(), Status::BAD_REQUEST);
        }

        $filtered = (string)makeIgnoreId($id);
        $date = time();

        if ($this->config->has($id) || $this->config->has($filtered)) {
            return api_error(r('Rule already exists: {id}', ['id' => $id]), Status::CONFLICT);
        }

        $this->config->set($filtered, $date)->persist();

        return api_response(Status::OK, $this->ruleAsArray($filtered, $date));
    }

    #[Delete(self::URL . '[/]', name: 'ignore.delete')]
    public function deleteRule(iRequest $request): iResponse
    {
        $params = DataUtil::fromRequest($request);

        if (null === ($rule = $params->get('rule'))) {
            return api_error('Missing required parameter: rule', Status::BAD_REQUEST);
        }

        try {
            checkIgnoreRule($rule);
        } catch (RuntimeException $e) {
            return api_error($e->getMessage(), Status::BAD_REQUEST);
        }

        $filtered = (string)makeIgnoreId($rule);

        if (!$this->config->has($filtered)) {
            return api_error(r('Rule does not exist: {rule}', ['rule' => $rule]), Status::NOT_FOUND);
        }

        $date = $this->config->get($filtered);

        $this->config->delete($filtered)->persist();

        return api_response(Status::OK, $this->ruleAsArray($filtered, $date));
    }

    /**
     * Gets information about the ignore id.
     *
     * @param iUri $uri Ignore ID encoded as URL.
     *
     * @return string|null Return the name of the item or null if not found.
     */
    private function getInfo(iUri $uri): string|null
    {
        if (empty($uri->getQuery())) {
            return null;
        }

        $params = [];
        parse_str($uri->getQuery(), $params);

        $key = sprintf('%s://%s@%s', $uri->getScheme(), $uri->getHost(), $params['id']);

        if (true === array_key_exists($key, $this->cache)) {
            return $this->cache[$key];
        }

        $sql = sprintf(
            "SELECT * FROM state WHERE JSON_EXTRACT(metadata, '$.%s.%s') = :id LIMIT 1",
            $uri->getHost(),
            $uri->getScheme() === iState::TYPE_SHOW ? 'show' : 'id'
        );

        $stmt = $this->db->prepare($sql);
        $stmt->execute(['id' => $params['id']]);
        $item = $stmt->fetch(PDO::FETCH_ASSOC);

        if (empty($item)) {
            $this->cache[$key] = null;
            return null;
        }

        $this->cache[$key] = Container::get(iState::class)->fromArray($item)->getName(
            iState::TYPE_SHOW === $uri->getScheme()
        );

        return $this->cache[$key];
    }

    public function ruleAsArray(string $guid, int|null $date = null): array
    {
        $urlParts = parse_url($guid);

        $backend = ag($urlParts, 'host');
        $type = ag($urlParts, 'scheme');
        $db = ag($urlParts, 'user');
        $id = ag($urlParts, 'pass');
        $scope = ag($urlParts, 'query');
        $query = [];
        if (null !== $scope) {
            parse_str($scope, $query);
        }

        $rule = makeIgnoreId($guid);

        $scoped_to = null;

        if (ag_exists($query, 'id')) {
            $scoped_to = ctype_digit(ag($query, 'id')) ? (int)ag($query, 'id') : ag($query, 'id');
        }

        $builder = [
            'rule' => (string)$rule,
            'id' => $id,
            'type' => ucfirst($type),
            'backend' => $backend,
            'db' => $db,
            'title' => null !== $scope ? ($this->getinfo($rule) ?? 'Unknown') : null,
            'scoped' => null !== $scoped_to,
            'scoped_to' => $scoped_to,
        ];

        if (null !== $date) {
            $builder['created'] = makeDate($date);
        }

        return $builder;
    }
}
