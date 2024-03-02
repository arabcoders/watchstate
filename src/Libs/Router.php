<?php

declare(strict_types=1);

namespace App\Libs;

use App\Libs\Attributes\Route\Route;
use FilesystemIterator;
use InvalidArgumentException;
use PhpToken;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionAttribute;
use ReflectionClass;
use RuntimeException;
use SplFileInfo;
use Throwable;

final readonly class Router
{
    /**
     * @param array $dirs List of directories to scan for php files.
     */
    public function __construct(private array $dirs = [])
    {
    }

    public function getDirs(): array
    {
        return $this->dirs;
    }

    public function generate(): array
    {
        $routes = [];

        foreach ($this->dirs as $path) {
            array_push($routes, ...$this->scanDirectory($path));
        }

        usort($routes, fn($a, $b) => strlen($a['path']) < strlen($b['path']) ? -1 : 1);

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

            array_push($routes, ...$this->getRoutes(new ReflectionClass($className)));
        }

        return $routes;
    }

    protected function getRoutes(ReflectionClass $class): array
    {
        $routes = [];

        $attributes = $class->getAttributes(Route::class, ReflectionAttribute::IS_INSTANCEOF);

        $invokable = false;

        foreach ($class->getMethods() as $method) {
            if ($method->getName() === '__invoke') {
                $invokable = true;
            }
        }

        foreach ($attributes as $attribute) {
            try {
                $attributeClass = $attribute->newInstance();

                if (!$attributeClass instanceof Route) {
                    continue;
                }
            } catch (Throwable) {
                continue;
            }

            if (false === $invokable && !$attributeClass->isCli) {
                throw new InvalidArgumentException(
                    r(
                        'Trying to route \'{route}\' to un-invokable class/method \'{callable}\'.',
                        [
                            'route' => $attributeClass->pattern,
                            'callable' => $class->getName()
                        ]
                    )
                );
            }

            $routes[] = [
                'path' => $attributeClass->pattern,
                'method' => $attributeClass->methods,
                'callable' => $class->getName(),
                'host' => $attributeClass->host,
                'middlewares' => $attributeClass->middleware,
                'name' => $attributeClass->name,
                'port' => $attributeClass->port,
                'scheme' => $attributeClass->scheme,
            ];
        }

        foreach ($class->getMethods() as $method) {
            $attributes = $method->getAttributes(Route::class, ReflectionAttribute::IS_INSTANCEOF);

            foreach ($attributes as $attribute) {
                try {
                    $attributeClass = $attribute->newInstance();
                    if (!$attributeClass instanceof Route) {
                        continue;
                    }
                } catch (Throwable) {
                    continue;
                }

                $call = $method->getName() === '__invoke' ? $class->getName() : [$class->getName(), $method->getName()];

                $routes[] = [
                    'path' => $attributeClass->pattern,
                    'method' => $attributeClass->methods,
                    'callable' => $call,
                    'host' => $attributeClass->host,
                    'middlewares' => $attributeClass->middleware,
                    'name' => $attributeClass->name,
                    'port' => $attributeClass->port,
                    'scheme' => $attributeClass->scheme,
                ];
            }
        }

        return $routes;
    }

    private function parseFile(string $file): array|false
    {
        $classes = [];
        $namespace = '';

        try {
            $stream = new Stream($file, 'r');
            $content = $stream->getContents();
            $stream->close();
        } catch (InvalidArgumentException $e) {
            throw new RuntimeException(
                r('Unable to read \'{file}\'. {error}', [
                    'file' => $file,
                    'error' => $e->getMessage(),
                ])
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
