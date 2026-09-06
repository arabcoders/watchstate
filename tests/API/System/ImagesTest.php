<?php

declare(strict_types=1);

namespace Tests\API\System;

use App\API\System\Images;
use App\Libs\Database\DBLayer;
use App\Libs\Exceptions\DBLayerException;
use App\Libs\Mappers\ImportInterface;
use App\Libs\TestCase;
use PDO;
use PDOException;
use Psr\Log\NullLogger;
use Psr\SimpleCache\CacheInterface;

final class ImagesTest extends TestCase
{
    public function test_lock(): void
    {
        $pdo = $this->createMock(PDO::class);
        $pdo
            ->expects(self::once())
            ->method('getAttribute')
            ->with(PDO::ATTR_DRIVER_NAME)
            ->willReturn('sqlite');
        $pdo
            ->expects(self::once())
            ->method('prepare')
            ->willThrowException(new PDOException('database is locked'));

        $cache = $this->createMock(CacheInterface::class);
        $cache->expects(self::once())->method('has')->willReturn(false);

        $images = new Images(
            $this->createStub(ImportInterface::class),
            new NullLogger(),
            $cache,
        );

        $this->expectException(DBLayerException::class);
        $images->getImage(new DBLayer($pdo), 'background');
    }
}
