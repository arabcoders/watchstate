<?php

declare(strict_types=1);

namespace App\Backends\Common;

trait CommonTrait
{
    /**
     * Get Backend Name.
     *
     * @return string
     */
    public function getName(): string
    {
        return $this->name ?? 'CommonName';
    }

    /**
     * Get Client Name.
     *
     * @return string
     */
    public function getClientName(): string
    {
        return $this->clientName ?? 'CommonClient';
    }
}
