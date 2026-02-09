<?php

declare(strict_types=1);

namespace App\Libs\Attributes\Scanner;

enum Target
{
    case IS_CLASS;
    case IS_METHOD;
}
