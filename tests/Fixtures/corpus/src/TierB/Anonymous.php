<?php

declare(strict_types=1);

namespace Fixtures\TierB;

use Fixtures\TierA\AbstractBase;

/**
 * Methods that exist but cannot be addressed. A textual search finds three `function`
 * declarations in this file; only one of them belongs to something with a name.
 */
final class Anonymous
{
    public function make(): object
    {
        return new class extends AbstractBase
        {
            public function greet(string $name, int $times = 1): string
            {
                return $name;
            }

            public function extra(): void {}
        };
    }

    /*
    public function commentedOut(): void
    {
    }
    */
}
