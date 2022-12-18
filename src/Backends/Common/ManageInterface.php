<?php

declare(strict_types=1);

namespace App\Backends\Common;

interface ManageInterface
{
    /**
     * Add/Edit Backend.
     *
     * @param array $backend
     * @param array $opts
     *
     * @return array
     */
    public function manage(array $backend, array $opts = []): array;
}
