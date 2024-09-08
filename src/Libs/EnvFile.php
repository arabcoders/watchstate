<?php

declare(strict_types=1);

namespace App\Libs;

use App\Libs\Exceptions\RuntimeException;

/**
 * Class EnvFile
 *
 * This class Manages the .env file configuration.
 */
final class EnvFile
{
    private array $data = [];

    public function __construct(public readonly string $file, bool $create = false)
    {
        if (!file_exists($this->file)) {
            if ($create) {
                touch($this->file);
            } else {
                throw new RuntimeException(r("The file '{file}' does not exist.", ['file' => $this->file]));
            }
        }

        $this->data = parseEnvFile($this->file);
    }

    /**
     * Return a new instance of the class with the same file.
     * This method will not flush the current data into new instance.
     * You must call persist() method to save the data. before calling this method.
     *
     * @param bool $create
     * @return self
     */
    public function newInstance(bool $create = false): self
    {
        return new self($this->file, create: $create);
    }

    /**
     * Get the value of a configuration setting.
     *
     * @param string $key The configuration setting key.
     * @param mixed $default The default value to return if the key does not exist.
     *
     * @return mixed The configuration setting value.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return ag($this->data, $key, $default);
    }

    /**
     * Set a configuration setting.
     *
     * @param string $key The configuration setting key.
     * @param int|float|string|bool $value The configuration setting value.
     *
     * @return self
     */
    public function set(string $key, int|float|string|bool $value): self
    {
        is_scalar($value) || throw new RuntimeException(r('The value must be a scalar type.'));
        $this->data = ag_set($this->data, $key, $value);

        return $this;
    }

    /**
     * Check if a configuration setting exists.
     *
     * @param string $key The configuration setting key.
     *
     * @return bool True if the configuration setting exists, false otherwise.
     */
    public function has(string $key): bool
    {
        return ag_exists($this->data, $key);
    }

    /**
     * Remove a configuration setting.
     *
     * @param string $key The configuration setting key.
     *
     * @return self
     */
    public function remove(string $key): self
    {
        $this->data = ag_delete($this->data, $key);
        return $this;
    }

    /**
     * Save the configuration settings to the file.
     */
    public function persist(): void
    {
        $stream = Stream::make($this->file, 'w');

        $lines = [];

        foreach ($this->data as $key => $value) {
            if ('bool' === get_debug_type($value)) {
                $value = $value ? '1' : '0';
            }

            $lines[] = r('{key}={value}', ['key' => $key, 'value' => $value]);
        }

        $stream->write(implode(PHP_EOL, $lines) . PHP_EOL);
    }

    /**
     * Get all configuration settings.
     *
     * @return array The configuration settings.
     */
    public function getAll(): array
    {
        return $this->data;
    }
}
