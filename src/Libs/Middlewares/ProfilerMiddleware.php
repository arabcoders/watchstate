<?php

declare(strict_types=1);

namespace App\Libs\Middlewares;

use App\Libs\Config;
use App\Libs\Enums\Http\Method;
use App\Listeners\ProcessProfileEvent;
use Psr\Http\Message\ResponseInterface as iResponse;
use Psr\Http\Message\ServerRequestInterface as iRequest;
use Psr\Http\Server\MiddlewareInterface as iMiddleware;
use Psr\Http\Server\RequestHandlerInterface as iHandler;
use Random\RandomException;
use Throwable;

readonly final class ProfilerMiddleware implements iMiddleware
{
    public const string QUERY_NAME = '_profile';
    public const string HEADER_NAME = 'X-Profile';

    public function process(iRequest $request, iHandler $handler): iResponse
    {
        if (false === extension_loaded('xhprof') || false === class_exists('Xhgui\Profiler\Profiler')) {
            return $handler->handle($request);
        }

        if (Method::GET !== Method::tryFrom($request->getMethod())) {
            return $handler->handle($request);
        }

        $config = Config::get('profiler', []);

        if (false === $this->sample($request, $config)) {
            return $handler->handle($request->withHeader('X-Profiled', 'No'));
        }

        try {
            $profiler = new \Xhgui\Profiler\Profiler(ag($config, 'config', []));
            $profiler->enable(ag($config, 'flags', []));
        } catch (Throwable) {
            return $handler->handle($request);
        }

        $response = $handler->handle($request);

        try {
            $data = $profiler->disable();
        } catch (Throwable) {
            $data = [];
        }

        if (empty($data)) {
            return $response;
        }

        $data = ag_set(
            $data,
            'meta.id',
            ag($request->getServerParams(), 'X_REQUEST_ID', fn() => generateUUID())
        );

        queueEvent(ProcessProfileEvent::NAME, $data);
        return $response->withHeader('X-Profiled', 'Yes');
    }

    private function sample(iRequest $request, array $config): bool
    {
        if (true === $request->hasHeader(self::HEADER_NAME)) {
            return true;
        }

        if (true === ag_exists($request->getQueryParams(), self::QUERY_NAME)) {
            return true;
        }

        $max = (int)ag($config, 'sampler', 1000);
        try {
            return 1 === random_int(1, $max);
        } catch (RandomException) {
            return 1 === rand(1, $max);
        }
    }
}
