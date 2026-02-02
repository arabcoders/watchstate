<?php

declare(strict_types=1);

namespace App\API\Backends;

use App\Commands\Backend\CreateUsersCommand;
use App\Libs\Attributes\Route\Get;
use App\Libs\Attributes\Route\Put;
use App\Libs\Config;
use App\Libs\ConfigFile;
use App\Libs\DataUtil;
use App\Libs\Enums\Http\Status;
use App\Libs\Traits\APITraits;
use DateInterval;
use Psr\Http\Message\ResponseInterface as iResponse;
use Psr\Http\Message\ServerRequestInterface as iRequest;
use Psr\Log\LoggerInterface as iLogger;
use Psr\SimpleCache\CacheInterface as iCache;
use Psr\SimpleCache\InvalidArgumentException;

final class Mapper
{
    use APITraits;

    private const string CACHE_KEY = 'all-backends-users';

    public function __construct(
        private readonly iLogger $logger,
        private readonly iCache $cache,
    ) {}

    #[Get(Index::URL . '/mapper[/]', name: 'backends.mappers.list')]
    public function list(iRequest $request): iResponse
    {
        $ignore = (bool) ag($request->getQueryParams(), 'force', false);
        $cached = new DateInterval('PT5M');

        $mapping = CreateUsersCommand::loadMappings();

        $data = cacheable_item(
            key: self::CACHE_KEY,
            function: static function () use ($mapping, $cached): array {
                $backends = CreateUsersCommand::loadBackends();
                if (count($backends) < 1) {
                    return [];
                }

                $backendsUser = CreateUsersCommand::get_backends_users($backends, $mapping);

                if (count($backendsUser) < 1) {
                    return [];
                }

                $obj = CreateUsersCommand::generate_users_list($backendsUser, $mapping);
                $matched = ag($obj, 'matched', []);
                $unmatched = ag($obj, 'unmatched', []);

                if (count($matched) < 1 && count($unmatched) < 1) {
                    return [];
                }

                $data = [
                    'matched' => [],
                    'unmatched' => [],
                    'backends' => array_keys($backends),
                ];

                foreach ($unmatched as $user) {
                    $data['unmatched'][] = [
                        'id' => ag($user, 'id', null),
                        'username' => ag($user, 'name', null),
                        'backend' => ag($user, 'backend', null),
                        'real_name' => ag($user, 'real_name', null),
                        'type' => ag($user, 'client_data', null),
                        'protected' => (bool) ag($user, 'protected', false),
                        'options' => ag($user, 'options', (object) []),
                    ];
                }

                foreach ($matched as $i => $user) {
                    $perUser = [
                        'user' => 'User group #' . ($i + 1),
                        'matched' => [],
                    ];

                    foreach (ag($user, 'backends', []) as $backend => $backendData) {
                        $perUser['matched'][] = [
                            'id' => ag($backendData, 'id', null),
                            'username' => ag($backendData, 'name', null),
                            'backend' => $backend,
                            'real_name' => ag($backendData, 'real_name', null),
                            'type' => ag($backendData, 'client_data.type', null),
                            'protected' => (bool) ag($backendData, 'protected', false),
                            'options' => ag($backendData, 'options', (object) []),
                        ];
                    }

                    $data['matched'][] = $perUser;
                }

                $data['expires'] = (string) make_date()->add($cached);

                return $data;
            },
            ttl: $cached,
            ignoreCache: $ignore,
            opts: [iCache::class => $this->cache],
        );

        $response = [
            'has_users' => CreateUsersCommand::hasUsers(),
            'has_mapper' => count($mapping) > 0,
            ...$data,
        ];

        return api_response(Status::OK, $response);
    }

    /**
     * Update the mapper file.
     *
     * @param iRequest $request The request object.
     *
     * @throws InvalidArgumentException May be thrown by the cache service.
     */
    #[Put(Index::URL . '/mapper[/]', name: 'backends.mappers.create')]
    public function update(iRequest $request): iResponse
    {
        $body = DataUtil::fromRequest($request);

        $data = [
            'version' => (string) $body->get('version', '1.5'),
            'map' => $body->get('map', []),
        ];

        if (!is_array($data['map'])) {
            return api_error('Invalid map data.', Status::BAD_REQUEST);
        }

        if (count($data['map']) < 1) {
            return api_error('Empty map data.', Status::BAD_REQUEST);
        }

        if (true === version_compare($data['version'], '1.5', '<')) {
            return api_error('Invalid version. must be 1.5 or greater.', Status::BAD_REQUEST);
        }

        $mapFile = Config::get('mapper_file');
        $exists = file_exists($mapFile);
        if (true === $exists) {
            unlink($mapFile);
        }

        $c = ConfigFile::open($mapFile, 'yaml', true, true, true)
            ->set('version', $data['version'])
            ->set('map', $data['map'])
            ->persist();

        if ($this->cache->has(self::CACHE_KEY)) {
            $this->cache->delete(self::CACHE_KEY);
        }

        return api_message(
            r('Mapper file successfully {state}.', [
                'state' => $exists ? 'updated' : 'created',
            ]),
            $exists ? Status::OK : Status::CREATED,
            body: $c->getAll(),
        );
    }
}
