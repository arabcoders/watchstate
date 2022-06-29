<?php

declare(strict_types=1);

namespace App\Libs;

use FilesystemIterator;
use PhpToken;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionAttribute;
use ReflectionClass;
use RuntimeException;
use SplFileInfo;
use Throwable;

final class Router
{
    public function __construct(private readonly array $dirs = [])
    {
    }

    public function generate(): array
    {
        $routes = [];

        foreach ($this->dirs as $path) {
            $routes += $this->scanDirectory($path);
        }

        return $routes;
    }

    private function scanDirectory(string $dir): array
    {
        $classes = $routes = [];

        /** @var array<SplFileInfo> $files */
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($files as $file) {
            if (!$file->isFile()) {
                continue;
            }

            if (!$file->isReadable() || 'php' !== $file->getExtension()) {
                continue;
            }

            $class = $this->parseFile((string)$file);

            if (false === $class) {
                continue;
            }

            array_push($classes, ...$class);
        }

        foreach ($classes as $className) {
            if (!class_exists($className)) {
                continue;
            }

            $routes += $this->getRoutes(new ReflectionClass($className));
        }

        return $routes;
    }

    protected function getRoutes(ReflectionClass $class): array
    {
        $routes = [];

        $attributes = $class->getAttributes(Routable::class, ReflectionAttribute::IS_INSTANCEOF);

        foreach ($attributes as $attribute) {
            try {
                $attributeClass = $attribute->newInstance();
            } catch (Throwable) {
                continue;
            }

            if (!$attributeClass instanceof Routable) {
                continue;
            }

            $routes[$attributeClass->command] = $class->getName();
        }

        return $routes;
    }

    private function parseFile(string $file): array|false
    {
        $classes = [];
        $namespace = '';

        if (false === ($content = @file_get_contents($file))) {
            throw new RuntimeException(
                sprintf('Unable to read \'%s\' - \'%s\' .', $file, error_get_last()['message'] ?? 'unknown')
            );
        }

        $tokens = PhpToken::tokenize($content);
        $count = count($tokens);

        foreach ($tokens as $i => $iValue) {
            if ($iValue->getTokenName() === 'T_NAMESPACE') {
                for ($j = $i + 1; $j < $count; $j++) {
                    if ($tokens[$j]->getTokenName() === 'T_NAME_QUALIFIED') {
                        $namespace = $tokens[$j]->text;
                        break;
                    }
                }
            }

            if ($iValue->getTokenName() === 'T_CLASS') {
                for ($j = $i + 1; $j < $count; $j++) {
                    if ($tokens[$j]->getTokenName() === 'T_WHITESPACE') {
                        continue;
                    }

                    if ($tokens[$j]->getTokenName() === 'T_STRING') {
                        $classes[] = $namespace . '\\' . $tokens[$j]->text;
                    } else {
                        break;
                    }
                }
            }
        }

        return count($classes) >= 1 ? $classes : false;
    }
}
