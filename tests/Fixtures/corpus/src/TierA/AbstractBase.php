<?php

declare(strict_types=1);

namespace Fixtures\TierA;

abstract class AbstractBase
{
    abstract public function greet(string $name, int $times = 1): string;

    public function describe(): string
    {
        return static::class;
    }
}
