<?php

declare(strict_types=1);

namespace App\Model\Base;

use App\Libs\Extends\Date;
use App\Model\Base\Exceptions\ValidationException;
use App\Model\Base\Exceptions\VValidateException;
use InvalidArgumentException;
use RuntimeException;
use Stringable;

abstract class BasicValidation
{
    /**
     * @var bool Whether the checks are successful.
     */
    protected bool $isValid = false;

    /**
     * @var array<string,array<string>> Declared property datatype. Example [ 'id' => [ 'int', 'null' ] ]
     */
    protected array $schemeDataType = [];

    /**
     * @var array<string,array<callable(mixed $value):bool>> Run custom validator on property. MUST return bool(true) to pass.
     */
    protected array $schemeValidate = [];

    /**
     * @var array<string,array<callable(mixed $value):mixed>> Run custom filter on property.
     */
    protected array $schemeFilter = [];

    protected function runValidator(BasicModel $model): void
    {
        $this->schemeDataType = array_replace_recursive($model->getSchemaDataType(), $this->schemeDataType);

        foreach ($model->getAll() as $fieldName => $fieldValue) {
            if (!empty($this->schemeDataType)) {
                if (!array_key_exists($fieldName, $this->schemeDataType)) {
                    throw new ValidationException(
                        sprintf("'%s' is not part of '%s' data properties.", $fieldName, get_class($model))
                    );
                }

                $this->checkDataTypes($fieldName, $fieldValue);
            }

            if (isset($this->schemeValidate[$fieldName])) {
                foreach ($this->schemeValidate[$fieldName] as $_fn) {
                    if (true !== is_callable($_fn)) {
                        throw new RuntimeException(
                            sprintf("Validation Filter for '%s' is not a callable.", $fieldName)
                        );
                    }

                    if (true !== $_fn($fieldValue)) {
                        throw new VValidateException(
                            sprintf("Validation Filter for '%s' returned non-true.", $fieldName)
                        );
                    }
                }
            }

            if (isset($this->schemeFilter[$fieldName])) {
                foreach ($this->schemeFilter[$fieldName] as $_fn) {
                    if (true !== is_callable($_fn)) {
                        throw new RuntimeException(sprintf("Data Filter for '%s' is not callable.", $fieldName));
                    }
                    $model->{$fieldName} = $_fn($fieldValue);
                }
            }
        }

        $this->isValid = true;
    }

    protected function checkDataTypes(string $name, mixed $value): bool
    {
        if (!is_array($this->schemeDataType[$name])) {
            throw new InvalidArgumentException(
                sprintf(
                    "Invalid data type returned from schemeDataType. expecting array. got '%s' instead.",
                    gettype($value)
                )
            );
        }

        $passCheck = false;

        foreach ($this->schemeDataType[$name] as $_type) {
            if ($this->checkType($value, $_type)) {
                $passCheck = true;
            }
        }

        if (!$passCheck) {
            throw new InvalidArgumentException(
                sprintf(
                    "'%s' expects '%s' data type, but '%s' was given.",
                    $name,
                    implode(', ', $this->schemeDataType[$name]),
                    get_debug_type($value)
                )
            );
        }

        return true;
    }

    /**
     * Whether Validation Checks out.
     *
     * @return bool
     */
    public function isValid(): bool
    {
        return $this->isValid;
    }

    protected function checkType(mixed $value, string $type): bool
    {
        if ($type === gettype($value) || $type === get_debug_type($value)) {
            return true;
        }

        return match ($type) {
            'int', 'integer' => is_int($value),
            'string' => is_string($value),
            'bool', 'boolean' => is_bool($value),
            'double' => is_double($value),
            'float' => is_float($value),
            'array' => is_array($value),
            'null', 'NULL' => null === $value,
            'object' => is_object($value),
            'resource', 'resource (closed)' => is_resource($value),
            Stringable::class => $value instanceof Stringable,
            Date::class => $value instanceof Date,
            'mixed' => true,
            default => false
        };
    }
}
