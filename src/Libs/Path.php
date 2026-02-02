<?php

declare(strict_types=1);

namespace App\Libs;

use RuntimeException;

/**
 * Class Path
 * A utility class for handling and manipulating filesystem paths.
 * @property-read Path $parent The parent directory of the path.
 * @property-read Path $absolute The absolute path.
 */
final readonly class Path
{
    public string $path;
    public string $name;
    public string $stem;
    public string $suffix;

    /**
     * Constructor.
     *
     * @param Path|string $path The initial path.
     */
    public function __construct(Path|string $path)
    {
        $this->path = (string) $path;
        $this->name = basename($this->path);
        $this->stem = pathinfo($this->path, PATHINFO_FILENAME);
        $this->suffix = pathinfo($this->path, PATHINFO_EXTENSION);
    }

    /**
     * Factory method to create a new Path instance.
     *
     * @param Path|string $path The path to create the instance for.
     * @return self A new Path object.
     */
    public static function make(Path|string $path): self
    {
        return new self($path);
    }

    /**
     * Converts the Path object to a string.
     *
     * @return string The string representation of the path.
     */
    public function __toString(): string
    {
        return $this->path;
    }

    /**
     * Joins the current path with additional paths.
     *
     * @param Path|string ...$paths Paths to join.
     * @return self A new Path object with the joined path.
     */
    public function joinPath(Path|string ...$paths): self
    {
        $segments = array_map(static fn($p) => (string) $p, array_merge([$this->path], $paths));
        return new self(implode(DIRECTORY_SEPARATOR, $segments));
    }

    /**
     * Checks if the path exists.
     *
     * @return bool True if the path exists, false otherwise.
     */
    public function exists(): bool
    {
        return file_exists($this->path);
    }

    /**
     * Checks if the path is a directory.
     *
     * @return bool True if the path is a directory, false otherwise.
     */
    public function isDir(): bool
    {
        return is_dir($this->path);
    }

    /**
     * Checks if the path is a file.
     *
     * @return bool True if the path is a file, false otherwise.
     */
    public function isFile(): bool
    {
        return is_file($this->path);
    }

    /**
     * Creates a directory at the path.
     *
     * @param int $mode The permissions mode (default: 0777).
     * @param bool $recursive Whether to create directories recursively.
     * @param bool $exist_ok Whether to ignore if the directory already exists.
     * @throws RuntimeException If the directory already exists and $exist_ok is false.
     */
    public function mkdir(int $mode = 0o777, bool $recursive = false, bool $exist_ok = false): void
    {
        if (!$exist_ok && $this->exists()) {
            throw new RuntimeException("Directory already exists: {$this->path}");
        }
        @mkdir($this->path, $mode, $recursive);
    }

    /**
     * Deletes the file at the path.
     *
     * @throws RuntimeException If the path is not a file.
     */
    public function unlink(): void
    {
        if ($this->isFile()) {
            unlink($this->path);
            return;
        }

        throw new RuntimeException("Not a file: {$this->path}");
    }

    /**
     * Deletes the directory at the path.
     *
     * @throws RuntimeException If the path is not a directory.
     */
    public function rmdir(): void
    {
        if ($this->isDir()) {
            rmdir($this->path);
            return;
        }
        throw new RuntimeException("Not a directory: {$this->path}");
    }

    /**
     * Reads the contents of the file as a string.
     *
     * @return string The file contents.
     * @throws RuntimeException If the path is not a file.
     */
    public function read(): string
    {
        if (!$this->isFile()) {
            throw new RuntimeException("Not a file: {$this->path}");
        }
        return file_get_contents($this->path);
    }

    /**
     * Writes a string to the file.
     *
     * @param string $data The data to write.
     */
    public function write(string $data): void
    {
        file_put_contents($this->path, $data);
    }

    /**
     * Returns the absolute path.
     *
     * @return self A new Path object with the absolute path.
     */
    public function absolute(): self
    {
        return new self($this->normalize($this->path));
    }

    /**
     * Normalizes the path to its absolute, canonical form.
     *
     * @param string|Path $path The path to normalize.
     * @param string $separator The directory separator (default: DIRECTORY_SEPARATOR).
     *
     * @return Path A new Path object with the normalized path.
     */
    public function normalize(string|Path $path, string $separator = DIRECTORY_SEPARATOR): Path
    {
        $path = (string) $path;
        $isAbsolute = str_starts_with($path, $separator);

        // Use realpath if the file exists
        if (false !== ($rp = realpath($path))) {
            return new self($rp);
        }

        // Normalize all separators to the current system's
        $path = str_replace(['/', '\\'], $separator, $path);

        $prefix = '';

        // Detect drive letter (e.g., C:\...)
        if (1 === preg_match('/^[a-zA-Z]:\\\\/', $path, $m)) {
            $prefix = $m[0];
            $path = substr($path, strlen($prefix));
        } // Detect UNC path (e.g., \\server\share)
        elseif (1 === preg_match('/^\\\\\\\\[^\\\\]+\\\\[^\\\\]+/', $path, $m)) {
            $prefix = $m[0];
            $path = substr($path, strlen($prefix));
        }

        // Split and resolve segments
        $parts = array_filter(explode($separator, $path), static fn($p) => $p !== '' && $p !== '.');
        $stack = [];

        foreach ($parts as $part) {
            if ($part === '..') {
                array_pop($stack);
            } else {
                $stack[] = $part;
            }
        }

        // Ensure trailing separator on prefix if necessary
        if ($prefix !== '' && !str_ends_with($prefix, $separator)) {
            $prefix .= $separator;
        }

        $normalized = $prefix . implode($separator, $stack);

        // Add leading separator for absolute paths without prefix (e.g., \foo\bar)
        if ($prefix === '' && $isAbsolute && !str_starts_with($normalized, $separator)) {
            $normalized = $separator . ltrim($normalized, $separator);
        }

        // Return fallback if normalization produced an empty string
        $fallback = $prefix !== '' ? $prefix : $separator;
        return new self($normalized === '' ? $fallback : $normalized);
    }

    /**
     * Gets the name of the file or directory.
     *
     * @return string The name of the file or directory.
     */
    public function name(): string
    {
        return basename($this->path);
    }

    /**
     * Gets the file extension (suffix).
     *
     * @return string The file extension.
     */
    public function suffix(): string
    {
        return pathinfo($this->path, PATHINFO_EXTENSION);
    }

    /**
     * Gets the file name without the extension (stem).
     *
     * @return string The file name without the extension.
     */
    public function stem(): string
    {
        return pathinfo($this->path, PATHINFO_FILENAME);
    }

    /**
     * Gets the parent directory of the path.
     *
     * @return self A new Path object for the parent directory.
     */
    public function parent(): self
    {
        return new self(dirname($this->path));
    }

    /**
     * Creates a new path with a different name in the same directory.
     *
     * @param string $name The new name.
     * @return self A new Path object with the updated name.
     */
    public function withName(string $name): self
    {
        return $this->parent()->joinPath($name);
    }

    /**
     * Creates a new path with a different file extension.
     *
     * @param string $suffix The new file extension.
     * @return self A new Path object with the updated extension.
     */
    public function withSuffix(string $suffix): self
    {
        return new self(preg_replace('/\.[^.]+$/', '', $this->path) . $suffix);
    }

    /**
     * Finds path names matching a pattern.
     *
     * @param string $pattern The glob pattern.
     * @return array<Path> An array of matching path names.
     */
    public function glob(string $pattern): array
    {
        $list = [];
        $items = glob($this->joinPath($pattern)->path);
        if (false === $items) {
            return $list;
        }

        foreach ($items as $item) {
            $list[] = new self($item);
        }

        return $list;
    }

    /**
     * Iterates over the contents of a directory.
     *
     * @return array An array of Path objects for the directory contents.
     * @throws RuntimeException If the path is not a directory.
     */
    public function iterDir(): array
    {
        if (!$this->isDir()) {
            throw new RuntimeException("Not a directory: {$this->path}");
        }

        $items = scandir($this->path);
        return array_map(fn($item) => new self($this->joinPath($item)->path), array_diff($items, ['.', '..']));
    }

    /**
     * Changes the permissions of the path.
     *
     * @param int $mode The new permission mode.
     * @throws RuntimeException If the operation fails.
     */
    public function chmod(int $mode): void
    {
        if (!@chmod($this->path, $mode)) {
            throw new RuntimeException("Failed to change mode for: {$this->path}");
        }
    }

    /**
     * Gets the owner of the file or directory.
     *
     * @return string The owner's username.
     */
    public function owner(): string
    {
        return posix_getpwuid(fileowner($this->path))['name'];
    }

    /**
     * Gets the group of the file or directory.
     *
     * @return string The group's name.
     */
    public function group(): string
    {
        return posix_getgrgid(filegroup($this->path))['name'];
    }

    /**
     * Checks if the path is absolute.
     *
     * @return bool True if the path is absolute, false otherwise.
     */
    public function isAbsolute(): bool
    {
        if (true === str_starts_with(PHP_OS, 'WIN')) {
            return 1 === preg_match('%^(?:[a-zA-Z]:[\\\/]|\\\\)%i', $this->path);
        }
        return str_starts_with($this->path, '/');
    }

    /**
     * Checks if the path is relative.
     *
     * @return bool True if the path is relative, false otherwise.
     */
    public function isRelative(): bool
    {
        return !$this->isAbsolute();
    }

    /**
     * Checks if the current path refers to the same file as another path.
     *
     * @param Path|string $other The other path to compare.
     * @return bool True if the paths refer to the same file, false otherwise.
     */
    public function sameFile(Path|string $other): bool
    {
        return (string) $this->normalize($this->path) === (string) $this->normalize((string) $other);
    }

    /**
     * Creates a symbolic link to the target path.
     *
     * @param Path|string $target The target path.
     * @throws RuntimeException If the operation fails.
     */
    public function symlinkTo(Path|string $target): void
    {
        if (!@symlink((string) $target, $this->path)) {
            throw new RuntimeException("Could not create symlink from {$this->path} to {$target}");
        }
    }

    /**
     * Checks if the path is a symbolic link.
     *
     * @return bool True if the path is a symbolic link, false otherwise.
     */
    public function isSymlink(): bool
    {
        return is_link($this->path);
    }

    /**
     * Resolves the path to its absolute, canonical form.
     *
     * @return self A new Path object with the resolved path.
     */
    public function resolve(): self
    {
        return $this->normalize($this->path);
    }

    /**
     * Gets the relative path from another path.
     *
     * @param Path|string $other The base path.
     * @return self A new Path object with the relative path.
     * @throws RuntimeException If the current path is not relative to the base path.
     */
    public function relativeTo(Path|string $other): self
    {
        $other = (string) $this->normalize((string) $other);
        $thisReal = (string) $this->normalize($this->path);

        if (!str_starts_with($thisReal, $other . DIRECTORY_SEPARATOR)) {
            throw new RuntimeException("{$this->path} is not relative to {$other}");
        }

        return new self(substr($thisReal, strlen($other) + 1));
    }

    /**
     * Checks if the path matches a pattern.
     *
     * @param string $pattern The pattern to match.
     * @return bool True if the path matches the pattern, false otherwise.
     */
    public function match(string $pattern): bool
    {
        return fnmatch($pattern, $this->path);
    }

    /**
     * Gets the status information of the file or directory.
     *
     * @return array An array of status information.
     */
    public function stat(): array
    {
        return stat($this->path);
    }

    /**
     * Renames the path to a new target.
     *
     * @param Path|string $target The new target path.
     */
    public function rename(Path|string $target): void
    {
        rename($this->path, (string) $target);
    }

    /**
     * Replaces the path with a new target.
     *
     * @param Path|string $target The new target path.
     */
    public function replace(Path|string $target): void
    {
        $this->rename($target);
    }

    /**
     * Creates an empty file or updates the modification time.
     *
     * @param int $mode The permissions mode (default: 0777).
     * @param int|null $time The modification time (default: current time).
     */
    public function touch(int $mode = 0o777, ?int $time = null): void
    {
        touch($this->path, $time ?? time());
        chmod($this->path, $mode);
    }

    /**
     * Find Children Sidecar Files
     *
     * Children files are files that share the same base name but have different extensions.
     * Common examples include subtitle files (.srt, .ass), metadata files (.nfo), and artwork.
     *
     * @param bool $nfoStyle If true, also searches for poster.* and fanart.* files in the parent directory.
     * @return array<Path> An array of Path objects for the found sidecar files.
     */
    public function childrenFiles(bool $nfoStyle = false): array
    {
        $list = [];
        $parent = $this->parent();

        // Handle NFO style artwork files
        if (true === $nfoStyle) {
            $possibleExt = ['jpg', 'jpeg', 'png'];
            foreach ($possibleExt as $ext) {
                $posterPath = $parent->joinPath("poster.{$ext}");
                if ($posterPath->exists()) {
                    $list[] = $posterPath;
                }

                $fanartPath = $parent->joinPath("fanart.{$ext}");
                if ($fanartPath->exists()) {
                    $list[] = $fanartPath;
                }
            }
        }

        $escapedBaseName = preg_replace('/([*?\[])/', '[$1]', $this->stem);
        foreach ($parent->glob($escapedBaseName . '.*') as $itemPath) {
            if (false === $itemPath->isFile() || true === $itemPath->sameFile($this)) {
                continue;
            }
            $list[] = $itemPath;
        }

        return $list;
    }

    public function __get(string $name)
    {
        return match ($name) {
            'parent' => $this->parent(),
            'absolute' => $this->absolute(),
            default => throw new RuntimeException("Unknown property: {$name}"),
        };
    }
}
