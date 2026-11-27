<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Output;

/**
 * A count and its noun, agreed: "1 route", "3 routes", "1 index", "2 indexes".
 *
 * Written out by hand at every header, the agreement went wrong often enough to notice:
 * "1 reads", "1 of 4 … are" (#113). A sweep rule flags a hand-written `=== 1 ? '' : 's'`.
 */
final class Counted
{
    public static function of(int $count, string $singular, ?string $plural = null): string
    {
        return $count.' '.self::noun($count, $singular, $plural);
    }

    public static function noun(int $count, string $singular, ?string $plural = null): string
    {
        return $count === 1 ? $singular : ($plural ?? $singular.'s');
    }
}
