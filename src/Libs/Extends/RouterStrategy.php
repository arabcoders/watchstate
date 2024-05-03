<?php

declare(strict_types=1);

namespace App\Libs\Extends;

use App\Libs\HTTP_STATUS;
use League\Route\Strategy\ApplicationStrategy;
use League\Route\Strategy\OptionsHandlerInterface;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface as iResponse;

class RouterStrategy extends ApplicationStrategy implements OptionsHandlerInterface
{
    public function getOptionsCallable(array $methods): callable
    {
        $headers = [
            'Allow' => implode(', ', $methods),
        ];

        $response = new Response(status: HTTP_STATUS::HTTP_NO_CONTENT->value, headers: $headers);

        if ('cors' === ag($_SERVER, 'HTTP_SEC_FETCH_MODE')) {
            return fn(): iResponse => addCors($response, $headers, $methods);
        }

        return fn(): iResponse => $response;
    }
}
