<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Graph;

/**
 * Why a step could not be taken, and whether that is a hole in the answer or just the edge of
 * the world.
 *
 * The distinction is the whole value of the trailing block. A walk that stops at
 * `str_ends_with` has not lost anything a reader wants — the destination is named, it is a
 * built-in, and there is nothing to find inside it. A walk that stops because a receiver's
 * type is unknown has lost a subtree of unknown size. Reporting both as "could not follow"
 * makes the second one invisible in a list of the first, which is the same as not reporting it.
 */
final readonly class Miss
{
    public function __construct(
        public bool $isGap,
        public string $reason,
    ) {}

    public static function gap(string $reason): self
    {
        return new self(true, $reason);
    }

    public static function edgeOfTheWorld(string $reason): self
    {
        return new self(false, $reason);
    }
}
