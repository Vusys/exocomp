<?php

declare(strict_types=1);

namespace Fixtures\TierB;

use Fixtures\TierA\Contract;

/**
 * Three ways of writing the same reference, and one that only looks the same.
 *
 * `Deep\Alpha` is relative to the current namespace; `\Fixtures\TierB\Deep\Beta` is absolute;
 * `Contract` is an imported short name. Only name resolution tells them apart — textually they
 * share nothing.
 */
final class Relative extends Deep\Alpha implements Contract
{
    public function greet(string $name, int $times = 1): string
    {
        return $name;
    }

    public function describe(): string
    {
        return \Fixtures\TierB\Deep\Beta::class;
    }
}
