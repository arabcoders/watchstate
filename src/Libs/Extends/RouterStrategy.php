<?php

declare(strict_types=1);

namespace App\Libs\Extends;

use App\Libs\Container;
use App\Libs\Enums\Http\Status;
use League\Route\Route;
use League\Route\Strategy\ApplicationStrategy;
use League\Route\Strategy\OptionsHandlerInterface;
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

        if ('cors' === ag($_SERVER, 'HTTP_SEC_FETCH_MODE')) {
            return static fn(): iResponse => add_cors(
                api_response(Status::NO_CONTENT, headers: $headers),
                $headers,
                $methods,
            );
        }

        return static fn(): iResponse => api_response(Status::NO_CONTENT, headers: $headers);
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
                ],
            ),
        );
    }
}
