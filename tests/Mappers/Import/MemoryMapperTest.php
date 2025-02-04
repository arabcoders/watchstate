<?php

declare(strict_types=1);

namespace Tests\Mappers\Import;

use App\Libs\Entity\StateEntity;
use App\Libs\Mappers\Import\MemoryMapper;
use App\Libs\Mappers\ImportInterface;
use Symfony\Component\Cache\Adapter\NullAdapter;
use Symfony\Component\Cache\Psr16Cache;

class MemoryMapperTest extends MapperAbstract
{
    protected function setupMapper(): ImportInterface
    {
        $mapper = new MemoryMapper($this->logger, $this->db, new Psr16Cache(new NullAdapter()));
        $mapper->setOptions(options: ['class' => new StateEntity([])]);

        return $mapper;
    }
}
