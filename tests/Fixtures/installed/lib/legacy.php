<?php

declare(strict_types=1);

namespace Installed\Legacy;

/**
 * Reachable only through the dumped classmap: it is outside every PSR-4 root and its file is
 * not named after it. This is the case the classmap exists to cover.
 */
final class Mapped
{
    public function reached(): bool
    {
        return true;
    }
}
