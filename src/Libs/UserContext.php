<?php

declare(strict_types=1);

namespace App\Libs;

use App\Libs\Attributes\DI\Inject;
use App\Libs\Database\DatabaseInterface as iDB;
use App\Libs\Mappers\Import\DirectMapper;
use App\Libs\Mappers\ImportInterface as iImport;
use Psr\SimpleCache\CacheInterface as iCache;

final class UserContext
{
    public readonly iImport $mapper;

    /**
     * Make User Context.
     *
     * @param string $name User name.
     * @param ConfigFile $config Per user Configuration file.
     * @param iImport $mapper Per user Data mapper
     * @param iCache $cache Per user cache.
     * @param iDB $db Per user database.
     * @param array $data (Optional) Mutable data for the context.
     */
    public function __construct(
        public readonly string $name,
        public readonly ConfigFile $config,
        #[Inject(DirectMapper::class)]
        iImport $mapper,
        public readonly iCache $cache,
        public readonly iDB $db,
        public array $data = [],
    ) {
        $this->mapper = $mapper->withUserContext($this);
    }

    public function getPath(): string
    {
        if (isset($this->data['path'])) {
            return $this->data['path'];
        }

        return fixPath(Config::get('path') . '/' . ('main' === $this->name ? 'config' : "users/{$this->name}"));
    }

    public function getBackendsNames(): string
    {
        return join(', ', array_keys($this->config->getAll()));
    }

    public function add(string $key, mixed $value): self
    {
        $this->data = ag_set($this->data, $key, $value);
        return $this;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return ag($this->data, $key, $default);
    }

    public function has(string $key): bool
    {
        return ag_exists($this->data, $key);
    }

    public function remove(string|array $key): self
    {
        $this->data = ag_delete($this->data, $key);
        return $this;
    }
}
