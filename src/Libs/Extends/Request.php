<?php

declare(strict_types=1);

namespace App\Libs\Extends;

use App\Libs\Config;
use GuzzleHttp\Client;

class Request extends Client
{
    public function __construct(array $options = [])
    {
        parent::__construct(array_replace_recursive(Config::get('request.default.options', []), $options));
    }
}
