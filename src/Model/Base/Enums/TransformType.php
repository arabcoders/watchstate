<?php

declare(strict_types=1);

namespace App\Model\Base\Enums;

enum TransformType
{
    case ENCODE;

    case DECODE;
}
