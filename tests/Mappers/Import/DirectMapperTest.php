<?php

declare(strict_types=1);

namespace Tests\Mappers\Import;

use App\Libs\Entity\StateEntity;
use App\Libs\Mappers\Import\DirectMapper;
use App\Libs\Mappers\ImportInterface;

class DirectMapperTest extends AbstractTestsMapper
{
    protected function setupMapper(): ImportInterface
    {
        $mapper = new DirectMapper($this->logger, $this->db);
        $mapper->setOptions(options: ['class' => new StateEntity([])]);
        return $mapper;
    }
}
