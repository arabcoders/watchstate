<?php

declare(strict_types=1);

namespace App\Cli;

use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\CommandLoader\CommandLoaderInterface;
use Symfony\Component\Console\Exception\CommandNotFoundException;

final class CommandLoader implements CommandLoaderInterface
{
    /**
     * @param array<string, string> $commandMap
     * @param array<string, array<string>> $aliases
     */
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly array $commandMap,
        private readonly array $aliases = [],
    ) {}

    public function get(string $name): Command
    {
        $canonicalName = $this->resolveCanonicalName($name);
        if (null === $canonicalName || false === $this->has($name)) {
            throw new CommandNotFoundException(sprintf('Command "%s" does not exist.', $name));
        }

        $command = $this->container->get($this->commandMap[$canonicalName]);
        assert($command instanceof Command, sprintf(
            'Service "%s" must be a %s instance.',
            $this->commandMap[$canonicalName],
            Command::class,
        ));

        if ($canonicalName !== $command->getName()) {
            $command->setName($canonicalName);
        }

        $command->setAliases($this->aliases[$canonicalName] ?? []);

        return $command;
    }

    public function has(string $name): bool
    {
        $canonicalName = $this->resolveCanonicalName($name);

        return (
            null !== $canonicalName
            && isset($this->commandMap[$canonicalName])
            && $this->container->has($this->commandMap[$canonicalName])
        );
    }

    /**
     * @return list<string>
     */
    public function getNames(): array
    {
        return array_keys($this->commandMap);
    }

    private function resolveCanonicalName(string $name): ?string
    {
        if (isset($this->commandMap[$name])) {
            return $name;
        }

        foreach ($this->aliases as $canonicalName => $aliases) {
            if (in_array($name, $aliases, true)) {
                return $canonicalName;
            }
        }

        return null;
    }
}
