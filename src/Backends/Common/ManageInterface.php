<?php

declare(strict_types=1);

namespace App\Backends\Common;

interface ManageInterface
{
    /**
     * Add or edit backend.
     *
     * @param array $backend Backend data.
     * @param array $opts options.
     *
     * @return array return modified $backend data.
     */
    public function manage(array $backend, array $opts = []): array;
}
