<?php

declare(strict_types=1);

namespace App\Libs\Mappers;

use App\Libs\Database\DatabaseInterface as iDB;
use App\Libs\UserContext;
use Psr\Log\LoggerInterface as iLogger;
use Psr\SimpleCache\CacheInterface as iCache;

interface ExtendedImportInterface extends ImportInterface
{
    /**
     * Set the database connection. and return the instance
     *
     * @param iDB $db Database connection
     * @return self Instance of the class
     */
    public function withDB(iDB $db): self;

    /**
     * Set the cache connection. and return the instance
     *
     * @param iCache $cache Cache connection
     * @return self Instance of the class
     */
    public function withCache(iCache $cache): self;

    /**
     * Set the logger connection. and return the instance
     *
     * @param iLogger $logger Logger connection
     * @return self Instance of the class
     */
    public function withLogger(iLogger $logger): self;

    /**
     * Set User Context
     *
     * @param UserContext $userContext User Context
     *
     * @return self
     */
    public function withUserContext(UserContext $userContext): self;

    /**
     * Compute the play state for each backend.
     *
     * @param array $backends List of backends to check.
     *
     * @return array List of changes for each backend.
     */
    public function computeChanges(array $backends): array;
}
