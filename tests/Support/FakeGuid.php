<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Backends\Common\Context;
use App\Backends\Common\GuidInterface;

final class FakeGuid implements GuidInterface
{
    private ?Context $context = null;

    public function withContext(Context $context): self
    {
        $instance = clone $this;
        $instance->context = $context;

        return $instance;
    }

    public function parse(array $guids, array $context = []): array
    {
        return $guids;
    }

    public function get(array $guids, array $context = []): array
    {
        return $guids;
    }

    public function has(array $guids, array $context = []): bool
    {
        return [] !== $guids;
    }

    public function isLocal(string $guid): bool
    {
        return str_starts_with($guid, 'local:');
    }
}
