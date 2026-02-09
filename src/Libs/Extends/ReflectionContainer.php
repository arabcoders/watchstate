<?php

declare(strict_types=1);

namespace App\Libs\Extends;

use App\Libs\Attributes\DI\Inject;
use League\Container\Argument\ArgumentInterface;
use League\Container\Argument\ArgumentResolverInterface;
use League\Container\Argument\DefaultValueArgument;
use League\Container\Argument\DefaultValueInterface;
use League\Container\Argument\LiteralArgument;
use League\Container\Argument\LiteralArgumentInterface;
use League\Container\Argument\ResolvableArgument;
use League\Container\ContainerAwareTrait;
use League\Container\Exception\ContainerException;
use League\Container\Exception\NotFoundException;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use ReflectionClass;
use ReflectionFunction;
use ReflectionFunctionAbstract;
use ReflectionMethod;
use ReflectionNamedType;

class ReflectionContainer implements ArgumentResolverInterface, ContainerInterface
{
    use ContainerAwareTrait;

    /**
     * @var array
     */
    protected array $cache = [];

    public function __construct(
        protected bool $cacheResolutions = false,
    ) {}

    /**
     * @param $id
     * @param array $args
     * @return mixed|object|string|null
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws \ReflectionException
     */
    public function get($id, array $args = []): mixed
    {
        if (true === $this->cacheResolutions && array_key_exists($id, $this->cache)) {
            return $this->cache[$id];
        }

        if (!$this->has($id)) {
            throw new NotFoundException(
                r("Alias '{class}' is not an existing class and therefore cannot be resolved.", [
                    'class' => $id,
                ]),
            );
        }

        $reflector = new ReflectionClass($id);
        $construct = $reflector->getConstructor();

        if ($construct && !$construct->isPublic()) {
            throw new NotFoundException(
                r("Alias '{class}' has a non-public constructor and therefore cannot be instantiated.", [
                    'class' => $id,
                ]),
            );
        }

        $resolution = null === $construct
            ? new $id()
            : $reflector->newInstanceArgs($this->reflectArguments($construct, $args));

        if (true === $this->cacheResolutions) {
            $this->cache[$id] = $resolution;
        }

        return $resolution;
    }

    public function has($id): bool
    {
        return class_exists($id);
    }

    /**
     * @throws NotFoundExceptionInterface
     * @throws \ReflectionException
     * @throws ContainerExceptionInterface
     */
    public function call(callable $callable, array $args = [])
    {
        if (is_string($callable) && str_contains($callable, '::')) {
            $callable = explode('::', $callable);
        }

        if (is_array($callable)) {
            if (is_string($callable[0])) {
                // if we have a definition container, try that first, otherwise, reflect
                try {
                    $callable[0] = $this->getContainer()->get($callable[0]);
                } catch (ContainerException) {
                    $callable[0] = $this->get($callable[0]);
                }
            }

            $reflection = new ReflectionMethod($callable[0], $callable[1]);

            if ($reflection->isStatic()) {
                $callable[0] = null;
            }

            return $reflection->invokeArgs($callable[0], $this->reflectArguments($reflection, $args));
        }

        if (is_object($callable)) {
            $reflection = new ReflectionMethod($callable, '__invoke');
            /** @noinspection PhpParamsInspection */
            return $reflection->invokeArgs($callable, $this->reflectArguments($reflection, $args));
        }

        $reflection = new ReflectionFunction($callable(...));

        return $reflection->invokeArgs($this->reflectArguments($reflection, $args));
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws \ReflectionException
     * @throws NotFoundExceptionInterface
     */
    public function resolveArguments(array $arguments): array
    {
        try {
            $container = $this->getContainer();
        } catch (ContainerException) {
            $container = $this;
        }

        foreach ($arguments as &$arg) {
            // if we have a literal, we don't want to do anything more with it
            if ($arg instanceof LiteralArgumentInterface) {
                $arg = $arg->getValue();
                continue;
            }

            if ($arg instanceof ArgumentInterface) {
                $argValue = $arg->getValue();
            } else {
                $argValue = $arg;
            }

            if (!is_string($argValue)) {
                continue;
            }

            // resolve the argument from the container, if it happens to be another
            // argument wrapper, use that value
            if ($container instanceof ContainerInterface && $container->has($argValue)) {
                try {
                    $arg = $container->get($argValue);

                    if ($arg instanceof ArgumentInterface) {
                        $arg = $arg->getValue();
                    }

                    continue;
                } catch (NotFoundException) {
                }
            }

            // if we have a default value, we use that, no more resolution as
            // we expect a default/optional argument value to be literal
            if ($arg instanceof DefaultValueInterface) {
                $arg = $arg->getDefaultValue();
            }
        }

        return $arguments;
    }

    /**
     * @throws \ReflectionException
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function reflectArguments(ReflectionFunctionAbstract $method, array $args = []): array
    {
        $params = $method->getParameters();
        $arguments = [];

        foreach ($params as $param) {
            $name = $param->getName();

            // if we've been given a value for the argument, treat as literal
            if (array_key_exists($name, $args)) {
                $arguments[] = new LiteralArgument($args[$name]);
                continue;
            }

            $attributes = $param->getAttributes(Inject::class);
            if (count($attributes) > 0) {
                $injector = $attributes[0]->newInstance();
                assert($injector instanceof Inject, 'Expected Inject attribute instance.');
                if (array_key_exists($injector->name, $args)) {
                    $arguments[] = new LiteralArgument($args[$injector->name]);
                    continue;
                }

                if ($param->isDefaultValueAvailable()) {
                    $arguments[] = new DefaultValueArgument($injector->name, $param->getDefaultValue());
                    continue;
                }

                $arguments[] = new ResolvableArgument($injector->name);
                continue;
            }

            $type = $param->getType();

            if ($type instanceof ReflectionNamedType) {
                // in PHP 8, nullable arguments have "?" prefix
                $typeHint = ltrim($type->getName(), '?');

                if (array_key_exists($typeHint, $args)) {
                    $arguments[] = new LiteralArgument($args[$typeHint]);
                    continue;
                }

                if ($param->isDefaultValueAvailable()) {
                    $arguments[] = new DefaultValueArgument($typeHint, $param->getDefaultValue());
                    continue;
                }

                $arguments[] = new ResolvableArgument($typeHint);
                continue;
            }

            if ($param->isDefaultValueAvailable()) {
                $arguments[] = new LiteralArgument($param->getDefaultValue());
                continue;
            }

            throw new NotFoundException(r("Unable to resolve a value for parameter '{param}' in '{method}'.", [
                'param' => $name,
                'method' => $method->getName(),
            ]));
        }

        return $this->resolveArguments($arguments);
    }
}
