<?php

declare(strict_types=1);

namespace App\API\System;

use App\Libs\Attributes\Route\Delete;
use App\Libs\Attributes\Route\Get;
use App\Libs\Container;
use App\Libs\Database\DatabaseInterface as iDB;
use App\Libs\DataUtil;
use App\Libs\Entity\StateInterface as iState;
use App\Libs\Enums\Http\Status;
use App\Libs\Middlewares\ExceptionHandlerMiddleware;
use App\Libs\Traits\APITraits;
use DateInterval;
use PDO;
use Psr\Http\Message\ResponseInterface as iResponse;
use Psr\Http\Message\ServerRequestInterface as iRequest;
use Psr\SimpleCache\CacheInterface as iCache;
use Psr\SimpleCache\InvalidArgumentException;

final class Integrity
{
    use APITraits;

    public const string URL = '%{api.prefix}/system/integrity';

    private array $dirExists = [];

    private array $checkedFile = [];

    private bool $fromCache = false;
    private PDO $pdo;

    /**
     * @throws InvalidArgumentException
     */
    public function __construct(private iDB $db, private readonly iCache $cache)
    {
        set_time_limit(0);
        $this->pdo = $this->db->getPDO();
    }

    /**
     * @throws InvalidArgumentException
     */
    #[Get(self::URL . '[/]', middleware: [ExceptionHandlerMiddleware::class], name: 'system.integrity')]
    public function __invoke(iRequest $request): iResponse
    {
        $params = DataUtil::fromArray($request->getQueryParams());

        if ($this->cache->has('system.integrity')) {
            $data = $this->cache->get('system.integrity', []);
            $this->dirExists = ag($data, 'dir_exists', []);
            $this->checkedFile = ag($data, 'checked_file', []);
            $this->fromCache = true;
        }

        $limit = $params->get('limit', 1000);

        $response = [
            'items' => [],
            'total' => 0,
            'fromCache' => $this->fromCache,
        ];

        $sql = "SELECT * FROM state";
        $stmt = $this->db->getPDO()->prepare($sql);
        $stmt->execute();

        $base = Container::get(iState::class);

        foreach ($stmt as $row) {
            if ($response['total'] > $limit) {
                break;
            }

            $entity = $base::fromArray($row);

            if (false === $this->checkIntegrity($entity)) {
                $response['items'][] = $this->formatEntity($entity, true);
                $response['total']++;
            }
        }

        $this->cache->set('system.integrity', [
            'dir_exists' => $this->dirExists,
            'checked_file' => $this->checkedFile,
        ], new DateInterval('PT1H'));

        return api_response(Status::OK, $response);
    }

    private function checkIntegrity(iState $entity): bool
    {
        $metadata = $entity->getMetadata();

        if (empty($metadata)) {
            return true;
        }

        $checks = [];

        foreach ($metadata as $backend => $data) {
            if (!isset($data['path'])) {
                continue;
            }

            $checks[] = [
                'backend' => $backend,
                'path' => $data['path'],
                'status' => true,
                'message' => '',
            ];
        }

        if (empty($checks)) {
            return true;
        }

        foreach ($checks as &$check) {
            $path = $check['path'];
            $dirName = dirname($path);

            if (false === $this->checkPath($dirName)) {
                $check['status'] = false;
                $check['message'] = "File parent directory does not exist.";
                continue;
            } else {
                $check['status'] = true;
                $check['message'] = "File parent directory exists.";
            }

            if (false === $this->checkFile($path)) {
                $check['status'] = false;
                $check['message'] = "File does not exist.";
            } else {
                $check['status'] = true;
                $check['message'] = "File exists.";
            }
        }

        unset($check);

        foreach ($checks as $check) {
            if (false === $check['status']) {
                $entity->setContext('integrity', $checks);
                return false;
            }
        }

        return true;
    }

    /**
     * @throws InvalidArgumentException
     */
    #[Delete(self::URL . '[/]', name: 'system.integrity.reset')]
    public function resetCache(iRequest $request): iResponse
    {
        if ($this->cache->has('system.integrity')) {
            $this->cache->delete('system.integrity');
        }

        return api_response(Status::OK);
    }

    private function checkPath(string $file): bool
    {
        $dirs = explode(DIRECTORY_SEPARATOR, $file);
        foreach ($dirs as $i => $dir) {
            $path = implode(DIRECTORY_SEPARATOR, array_slice($dirs, 0, $i + 1));
            if (empty($path)) {
                continue;
            }
            if (false === $this->dirExists($path)) {
                return false;
            }
        }

        return true;
    }

    private function dirExists(string $dir): bool
    {
        if (array_key_exists($dir, $this->dirExists)) {
            return $this->dirExists[$dir];
        }

        $this->dirExists[$dir] = is_dir($dir);

        return $this->dirExists[$dir];
    }

    private function checkFile(string $file): bool
    {
        if (array_key_exists($file, $this->checkedFile)) {
            return $this->checkedFile[$file];
        }

        $this->checkedFile[$file] = file_exists($file);

        return $this->checkedFile[$file];
    }
}
