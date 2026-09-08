<?php

declare(strict_types=1);

namespace Fixtures\TierB;

/**
 * The other half of PHP's folding rule, and the half a "careful" implementation gets wrong.
 *
 * Class names fold by ASCII rules only, so the engine leaves every byte above 0x7F alone:
 * `Étage` and `étage` are two different classes and both may be declared here, exactly as
 * `Alpha` and `alpha` could not be. An index that folds names with `mb_strtolower` merges
 * these two into one row and then answers about whichever it stored last.
 */
final class Étage
{
    public function level(): int
    {
        return 1;
    }
}

final class étage
{
    public function level(): int
    {
        return 2;
    }
}
