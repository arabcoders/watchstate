<?php

declare(strict_types=1);

namespace App\Libs;

use App\Libs\Extends\PSRContainer as BaseContainer;
use League\Container\ReflectionContainer;
use RuntimeException;

/**
 * Container class provides a dependency injection container implementation.
 *
 * This class provides methods for initializing the container, adding services to the container,
 * retrieving instances of classes from the container, and checking if a service exists in the container.
 */
final class Container
{
    /**
     * @var BaseContainer|null An instance of the container.
     */
    private static BaseContainer|null $container = null;

    /**
     * Initialize the container with an optional base container.
     *
     * @param BaseContainer|null $container (optional) The base container to be used. If not provided, a new BaseContainer will be created.
     *
     * @return self The initialized container.
     */
    public static function init(BaseContainer|null $container = null): self
    {
        if (null === self::$container) {
            if (null === $container) {
                $container = new BaseContainer();
                $container->defaultToShared(true);
                $reflectionContainer = new ReflectionContainer(true);
                $container->delegate($reflectionContainer);
                $container->addShared(ReflectionContainer::class, $reflectionContainer);
                $reflectionContainer = null;
            }
            self::$container = $container;
        }

        return new self();
    }

    /**
     * Add a new service to the container.
     *
     * @param string $id The unique identifier for the service.
     * @param mixed $concrete The concrete implementation of the service.
     *
     * @return self Returns a new instance of the container.
     */
    public static function add(string $id, mixed $concrete): self
    {
        self::addService($id, $concrete);

        return new self();
    }

    /**
     * Add a new service to the container.
     *
     * @param string $id The identifier for the service.
     * @param callable|array|object $definition The definition of the service.
     */
    private static function addService(string $id, callable|array|object $definition): void
    {
        if (is_callable($definition) || is_object($definition)) {
            self::$container->add($id, $definition);
        }

        if (is_array($definition)) {
            $service = self::$container->add($id, $definition['class']);

            if (!empty($definition['args'])) {
                $service->addArguments($definition['args']);
            }

            if (!empty($definition['tag'])) {
                $service->addTag($definition['tag']);
            }

            if (!empty($definition['alias'])) {
                $service->setAlias($definition['alias']);
            }

            if (array_key_exists('shared', $definition)) {
                $service->setShared((bool)$definition['shared']);
            }

            if (!empty($definition['call']) && is_array($definition['call'])) {
                $service->addMethodCalls($definition['call']);
            }
        }
    }

    /**
     * Get Instance of requested class.
     *
     * @template T
     * @param class-string<T> $className
     *
     * @return T
     */
    public static function get(string $className)
    {
        return self::$container->get($className);
    }

    /**
     * Get Instance of requested class.
     *
     * @template T
     * @param class-string<T> $className
     *
     * @return T
     */
    public static function getNew(string $className)
    {
        return self::$container->getNew($className);
    }

    /**
     * Check if the container has an instance of the requested class.
     *
     * @param class-string $className The name of the class to check.
     *
     * @return bool True if the container has an instance of the requested class, false otherwise.
     */
    public static function has(string $className): bool
    {
        return self::$container->has($className);
    }

    /**
     * Retrieves the container instance.
     *
     * @return BaseContainer The container instance.
     * @throws RuntimeException if the container has not been initialized.
     */
    public static function getContainer(): BaseContainer
    {
        if (null === self::$container) {
            throw new RuntimeException('PSRContainer has not been initialized.');
        }

        return self::$container;
    }
}
