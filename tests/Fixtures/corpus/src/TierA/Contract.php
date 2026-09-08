<?php

declare(strict_types=1);

namespace Fixtures\TierA;

interface Contract extends Describable
{
    public const string VERSION = '1.0';

    public function greet(string $name, int $times = 1): string;
}
