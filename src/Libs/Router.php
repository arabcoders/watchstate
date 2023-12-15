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

/**
 * Router class handles the generation of routes based on scanned directories and class attributes.
 */
final class Router
{
    /**
     * Class constructor.
     *
     * @param array $dirs An optional array of directories.
     */
    public function __construct(private readonly array $dirs = [])
    {
    }

    /**
     * Generates an array of routes by scanning directories.
     *
     * @return array The generated routes.
     */
    public function generate(): array
    {
        $routes = [];

        foreach ($this->dirs as $path) {
            $routes += $this->scanDirectory($path);
        }

        return $routes;
    }

    /**
     * Scans the given directory and returns an array of routes.
     *
     * @param string $dir The directory to scan for files.
     *
     * @return array An array of routes.
     */
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

    /**
     * Get the routes for a given class.
     *
     * @param ReflectionClass $class The reflection instance of given class to get the routes for.
     *
     * @return array The routes for the class.
     */
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

    /**
     * Parses a file and extracts the namespaces and classes.
     *
     * @param string $file The path to the file to parse.
     *
     * @return array|false An array of fully qualified class names if classes are found, otherwise false.
     * @throws RuntimeException If the file cannot be read.
     */
    private function parseFile(string $file): array|false
    {
        $classes = [];
        $namespace = '';

        if (false === ($content = @file_get_contents($file))) {
            throw new RuntimeException(r("Unable to read '{file}' - '{message}'.", [
                'file' => $file,
                'message' => error_get_last()['message'] ?? 'unknown',
            ]));
        }

        $tokens = PhpToken::tokenize($content);
        $count = count($tokens);

        foreach ($tokens as $i => $iValue) {
            if ('T_NAMESPACE' === $iValue->getTokenName()) {
                for ($j = $i + 1; $j < $count; $j++) {
                    if ('T_NAME_QUALIFIED' === $tokens[$j]->getTokenName()) {
                        $namespace = $tokens[$j]->text;
                        break;
                    }
                }
            }

            if ('T_CLASS' === $iValue->getTokenName()) {
                for ($j = $i + 1; $j < $count; $j++) {
                    if ('T_WHITESPACE' === $tokens[$j]->getTokenName()) {
                        continue;
                    }

                    if ('T_STRING' === $tokens[$j]->getTokenName()) {
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
