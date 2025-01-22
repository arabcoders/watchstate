<?php

declare(strict_types=1);

namespace App\Libs\Attributes\DI;

use Attribute;

#[Attribute(Attribute::TARGET_PARAMETER)]
readonly class Inject
{
    /**
     * Dynamically override dependency resolve. This is useful when you want to
     * resolve a dependency to a specific class which is not the same as the
     * type-hinted interface.
     *
     * For example, you have an interface `LoggerInterface` and you have two
     * classes that implement this interface: `FileLogger` and `DatabaseLogger`.
     * And you want to resolve the `LoggerInterface` to `FileLogger` when it is
     * type-hinted in a constructor. You can use this attribute to achieve that.
     * By doing the following:
     *
     * <code>
     * class Example
     * {
     *    public function __invoke(#[Inject(FileLogger::class)] private LoggerInterface $logger) {}
     * }
     * </code>
     *
     * @param string $name
     */
    public function __construct(public string $name)
    {
    }
}
