<?php

declare(strict_types=1);

namespace Fixtures\TierB;

/**
 * Two declarations in one file, plus a short name that also exists in another namespace
 * (`Fixtures\TierB\Deep\Alpha`). A filename-based lookup finds neither of these, and a search
 * for "Alpha" finds two unrelated classes.
 */
final class Alpha
{
    public function which(): string
    {
        return self::class;
    }
}

final class Omega
{
    public function which(): string
    {
        return self::class;
    }
}
