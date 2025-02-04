<?php

declare(strict_types=1);

namespace App\Model\Base\Traits;

trait UsesPaging
{
    protected int $start = 0;
    protected int $perpage = 15;
    protected int $total = 0;
    protected string $order = 'DESC';
    protected string $sort = 'id';

    public function setStart(int $start = 0): self
    {
        $this->start = $start;

        return $this;
    }

    public function getStart(): int
    {
        return $this->start;
    }

    public function setPerpage(int $perpage = 15): self
    {
        $this->perpage = $perpage;

        return $this;
    }

    public function getPerpage(): int
    {
        return $this->perpage;
    }

    public function setTotal(int $total = 0): self
    {
        $this->total = $total;

        return $this;
    }

    public function getTotal(): int
    {
        return $this->total;
    }

    public function setAscendingOrder(): self
    {
        $this->order = 'ASC';

        return $this;
    }

    public function setDescendingOrder(): self
    {
        $this->order = 'DESC';

        return $this;
    }

    public function setSort($field): self
    {
        $this->sort = $field;

        return $this;
    }

    public function getSort(): string
    {
        return $this->sort;
    }

    public function getOrder(): string
    {
        return $this->order;
    }

}
