<?php

declare(strict_types=1);

namespace App\Libs\Extends;

use App\Libs\HTTP_STATUS;
use League\Route\Strategy\ApplicationStrategy;
use League\Route\Strategy\OptionsHandlerInterface;
use Psr\Http\Message\ResponseInterface;

class RouterStrategy extends ApplicationStrategy implements OptionsHandlerInterface
{
    public function getOptionsCallable(array $methods): callable
    {
        return fn(): ResponseInterface => api_response(body: [], status: HTTP_STATUS::HTTP_NO_CONTENT, headers: [
            'Allow' => implode(', ', $methods),
            'Access-Control-Allow-Methods' => implode(', ', $methods),
        ]);
    }
}
