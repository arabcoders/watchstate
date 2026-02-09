<?php

declare(strict_types=1);

namespace App\Model\Base;

use App\Libs\Container;
use App\Libs\Extends\Date;
use App\Model\Base\Enums\TransformType;
use InvalidArgumentException;
use JsonSerializable;
use ReflectionNamedType;
use ReflectionObject;
use ReflectionProperty;
use ReflectionUnionType;
use Stringable;

abstract class BasicModel implements jsonSerializable
{
    /**
     * @var array<string,mixed> Copy of the original data.
     */
    protected array $data = [];

    /**
     * @var array<string, class-string|callable(TransformType $type, mixed $data):mixed> Transformations for properties.
     */
    protected array $transform = [];

    /**
     * Custom compare for complex data types. If the callable returns true,
     * the value is considered unchanged. Otherwise, it's considered changed.
     *
     * @var array<string, callable(mixed $old, mixed $new):bool>
     */
    protected array $differ = [];

    /**
     * @var array<string,string> Casts for properties.
     */
    protected array $casts = [];

    /**
     * @var array<class-string,array<string,array>> Properties for the class.
     */
    private static array $_props = [];

    /**
     * @var array<class-string,array<string,string>> Columns for the class.
     */
    private static array $_columns = [];

    /**
     * @var bool Loaded from DB.
     */
    protected bool $fromDB = false;

    /**
     * @var bool Whether the queried data is Custom.
     */
    protected bool $isCustom = false;

    /**
     * @var string Refers to table Unique ID.
     */
    protected string $primaryKey = 'id';

    /**
     * Receive Data as key/value pairs.
     *
     * @param array $data
     * @param bool $isCustom If True Object **SHOULD NOT** pass checks.
     * @param array $options
     */
    public function __construct(array $data = [], bool $isCustom = false, array $options = [])
    {
        $this->init($data, $isCustom, $options);

        $this->isCustom = $isCustom;

        if (array_key_exists('fromDB', $options)) {
            $this->fromDB = (bool) $options['fromDB'];
        }

        if (array_key_exists('primaryKey', $options)) {
            $this->primaryKey = (string) $options['primaryKey'];
        }

        $data = $this->transform(TransformType::DECODE, $data);

        foreach ($data as $key => $value) {
            $value = $this->setValueType($key, $value);
            $this->{$key} = $value;
            $this->data[$key] = $value;
        }
    }

    protected function init(array &$data, bool &$isCustom, array &$options): void
    {
    }

    abstract public function validate(): bool;

    public function isFromDB(?bool $fromDB = null): bool
    {
        if (null !== $fromDB) {
            $this->fromDB = $fromDB;
        }

        return $this->fromDB;
    }

    public function apply(BasicModel $model): static
    {
        foreach ($model->getAll() as $key => $value) {
            if ($key === $this->primaryKey) {
                continue;
            }

            $this->{$key} = $this->setValueType($key, $value);
        }

        return $this;
    }

    public function hasPrimaryKey(): bool
    {
        return property_exists($this, 'primaryKey') && !empty($this->{$this->primaryKey});
    }

    public function getAll(bool $transform = false): array
    {
        $props = [];

        $reflect = new ReflectionObject($this)->getProperties(ReflectionProperty::IS_PUBLIC);

        foreach ($reflect as $src) {
            $value = $src->getValue($this);

            if ($value instanceof Stringable) {
                $value = (string) $value;
            }

            $props[$src->getName()] = $value;
        }

        return true === $transform ? $this->transform(TransformType::ENCODE, $props) : $props;
    }

    public function diff(bool $deep = false, bool $transform = false): array
    {
        $changed = [];

        foreach ($this->getAll() as $key => $value) {
            if (false === array_key_exists($key, $this->data)) {
                continue;
            }

            $old = $this->data[$key];

            // -- custom compare in case of complex data types.
            if (null !== ($fn = $this->differ[$key] ?? null) && true === (bool) $fn($old, $value)) {
                continue;
            } elseif ($value === $old) {
                continue;
            }

            $changed[$key] = false === $deep
                ? $value
                : [
                    'old' => $old ?? null,
                    'new' => $value,
                ];
        }

        if (true === $transform && !empty($changed)) {
            foreach ($changed as $key => $value) {
                if (false === $deep) {
                    $changed[$key] = $this->transform(TransformType::ENCODE, [$key => $value])[$key];
                } else {
                    $changed[$key] = [
                        'old' => $this->transform(TransformType::ENCODE, [$key => $value['old']])[$key],
                        'new' => $this->transform(TransformType::ENCODE, [$key => $value['new']])[$key],
                    ];
                }
            }
        }

        return $changed;
    }

    /**
     * Get Schema type for data Validation.
     *
     * This relies on Entity being strongly typed.
     *
     * @return array<string,array<string>>
     */
    public function getSchemaDataType(): array
    {
        $className = get_class($this);

        if (isset(self::$_props[$className])) {
            return self::$_props[$className];
        }

        self::$_props[$className] = [];

        $reflect = new ReflectionObject($this)->getProperties(ReflectionProperty::IS_PUBLIC);

        foreach ($reflect as $src) {
            $prop = $src->getType();
            $propName = $src->getName();

            if (null === $prop) {
                self::$_props[$className][$propName] = ['mixed'];
                continue;
            }

            if ($prop instanceof ReflectionNamedType) {
                self::$_props[$className][$propName][] = $prop->getName();

                if ($prop->allowsNull()) {
                    self::$_props[$className][$propName][] = 'null';
                }

                continue;
            }

            if ($prop instanceof ReflectionUnionType) {
                foreach ($prop->getTypes() as $typed) {
                    self::$_props[$className][$propName][] = $typed->getName();
                }
            }
        }

        return self::$_props[$className];
    }

    public function setValueType(string $key, mixed $value): mixed
    {
        if (!isset($this->casts[$key])) {
            return $value;
        }

        if ('int' === $this->casts[$key] && $value instanceof Date) {
            $value = $value->getTimestamp();
        }

        if (get_debug_type($value) === $this->casts[$key]) {
            return $value;
        }

        settype($value, $this->casts[$key]);

        return $value;
    }

    public function getColumnsNames(): array
    {
        $className = get_class($this);

        if (isset(self::$_columns[$className])) {
            return self::$_columns[$className];
        }

        self::$_columns[$className] = [];

        foreach (new ReflectionObject($this)->getConstants() as $key => $val) {
            if (!str_starts_with($key, 'COLUMN_')) {
                continue;
            }

            self::$_columns[$className][$key] = $val;
        }

        return self::$_columns[$className];
    }

    public function getPrimaryData(): array
    {
        return $this->data;
    }

    public function updatePrimaryData(): self
    {
        $this->data = $this->getAll();
        return $this;
    }

    public function getPrimaryId(): mixed
    {
        return $this->data[$this->primaryKey] ?? $this->{$this->primaryKey} ?? null;
    }

    public function getPrimaryKey(): string
    {
        return $this->primaryKey;
    }

    public function isCustom(): bool
    {
        return $this->isCustom;
    }

    public function __debugInfo(): array
    {
        return $this->getAll();
    }

    public function __destruct()
    {
        self::$_props = self::$_columns = [];
    }

    public function jsonSerialize(): array
    {
        return $this->getAll();
    }

    protected function transform(TransformType $type, array $data): array
    {
        if (empty($this->transform)) {
            return $data;
        }

        foreach ($this->transform as $key => $callable) {
            if (false === array_key_exists($key, $data)) {
                continue;
            }

            if (false === is_callable($callable)) {
                if (true === is_string($callable) && true === class_exists($callable)) {
                    $callable = Container::get($callable);
                } else {
                    throw new InvalidArgumentException(sprintf("Transformer for '%s', is not callable.", $key));
                }
            }

            $data[$key] = $callable($type, $data[$key]);
        }

        return $data;
    }
}
