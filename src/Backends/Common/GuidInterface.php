<?php

declare(strict_types=1);

namespace App\Backends\Common;

interface GuidInterface
{
    /**
     * Set working context.
     *
     * @param Context $context
     *
     * @return $this Mutated version of the implementation shall be returned.
     */
    public function withContext(Context $context): self;

    /**
     * Parse external ids from given list in safe way.
     *
     * *DO NOT THROW OR LOG ANYTHING.*
     *
     * @param array $guids
     *
     * @return array
     */
    public function parse(array $guids): array;

    /**
     * Parse supported external ids from given list.
     *
     * @param array $guids
     * @param array $context
     * @return array
     */
    public function get(array $guids, array $context = []): array;

    /**
     * Does the given list contain supported external ids?
     *
     * @param array $guids
     * @param array $context
     * @return bool
     */
    public function has(array $guids, array $context = []): bool;

    /**
     * Is the given identifier a local id?
     *
     * @param string $guid
     *
     * @return bool
     */
    public function isLocal(string $guid): bool;
}
