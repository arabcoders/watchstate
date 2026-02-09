<?php

declare(strict_types=1);

namespace App\Model\Base\Enums;

enum ScalarType
{
    case STRING;
    case INT;
    case FLOAT;
    case BOOL;
}
