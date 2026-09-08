<?php

declare(strict_types=1);

namespace Fixtures\TierB;

use Fixtures\TierA\Baseline;
use Fixtures\TierA\Support\Repository as Store;
use Fixtures\TierB\Deep\{Alpha, Beta as Second};

use function Fixtures\TierB\Helpers\normalise;

use const Fixtures\TierB\Helpers\MAX_DEPTH;

/**
 * Every reference here is written as something other than the name it refers to. This is the
 * tier that justifies the tool existing: grep for "Repository" misses `Store`, and grep for
 * "Store" finds a name that is not declared anywhere.
 */
final class Aliases
{
    public function __construct(
        private readonly Store $store,
        private readonly Baseline $baseline,
    ) {}

    public function first(Alpha $alpha, Second $beta): int
    {
        return normalise($alpha, $beta) + MAX_DEPTH;
    }
}
