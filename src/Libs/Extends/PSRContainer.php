<?php

declare(strict_types=1);

namespace App\Libs\Extends;

use League\Container\Container;

final class PSRContainer extends Container
{
    /**
     * Get Instance of a class.
     *
     * @template T
     * @param class-string<T> $id
     * @return T
     */
    public function get($id)
    {
        return parent::get($id);
    }

    /**
     * Get new instance of a class.
     *
     * @template T
     * @param class-string<T> $id
     * @return T
     */
    public function getNew($id)
    {
        return parent::getNew($id);
    }

}
