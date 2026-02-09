<?php

declare(strict_types=1);

namespace App\Model\Base\Interfaces;

use App\Model\Base\BasicModel;

interface IDInterface
{
    /**
     * Create a new ID.
     *
     * @param BasicModel $model The model to create an ID for.
     *
     * @return int|string
     */
    public function makeId(BasicModel $model): int|string;
}
