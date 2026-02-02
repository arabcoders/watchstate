<?php

declare(strict_types=1);

namespace App\Libs;

use App\Libs\Enums\Http\Method;
use Closure;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7Server\ServerRequestCreator;
use Psr\Http\Message\ServerRequestInterface as iRequest;
use Random\RandomException;
use Throwable;

final readonly class Profiler
{
    public const string QUERY_NAME = '_profile';
    public const string HEADER_NAME = 'X-Profile';

    /**
     * Class constructor.
     *
     * @param Closure{data:array, response:mixed} $callback the callback to receive the profile data.
     * @param int $sample The sample rate.
     * @param array $config The profiler configuration.
     * @param array $flags The profiler flags.
     */
    public function __construct(
        private Closure $callback,
        private int $sample = 5000,
        private array $config = [],
        private array $flags = ['PROFILER_CPU_PROFILING', 'PROFILER_MEMORY_PROFILING'],
    ) {}

    /**
     * Process the function and profile it.
     *
     * @param callable $func The function to profile.
     * @param iRequest|null $request The request object.
     *
     * @return mixed The result of the function.
     */
    public function process(callable $func, ?iRequest $request = null): mixed
    {
        if (false === extension_loaded('xhprof') || false === class_exists('Xhgui\Profiler\Profiler')) {
            return $func();
        }

        if (null === $request) {
            $factory = new Psr17Factory();
            $request = new ServerRequestCreator($factory, $factory, $factory, $factory)->fromGlobals();
        }

        if (Method::GET !== Method::tryFrom($request->getMethod())) {
            return $func();
        }

        if (false === $this->sample($request)) {
            return $func();
        }

        try {
            $profiler = new \Xhgui\Profiler\Profiler($this->config);
            $profiler->enable($this->flags);
        } catch (Throwable) {
            return $func();
        }

        $response = $func();

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
            ag($request->getServerParams(), 'X_REQUEST_ID', generate_uuid(...)),
        );

        ($this->callback)($data, $response);

        return $response;
    }

    /**
     * Check if the request should be profiled.
     *
     * @param iRequest $request The request object.
     *
     * @return bool True if the request should be profiled, false otherwise.
     */
    private function sample(iRequest $request): bool
    {
        if (true === $request->hasHeader(self::HEADER_NAME)) {
            return true;
        }

        if (true === ag_exists($request->getQueryParams(), self::QUERY_NAME)) {
            return true;
        }

        try {
            return 1 === random_int(1, $this->sample);
        } catch (RandomException) {
            return 1 === rand(1, $this->sample);
        }
    }
}
