<?php

declare(strict_types=1);

namespace Installed;

final class Sharing
{
    public function first(): bool
    {
        return true;
    }
}

/**
 * Shares a file with a differently-named sibling, so neither PSR-4 arithmetic nor a filename
 * match can place it. Only reading the bytes finds this one — which is what `--scan` is for.
 */
final class Hidden
{
    public function second(): bool
    {
        return true;
    }
}
