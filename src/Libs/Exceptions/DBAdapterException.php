<?php

declare(strict_types=1);

namespace App\Libs\Exceptions;

use RuntimeException;

/**
 * Class DatabaseException
 *
 * The DatabaseException class extends the RuntimeException class and represents an exception
 * that is thrown when there is an error related to the database operation.
 */
class DBAdapterException extends RuntimeException implements AppExceptionInterface
{
    use UseAppException;

    public string $queryString = '';
    public array $bind = [];

    public array $options = [];
    public array $errorInfo = [];

    /**
     * @param string $queryString
     * @param array $bind
     * @param array $errorInfo
     * @param string|int $errorCode
     *
     * @return $this
     */
    public function setInfo(
        string $queryString,
        array $bind = [],
        array $errorInfo = [],
        mixed $errorCode = 0
    ): self {
        $this->queryString = $queryString;
        $this->bind = $bind;
        $this->errorInfo = $errorInfo;
        $this->code = $errorCode;

        return $this;
    }

    public function getQueryString(): string
    {
        return $this->queryString;
    }

    public function getQueryBind(): array
    {
        return $this->bind;
    }

    public function setFile(string $file): DBAdapterException
    {
        $this->file = $file;

        return $this;
    }

    public function setLine(int $line): DBAdapterException
    {
        $this->line = $line;

        return $this;
    }

    public function setOptions(array $options): DBAdapterException
    {
        $this->options = $options;

        return $this;
    }
}
