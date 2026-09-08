<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Analysis;

/**
 * An anonymous class declaration, recorded but not described.
 *
 * `new class { … }` has no name, so nothing in this tool can address it: `show` and `members`
 * take a symbol address, and there is no address to give. Leaving it out of an outline
 * entirely would nonetheless be a silent omission — a reader would see a file's shape with
 * several methods missing and no indication that anything was withheld — so it is reported as
 * present, at its line, with the count of what it holds.
 */
final readonly class AnonymousClass
{
    public function __construct(
        public int $line,
        public int $column,
        public int $memberCount,
        public ?string $extends,
    ) {}
}
