<?php

declare(strict_types=1);

namespace App\Model\Events;

use App\Model\Base\BasicValidation;
use App\Model\Events\Event as EntityItem;

final class EventValidation extends BasicValidation
{
    public function __construct(EntityItem $object)
    {
        $this->runValidator($object);
    }
}
