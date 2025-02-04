<?php

declare(strict_types=1);

namespace App\Backends\Common;

interface GuidInterface
{
    /**
     * Set working context.
     *
     * @param Context $context Context to associate this object with.
     *
     * @return $this A new instance will be returned.
     */
    public function withContext(Context $context): self;

    /**
     * Parse external ids from given list in safe way.
     *
     * @note this method is **NOT allowed** to log and throw exceptions.
     *
     * @param array $guids List of external ids to parse.
     * @param array $context Context to associate this call with.
     *
     * @return array List of parsed external ids.
     */
    public function parse(array $guids, array $context = []): array;

    /**
     * Parse supported external ids from given list.
     *
     * @note this method is allowed to log and throw exceptions.
     *
     * @param array $guids List of external ids to parse.
     * @param array $context Context to associate this call with.
     *
     * @return array List of parsed and supported external ids.
     */
    public function get(array $guids, array $context = []): array;

    /**
     * Does the given list contain supported external ids?
     *
     * @param array $guids List of external ids to check.
     * @param array $context Context to associate this call with.
     *
     * @return bool True if the list contain supported external ids.
     */
    public function has(array $guids, array $context = []): bool;

    /**
     * Is the given identifier a local id?
     *
     * @param string $guid External id to check.
     *
     * @return bool True if the given id is a local id.
     */
    public function isLocal(string $guid): bool;
}
