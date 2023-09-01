<?php

declare(strict_types=1);

namespace Tests\Mappers\Import;

use App\Libs\Entity\StateEntity;
use App\Libs\Mappers\Import\MemoryMapper;
use App\Libs\Mappers\ImportInterface;

class MemoryMapperTest extends AbstractTestsMapper
{
    protected function setupMapper(): ImportInterface
    {
        $mapper = new MemoryMapper($this->logger, $this->db);
        $mapper->setOptions(options: ['class' => new StateEntity([])]);

        return $mapper;
    }
}
