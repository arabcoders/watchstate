<?php

declare(strict_types=1);

namespace App\API\Ignore;

use App\Libs\Attributes\DI\Inject;
use App\Libs\Attributes\Route\Delete;
use App\Libs\Attributes\Route\Get;
use App\Libs\Attributes\Route\Post;
use App\Libs\ConfigFile;
use App\Libs\Container;
use App\Libs\DataUtil;
use App\Libs\Entity\StateInterface as iState;
use App\Libs\Enums\Http\Status;
use App\Libs\Exceptions\RuntimeException;
use App\Libs\Mappers\Import\DirectMapper;
use App\Libs\Mappers\ImportInterface as iImport;
use App\Libs\Traits\APITraits;
use App\Libs\UserContext;
use PDO;
use Psr\Http\Message\ResponseInterface as iResponse;
use Psr\Http\Message\ServerRequestInterface as iRequest;
use Psr\Http\Message\UriInterface as iUri;
use Psr\Log\LoggerInterface as iLogger;

final class Index
{
    use APITraits;

    public const string URL = '%{api.prefix}/ignore';

    private array $cache = [];

    public function __construct(
        #[Inject(DirectMapper::class)]
        private readonly iImport $mapper,
        private readonly iLogger $logger,
    ) {}

    #[Get(self::URL . '[/]', name: 'ignore')]
    public function __invoke(iRequest $request): iResponse
    {
        try {
            $userContext = $this->getUserContext($request, $this->mapper, $this->logger);
        } catch (RuntimeException $e) {
            return api_error($e->getMessage(), Status::NOT_FOUND);
        }

        $params = DataUtil::fromArray($request->getQueryParams());

        $type = $params->get('type');
        $db = $params->get('db');
        $id = $params->get('id');
        $backend = $params->get('backend');

        $response = [];

        foreach ($this->getConfigFile(userContext: $userContext)->getAll() as $guid => $date) {
            $item = $this->ruleAsArray(userContext: $userContext, guid: $guid, date: $date);

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
        try {
            $userContext = $this->getUserContext($request, $this->mapper, $this->logger);
        } catch (RuntimeException $e) {
            return api_error($e->getMessage(), Status::NOT_FOUND);
        }

        $params = DataUtil::fromRequest($request);

        if (null === ($id = $params->get('rule'))) {
            foreach (['id', 'db', 'backend', 'type'] as $key) {
                if (null !== $params->get($key)) {
                    continue;
                }

                return api_error(r('Missing required parameter: {key}', ['key' => $key]), Status::BAD_REQUEST);
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
            check_ignore_rule(guid: $id, userContext: $userContext);
        } catch (RuntimeException $e) {
            return api_error($e->getMessage(), Status::BAD_REQUEST);
        }

        $filtered = (string) make_ignore_id($id);
        $date = time();

        $config = $this->getConfigFile(userContext: $userContext);

        if ($config->has($id) || $config->has($filtered)) {
            return api_error(r('Rule already exists: {id}', ['id' => $id]), Status::CONFLICT);
        }

        $config->set($filtered, $date)->persist();

        return api_response(Status::OK, $this->ruleAsArray(userContext: $userContext, guid: $filtered, date: $date));
    }

    #[Delete(self::URL . '[/]', name: 'ignore.delete')]
    public function deleteRule(iRequest $request): iResponse
    {
        try {
            $userContext = $this->getUserContext($request, $this->mapper, $this->logger);
        } catch (RuntimeException $e) {
            return api_error($e->getMessage(), Status::NOT_FOUND);
        }

        $params = DataUtil::fromRequest($request);

        if (null === ($rule = $params->get('rule'))) {
            return api_error('Missing required parameter: rule', Status::BAD_REQUEST);
        }

        try {
            check_ignore_rule(guid: $rule, userContext: $userContext);
        } catch (RuntimeException $e) {
            return api_error($e->getMessage(), Status::BAD_REQUEST);
        }

        $filtered = (string) make_ignore_id($rule);

        $config = $this->getConfigFile(userContext: $userContext);

        if (!$config->has($filtered)) {
            return api_error(r('Rule does not exist: {rule}', ['rule' => $rule]), Status::NOT_FOUND);
        }

        $date = $config->get($filtered);

        $config->delete($filtered)->persist();

        return api_response(Status::OK, $this->ruleAsArray(userContext: $userContext, guid: $filtered, date: $date));
    }

    /**
     * Gets information about the ignore id.
     *
     * @param iUri $uri Ignore ID encoded as URL.
     *
     * @return string|null Return the name of the item or null if not found.
     */
    private function getInfo(UserContext $userContext, iUri $uri): ?string
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
            $uri->getScheme() === iState::TYPE_SHOW ? 'show' : 'id',
        );

        $stmt = $userContext->db->getDBLayer()->prepare($sql);
        $stmt->execute(['id' => $params['id']]);
        $item = $stmt->fetch(PDO::FETCH_ASSOC);

        if (empty($item)) {
            $this->cache[$key] = null;
            return null;
        }

        $this->cache[$key] = Container::get(iState::class)->fromArray($item)->getName(
            iState::TYPE_SHOW === $uri->getScheme(),
        );

        return $this->cache[$key];
    }

    public function ruleAsArray(UserContext $userContext, string $guid, ?int $date = null): array
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

        $rule = make_ignore_id($guid);

        $scoped_to = null;

        if (ag_exists($query, 'id')) {
            $scoped_to = ctype_digit(ag($query, 'id')) ? (int) ag($query, 'id') : ag($query, 'id');
        }

        $builder = [
            'rule' => (string) $rule,
            'id' => $id,
            'type' => ucfirst($type),
            'backend' => $backend,
            'db' => $db,
            'title' => null !== $scope ? $this->getinfo(userContext: $userContext, uri: $rule) ?? 'Unknown' : null,
            'scoped' => null !== $scoped_to,
            'scoped_to' => $scoped_to,
        ];

        if (null !== $date) {
            $builder['created'] = make_date($date);
        }

        return $builder;
    }

    private function getConfigFile(UserContext $userContext): ConfigFile
    {
        return ConfigFile::open(
            file: $userContext->getPath() . '/ignore.yaml',
            type: 'yaml',
            autoCreate: true,
            autoBackup: false,
        );
    }
}
