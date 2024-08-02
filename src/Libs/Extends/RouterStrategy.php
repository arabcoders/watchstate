<?php

declare(strict_types=1);

namespace App\Libs\Extends;

use App\Libs\Container;
use App\Libs\Enums\HTTP_STATUS;
use League\Route\Route;
use League\Route\Strategy\ApplicationStrategy;
use League\Route\Strategy\OptionsHandlerInterface;
use Nyholm\Psr7\Response;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Http\Message\ResponseInterface as iResponse;
use Psr\Http\Message\ServerRequestInterface as iRequest;

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

    /**
     * @throws ContainerExceptionInterface
     * @throws \ReflectionException
     * @throws NotFoundExceptionInterface
     */
    public function invokeRouteCallable(Route $route, iRequest $request): iResponse
    {
        return $this->decorateResponse(
            Container::get(ReflectionContainer::class)->call(
                callable: $route->getCallable($this->getContainer()),
                args: [
                    ...$route->getVars(),
                    iRequest::class => $request,
                    'args' => $route->getVars(),
                ]
            )
        );
    }
}
